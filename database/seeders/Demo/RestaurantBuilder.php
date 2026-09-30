<?php

namespace Database\Seeders\Demo;

use App\Models\Branch;
use App\Models\BranchHour;
use App\Models\BranchProduct;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\DeliveryZone;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\SubscriptionFeature;
use App\Models\SubscriptionPlan;
use App\Models\Table;
use App\Models\User;
use App\Models\UserBranch;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Turns one restaurant's data array (SpiceRoute::data(), StackAndBun::data())
 * into rows. Every write is an updateOrCreate() keyed on a natural unique
 * key, so running it again updates the demo in place instead of
 * duplicating it.
 *
 * Returns a DemoRestaurant — the handles OrderHistory needs.
 */
class RestaurantBuilder
{
    private Randomizer $random;

    /**
     * @param  string|null  $forcedPassword  set on every demo login (DEMO_PASSWORD), or
     * @param  string  $newUserPassword  used only for accounts created by this run
     */
    public function __construct(
        private ?string $forcedPassword,
        private string $newUserPassword,
        private string $assetBaseUrl,
        private string $imageDir,
        int $seed,
    ) {
        $this->random = new Randomizer(new Mt19937($seed));
    }

    /** Emails of accounts created by this run (they got $newUserPassword). */
    public array $createdLogins = [];

    public function build(array $data): DemoRestaurant
    {
        $demo = new DemoRestaurant($data);
        $demo->restaurant = $this->restaurant($data);
        $this->subscription($demo->restaurant);
        $this->branches($demo, $data);
        $this->staff($demo, $data);
        $this->menu($demo, $data);
        $this->coupons($demo, $data);
        $this->customers($demo, $data);

        return $demo;
    }

    private function restaurant(array $data): Restaurant
    {
        $attributes = $data['restaurant'];
        $attributes['logo'] = $this->imageUrl($data['logo']);

        $restaurant = Restaurant::updateOrCreate(['slug' => $attributes['slug']], $attributes);
        $restaurant->settings()->updateOrCreate(['restaurant_id' => $restaurant->id], $data['settings']);

        return $restaurant->fresh('settings');
    }

    private function subscription(Restaurant $restaurant): void
    {
        $plan = SubscriptionPlan::where('slug', 'premium')->firstOrFail();

        $subscription = Subscription::updateOrCreate(
            ['restaurant_id' => $restaurant->id, 'subscription_plan_id' => $plan->id],
            ['start_date' => now()->subMonths(7)->startOfMonth(), 'expiry_date' => now()->addMonths(5)->endOfMonth(), 'status' => 'ACTIVE']
        );

        foreach ($plan->features as $feature) {
            SubscriptionFeature::updateOrCreate(
                ['subscription_id' => $subscription->id, 'feature_id' => $feature->id],
                ['enabled' => true]
            );
        }
    }

    private function branches(DemoRestaurant $demo, array $data): void
    {
        $restaurant = $demo->restaurant;

        foreach ($data['branches'] as $code => $b) {
            $branch = Branch::updateOrCreate(
                ['restaurant_id' => $restaurant->id, 'branch_code' => $code],
                [
                    'name' => $b['name'],
                    'phone' => $b['phone'],
                    'email' => $b['email'],
                    'address' => $b['address'],
                    'city' => $b['city'],
                    'state' => $b['state'],
                    'country' => $b['country'],
                    'postal_code' => $b['postal_code'],
                    'latitude' => $b['latitude'],
                    'longitude' => $b['longitude'],
                    'status' => 'ACTIVE',
                    'priority' => $b['priority'],
                ]
            );

            $branch->settings()->updateOrCreate(
                ['branch_id' => $branch->id],
                ['restaurant_id' => $restaurant->id] + $b['settings']
            );

            // 0 = Sunday .. 6 = Saturday. Friday and Saturday nights run late.
            foreach (range(0, 6) as $day) {
                BranchHour::updateOrCreate(
                    ['branch_id' => $branch->id, 'day_of_week' => $day],
                    [
                        'restaurant_id' => $restaurant->id,
                        'open_time' => $b['hours']['open'],
                        'close_time' => in_array($day, [5, 6], true) ? $b['hours']['weekend_close'] : $b['hours']['close'],
                        'is_closed' => false,
                        'is_24_hours' => false,
                    ]
                );
            }

            // Inner zone first: GeofencingService picks the first (lowest id) zone that contains the address.
            foreach ($b['zones'] as $zone) {
                DeliveryZone::updateOrCreate(
                    ['branch_id' => $branch->id, 'name' => $zone['name']],
                    [
                        'restaurant_id' => $restaurant->id,
                        'type' => 'RADIUS',
                        'center_latitude' => $b['latitude'],
                        'center_longitude' => $b['longitude'],
                        'radius_km' => $zone['radius_km'],
                        'polygon' => null,
                        'delivery_fee_override' => $zone['fee'],
                        'is_active' => true,
                    ]
                );
            }

            foreach ($b['tables'] as $group) {
                for ($n = 1; $n <= $group['count']; $n++) {
                    $capacities = $group['capacity'];
                    Table::updateOrCreate(
                        ['branch_id' => $branch->id, 'table_number' => $group['prefix'].$n],
                        [
                            'restaurant_id' => $restaurant->id,
                            'capacity' => $capacities[($n - 1) % count($capacities)],
                            'section' => $group['section'],
                        ]
                    );
                }
            }

            $demo->branches[$code] = $branch->fresh('settings');
        }
    }

    private function staff(DemoRestaurant $demo, array $data): void
    {
        $roles = Role::whereNull('restaurant_id')->get()->keyBy('slug');

        foreach ($data['restaurant_staff'] as $roleSlug => $people) {
            foreach ($people as [$name, $phone]) {
                $this->staffMember($demo, $data, $roles[$roleSlug], $name, $phone, null);
            }
        }

        foreach ($data['branches'] as $code => $b) {
            foreach ($b['staff'] as $roleSlug => $people) {
                foreach ($people as [$name, $phone]) {
                    $user = $this->staffMember($demo, $data, $roles[$roleSlug], $name, $phone, $demo->branches[$code]);
                    $demo->staff[$code][$roleSlug][] = $user;
                }
            }
        }
    }

    private function staffMember(DemoRestaurant $demo, array $data, Role $role, string $name, string $phone, ?Branch $branch): User
    {
        $email = Str::of($name)->lower()->ascii()->replace(' ', '.')->append('@', $data['email_domain'])->toString();

        $user = User::where('email', $email)->first();
        $attributes = [
            'restaurant_id' => $demo->restaurant->id,
            'name' => $name,
            'phone' => $phone,
            'is_super_admin' => false,
            'status' => 'ACTIVE',
        ];

        if (! $user) {
            $user = User::create($attributes + ['email' => $email, 'password' => $this->forcedPassword ?? $this->newUserPassword]);
            $this->createdLogins[] = $email;
        } else {
            $user->update($attributes + ($this->forcedPassword !== null ? ['password' => $this->forcedPassword] : []));
        }

        $user->roles()->sync([$role->id]);

        if ($branch) {
            UserBranch::updateOrCreate(
                ['user_id' => $user->id, 'branch_id' => $branch->id],
                ['restaurant_id' => $demo->restaurant->id]
            );
        }

        $demo->logins[] = ['role' => $role->name, 'branch' => $branch?->name, 'name' => $name, 'email' => $email];

        return $user->fresh('roles');
    }

    private function menu(DemoRestaurant $demo, array $data): void
    {
        $restaurant = $demo->restaurant;
        $folder = $data['image_folder'];

        $groups = [];
        $order = 10;
        foreach ($data['modifier_groups'] as $key => $g) {
            $group = ModifierGroup::updateOrCreate(
                ['restaurant_id' => $restaurant->id, 'name' => $g['name']],
                [
                    'selection_type' => $g['type'],
                    'is_required' => $g['required'],
                    'min_selections' => $g['min'],
                    'max_selections' => $g['max'],
                    'display_order' => $order,
                ]
            );
            $order += 10;

            foreach ($g['modifiers'] as $i => [$name, $price, $isDefault]) {
                Modifier::updateOrCreate(
                    ['modifier_group_id' => $group->id, 'name' => $name],
                    [
                        'restaurant_id' => $restaurant->id,
                        'price_adjustment' => $price,
                        'is_default' => $isDefault,
                        'display_order' => ($i + 1) * 10,
                        'status' => 'ACTIVE',
                    ]
                );
            }

            $groups[$key] = $group;
        }

        foreach ($data['categories'] as $c => $cat) {
            $category = Category::updateOrCreate(
                ['restaurant_id' => $restaurant->id, 'slug' => Str::slug($cat['name'])],
                [
                    'name' => $cat['name'],
                    'description' => $cat['description'],
                    'image' => $this->imageUrl("{$folder}/{$cat['image']}.jpg"),
                    'display_order' => ($c + 1) * 10,
                    'status' => 'ACTIVE',
                ]
            );

            foreach ($cat['products'] as $p => [$slug, $name, $price, $description, $prep, $groupKeys, $tag, $weight]) {
                $product = Product::updateOrCreate(
                    ['restaurant_id' => $restaurant->id, 'slug' => $slug],
                    [
                        'category_id' => $category->id,
                        'name' => $name,
                        'description' => $description,
                        'image' => $this->imageUrl("{$folder}/{$slug}.jpg"),
                        'base_price' => $price,
                        'preparation_time_minutes' => $prep,
                        'display_order' => ($p + 1) * 10,
                        'status' => 'ACTIVE',
                    ]
                );

                $product->modifierGroups()->sync(collect($groupKeys)->map(fn ($k) => $groups[$k]->id)->all());

                $demo->products[$slug] = $product;
                $demo->tagged[$tag][] = ['slug' => $slug, 'weight' => $weight];
            }
        }

        foreach ($data['branch_products'] as [$code, $slug, $available, $priceOverride]) {
            BranchProduct::updateOrCreate(
                ['branch_id' => $demo->branches[$code]->id, 'product_id' => $demo->products[$slug]->id],
                ['restaurant_id' => $restaurant->id, 'is_available' => $available, 'price_override' => $priceOverride]
            );
        }
    }

    private function coupons(DemoRestaurant $demo, array $data): void
    {
        foreach ($data['coupons'] as $c) {
            $demo->coupons[] = Coupon::updateOrCreate(
                ['restaurant_id' => $demo->restaurant->id, 'code' => $c['code']],
                [
                    'type' => $c['type'],
                    'value' => $c['value'],
                    'min_order_amount' => $c['min'],
                    'max_discount_amount' => $c['max_discount'],
                    'usage_limit' => $c['limit'],
                    'per_customer_limit' => $c['per_customer'],
                    'valid_from' => now()->addDays($c['from'])->startOfDay(),
                    'valid_until' => now()->addDays($c['until'])->endOfDay(),
                    'is_active' => $c['active'],
                ]
            );
        }
    }

    private function customers(DemoRestaurant $demo, array $data): void
    {
        $codes = array_keys($data['branches']);
        $restaurant = $demo->restaurant;

        foreach ($data['customers'] as $i => $name) {
            $code = $codes[$i % count($codes)];
            $b = $data['branches'][$code];
            $phone = '+923'.$this->random->getInt(0, 4).$this->random->getInt(0, 9).sprintf('%07d', $this->random->getInt(1000000, 9999999));

            // Keyed on name, not phone, so a re-run finds the same person.
            $customer = Customer::where('restaurant_id', $restaurant->id)->where('name', $name)->first()
                ?? Customer::create([
                    'restaurant_id' => $restaurant->id,
                    'name' => $name,
                    'phone' => $phone,
                    'email' => null,
                    'password' => null,
                    'status' => 'ACTIVE',
                ]);

            if (! $customer->addresses()->exists()) {
                $this->addAddress($customer, $b, 'Home', true);
                if ($this->random->getInt(1, 100) <= 35) {
                    $this->addAddress($customer, $b, 'Office', false);
                }
            }

            $demo->customers[$code][] = $customer->fresh('addresses');
        }
    }

    private function addAddress(Customer $customer, array $branch, string $label, bool $default): void
    {
        $area = $branch['areas'][$this->random->getInt(0, count($branch['areas']) - 1)];

        if ($label === 'Office' || $this->random->getInt(1, 100) <= 30) {
            $buildings = ['Al-Murtaza Heights', 'Crescent Towers', 'Emerald Residency', 'Park View Apartments', 'Silver Oaks', 'Horizon Plaza', 'Gulmohar Court', 'Rose Arcade'];
            $building = $buildings[$this->random->getInt(0, count($buildings) - 1)];
            $floor = (string) $this->random->getInt(1, 12);
            $line = ($label === 'Office' ? 'Office ' : 'Flat ').$floor.sprintf('%02d', $this->random->getInt(1, 12)).", {$building}, {$area}, {$branch['city']}";
        } else {
            $building = null;
            $floor = null;
            $line = 'House '.$this->random->getInt(3, 180).'-'.['A', 'B', 'C', 'D', 'E'][$this->random->getInt(0, 4)]
                .', Street '.$this->random->getInt(1, 34).", {$area}, {$branch['city']}";
        }

        // A point 0.4–3.5 km from the branch — inside its standard delivery zone.
        $distanceKm = $this->random->getFloat(0.4, 3.5);
        $bearing = $this->random->getFloat(0, 2 * M_PI);
        $lat = $branch['latitude'] + ($distanceKm / 111.0) * cos($bearing);
        $lng = $branch['longitude'] + ($distanceKm / (111.0 * cos(deg2rad($branch['latitude'])))) * sin($bearing);

        $notes = [null, null, 'Call on arrival, the bell is not working.', 'Leave with the guard at the gate.', 'Please bring change for Rs 5,000.', 'Gate 2, beside the pharmacy.', null];

        CustomerAddress::create([
            'restaurant_id' => $customer->restaurant_id,
            'customer_id' => $customer->id,
            'label' => $label,
            'full_address' => $line,
            'latitude' => round($lat, 7),
            'longitude' => round($lng, 7),
            'building' => $building,
            'floor' => $floor,
            'delivery_instructions' => $notes[$this->random->getInt(0, count($notes) - 1)],
            'is_default' => $default,
        ]);
    }

    private function imageUrl(string $relative): ?string
    {
        if (! is_file("{$this->imageDir}/{$relative}")) {
            return null;
        }

        return "{$this->assetBaseUrl}/demo/images/{$relative}";
    }
}
