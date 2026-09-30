<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Restaurant;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\SubscriptionFeature;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Convenience seeder for local development / manual QA: one Super Admin
 * plus two independent restaurants, each with an owner, a branch and an
 * active subscription — enough to click through the tenant/branch
 * isolation behavior by hand.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'superadmin@platform.test'],
            ['name' => 'Platform Super Admin', 'password' => Hash::make('password'), 'is_super_admin' => true, 'status' => 'ACTIVE']
        );

        $standardPlan = SubscriptionPlan::where('slug', 'standard')->first();
        $ownerRole = Role::where('restaurant_id', null)->where('slug', 'restaurant-owner')->first();

        foreach (['Pizza House' => 'pizza-house', 'Burger Barn' => 'burger-barn'] as $name => $slug) {
            $restaurant = Restaurant::updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'currency' => 'USD', 'timezone' => 'UTC', 'status' => 'ACTIVE']
            );

            $restaurant->settings()->firstOrCreate([]);

            $owner = User::updateOrCreate(
                ['email' => strtolower(str_replace(' ', '', $slug)).'@owner.test'],
                ['restaurant_id' => $restaurant->id, 'name' => "{$name} Owner", 'password' => Hash::make('password'), 'status' => 'ACTIVE']
            );
            $owner->roles()->sync([$ownerRole->id]);

            $branch = Branch::updateOrCreate(
                ['restaurant_id' => $restaurant->id, 'branch_code' => 'MAIN'],
                ['name' => "{$name} - Main Branch", 'status' => 'ACTIVE', 'latitude' => 24.8607, 'longitude' => 67.0011]
            );
            $branch->settings()->firstOrCreate(['restaurant_id' => $restaurant->id]);

            $subscription = Subscription::updateOrCreate(
                ['restaurant_id' => $restaurant->id, 'subscription_plan_id' => $standardPlan->id],
                ['start_date' => now()->subDay(), 'expiry_date' => now()->addYear(), 'status' => 'ACTIVE']
            );

            foreach ($standardPlan->features as $feature) {
                SubscriptionFeature::updateOrCreate(
                    ['subscription_id' => $subscription->id, 'feature_id' => $feature->id],
                    ['enabled' => true]
                );
            }
        }
    }
}
