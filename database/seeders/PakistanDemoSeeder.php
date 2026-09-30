<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\User;
use App\Services\KitchenTicketService;
use App\Services\NotificationService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Support\TenantContext;
use Database\Seeders\Demo\OrderHistory;
use Database\Seeders\Demo\RestaurantBuilder;
use Database\Seeders\Demo\SilentNotifications;
use Database\Seeders\Demo\SpiceRoute;
use Database\Seeders\Demo\StackAndBun;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Realistic demo data for Pakistan: a platform Super Admin plus two
 * restaurants — "Spice Route" (desi, Karachi) and "Stack & Bun" (smash
 * burgers, Lahore) — each with two branches, staff for every role, a full
 * menu with real food photos, tables, delivery zones, coupons, customers
 * and ~30 days of order history played through the real services.
 *
 *   php artisan db:seed --class=PakistanDemoSeeder
 *
 * Environment (all optional):
 *   DEMO_PASSWORD           password for every demo login (otherwise a random one
 *                           is generated for new accounts and printed once)
 *   DEMO_SUPERADMIN_EMAIL   default superadmin@platform.demo
 *   DEMO_HISTORY_DAYS       days of order history, default 30 (0 = none)
 *
 * Safe to run again: everything is updated in place, and order history is
 * only generated for a restaurant that has no orders yet.
 *
 * Photos live in public/demo/images and are referenced by absolute URL
 * built from APP_URL — set APP_URL to the server's public address before
 * seeding.
 */
class PakistanDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = app(TenantContext::class);
        $tenant->bypass(true);

        // Nobody should be emailed/pushed about thousands of historical orders.
        // The services are resolved fresh below, so they pick up this binding.
        app()->instance(NotificationService::class, new SilentNotifications());

        $forcedPassword = env('DEMO_PASSWORD') ?: null;
        $generatedPassword = Str::password(14, symbols: false);
        $appUrl = rtrim((string) config('app.url'), '/');

        if (str_contains($appUrl, 'localhost') && app()->environment('production')) {
            $this->command?->warn("APP_URL is {$appUrl} — product photo URLs will point there. Set APP_URL to the public address and re-run to fix them.");
        }

        try {
            $superAdminEmail = env('DEMO_SUPERADMIN_EMAIL', 'superadmin@platform.demo');
            $superAdmin = User::where('email', $superAdminEmail)->first();
            $createdLogins = [];
            if (! $superAdmin) {
                User::create([
                    'email' => $superAdminEmail,
                    'name' => 'Platform Admin',
                    'password' => $forcedPassword ?? $generatedPassword,
                    'is_super_admin' => true,
                    'status' => 'ACTIVE',
                ]);
                $createdLogins[] = $superAdminEmail;
            } elseif ($forcedPassword !== null) {
                $superAdmin->update(['password' => $forcedPassword, 'is_super_admin' => true, 'status' => 'ACTIVE']);
            }

            $builder = new RestaurantBuilder(
                forcedPassword: $forcedPassword,
                newUserPassword: $generatedPassword,
                assetBaseUrl: $appUrl,
                imageDir: public_path('demo/images'),
                seed: 20260930,
            );

            $days = (int) env('DEMO_HISTORY_DAYS', 30);
            $rows = [['Super Admin', '—', 'Platform Admin', $superAdminEmail]];

            foreach ([SpiceRoute::data(), StackAndBun::data()] as $i => $data) {
                $this->command?->info("Building {$data['restaurant']['name']}…");
                $demo = $builder->build($data);

                foreach ($demo->logins as $login) {
                    $rows[] = [$login['role'], $login['branch'] ?? $demo->restaurant->name, $login['name'], $login['email']];
                }

                $hasOrders = Order::where('restaurant_id', $demo->restaurant->id)->exists();
                if ($days > 0 && ! $hasOrders) {
                    $this->command?->info("  Playing {$days} days of orders (this takes a minute)…");
                    $history = new OrderHistory(
                        app(OrderService::class),
                        app(KitchenTicketService::class),
                        app(PaymentService::class),
                        $days,
                        seed: 1000 + $i,
                    );
                    $history->run($demo);

                    $s = $history->stats;
                    $this->command?->info(sprintf(
                        '  %d orders: %d completed, %d cancelled, %d in progress, %d add-on rounds, %d returns — Rs %s revenue.',
                        $s['orders'], $s['completed'], $s['cancelled'], $s['live'], $s['add_ons'], $s['returns'], number_format($s['revenue'])
                    ));
                    if ($s['failed'] > 0) {
                        $this->command?->warn("  {$s['failed']} orders could not be placed:");
                        foreach ($history->errors as $error) {
                            $this->command?->warn("    {$error}");
                        }
                    }
                } elseif ($hasOrders) {
                    $this->command?->line('  Already has orders — order history left as it is.');
                }
            }

            $createdLogins = array_merge($createdLogins, $builder->createdLogins);
            $this->command?->newLine();
            $this->command?->table(['Role', 'Branch / restaurant', 'Name', 'Email'], $rows);

            if ($forcedPassword !== null) {
                $this->command?->info('Every demo login uses the DEMO_PASSWORD you set.');
            } elseif ($createdLogins) {
                $this->command?->warn("Password for the new demo logins: {$generatedPassword}");
                $this->command?->warn('Save it now — it is not stored anywhere else. (Set DEMO_PASSWORD and re-run to change it.)');
                if (count($createdLogins) < count($rows)) {
                    $this->command?->line('Logins that already existed kept their old password.');
                }
            } else {
                $this->command?->line('All demo logins already existed and kept their passwords. Set DEMO_PASSWORD and re-run to reset them.');
            }
        } finally {
            $tenant->bypass(false);
        }
    }
}
