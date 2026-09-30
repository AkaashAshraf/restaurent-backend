<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            FeatureSeeder::class,
            RoleSeeder::class,
            SubscriptionPlanSeeder::class,
            PakistanDemoSeeder::class,
        ]);
    }
}
