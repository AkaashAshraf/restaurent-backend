<?php

namespace Database\Seeders;

use App\Models\Feature;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    /** Example plans from spec section 10 / MVP section 5. */
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Basic', 'slug' => 'basic', 'branch_limit' => 1, 'user_limit' => 5, 'price' => 0,
                'features' => ['ADMIN_PANEL', 'KITCHEN_APP', 'DINE_IN', 'TAKEAWAY'],
            ],
            [
                'name' => 'Standard', 'slug' => 'standard', 'branch_limit' => 3, 'user_limit' => 20, 'price' => 49,
                'features' => [
                    'ADMIN_PANEL', 'KITCHEN_APP', 'WAITER_APP', 'CUSTOMER_APP', 'ONLINE_ORDERING',
                    'DINE_IN', 'TAKEAWAY', 'TABLE_MANAGEMENT', 'REPORTS', 'COUPONS', 'ONLINE_PAYMENTS',
                ],
            ],
            [
                'name' => 'Premium', 'slug' => 'premium', 'branch_limit' => null, 'user_limit' => null, 'price' => 149,
                'features' => array_keys(FeatureSeeder::KEYS),
            ],
        ];

        foreach ($plans as $p) {
            $plan = SubscriptionPlan::updateOrCreate(
                ['slug' => $p['slug']],
                ['name' => $p['name'], 'branch_limit' => $p['branch_limit'], 'user_limit' => $p['user_limit'], 'price' => $p['price']]
            );

            $featureIds = Feature::whereIn('key', $p['features'])->pluck('id');
            $plan->features()->sync($featureIds);
        }
    }
}
