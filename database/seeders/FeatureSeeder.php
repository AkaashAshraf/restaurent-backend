<?php

namespace Database\Seeders;

use App\Models\Feature;
use Illuminate\Database\Seeder;

class FeatureSeeder extends Seeder
{
    /** MVP feature list per spec section 5. */
    public const KEYS = [
        'ADMIN_PANEL' => 'Admin Panel',
        'KITCHEN_APP' => 'Kitchen App',
        'WAITER_APP' => 'Waiter App',
        'RIDER_APP' => 'Rider App',
        'CUSTOMER_APP' => 'Customer App',
        'CUSTOMER_WEBSITE' => 'Customer Website',
        'ONLINE_ORDERING' => 'Online Ordering',
        'DELIVERY' => 'Delivery',
        'DINE_IN' => 'Dine-in',
        'TAKEAWAY' => 'Takeaway',
        'TABLE_MANAGEMENT' => 'Table Management',
        'REPORTS' => 'Reports',
        'COUPONS' => 'Coupons',
        'ONLINE_PAYMENTS' => 'Online Payments',
    ];

    public function run(): void
    {
        foreach (self::KEYS as $key => $name) {
            Feature::updateOrCreate(['key' => $key], ['name' => $name, 'is_active' => true]);
        }
    }
}
