<?php

namespace Tests;

use App\Models\Branch;
use App\Models\Restaurant;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\SubscriptionFeature;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Platform-wide catalogs every test can rely on being present,
        // without pulling in restaurant/branch demo fixtures.
        $this->seed(PermissionSeeder::class);
        $this->seed(FeatureSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(SubscriptionPlanSeeder::class);
    }

    protected function makeSuperAdmin(): User
    {
        return User::create([
            'name' => 'Super Admin',
            'email' => 'super_'.uniqid().'@platform.test',
            'password' => bcrypt('password'),
            'is_super_admin' => true,
            'status' => 'ACTIVE',
        ]);
    }

    /**
     * Builds a fully-operational restaurant: active Standard subscription,
     * one branch, and an Owner user with restaurant-wide access — the
     * minimum fixture most tenant/permission tests need.
     */
    protected function makeRestaurantWithOwner(string $name = 'Test Restaurant', ?string $planSlug = 'standard'): array
    {
        $restaurant = Restaurant::create([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name).'-'.uniqid(),
            'currency' => 'USD',
            'timezone' => 'UTC',
            'status' => 'ACTIVE',
        ]);
        $restaurant->settings()->create([]);

        if ($planSlug) {
            $plan = SubscriptionPlan::where('slug', $planSlug)->first();

            $subscription = Subscription::create([
                'restaurant_id' => $restaurant->id,
                'subscription_plan_id' => $plan->id,
                'start_date' => now()->subDay(),
                'expiry_date' => now()->addYear(),
                'status' => 'ACTIVE',
            ]);

            foreach ($plan->features as $feature) {
                SubscriptionFeature::create([
                    'subscription_id' => $subscription->id,
                    'feature_id' => $feature->id,
                    'enabled' => true,
                ]);
            }
        }

        $branch = Branch::create([
            'restaurant_id' => $restaurant->id,
            'name' => "{$name} - Main",
            'branch_code' => 'MAIN',
            'status' => 'ACTIVE',
            'latitude' => 24.86,
            'longitude' => 67.00,
        ]);

        $ownerRole = Role::where('restaurant_id', null)->where('slug', 'restaurant-owner')->first();

        $owner = User::create([
            'restaurant_id' => $restaurant->id,
            'name' => "{$name} Owner",
            'email' => 'owner_'.uniqid().'@test.test',
            'password' => bcrypt('password'),
            'status' => 'ACTIVE',
        ]);
        $owner->roles()->sync([$ownerRole->id]);

        return [$restaurant, $branch, $owner];
    }

    protected function makeBranchScopedUser(Restaurant $restaurant, Branch $branch, string $roleSlug = 'branch-manager'): User
    {
        $role = Role::where('restaurant_id', null)->where('slug', $roleSlug)->first();

        $user = User::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Branch User',
            'email' => 'branchuser_'.uniqid().'@test.test',
            'password' => bcrypt('password'),
            'status' => 'ACTIVE',
        ]);
        $user->roles()->sync([$role->id]);
        $user->branchAssignments()->create(['branch_id' => $branch->id, 'restaurant_id' => $restaurant->id]);

        return $user;
    }

    protected function actingAsUser(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    /**
     * Attaches a Bearer token for the next request.
     *
     * Necessary beyond a plain withHeader() call: Laravel's AuthManager
     * caches resolved guard instances (and the user a guard resolved) for
     * the lifetime of the application container. In production that's
     * irrelevant — every real HTTP request boots a fresh container — but
     * within a single test method the container persists across multiple
     * simulated requests, so a second request authenticating as a
     * different user would otherwise silently keep resolving to whichever
     * user the sanctum guard resolved first. forgetGuards() clears that
     * cache so each request re-authenticates from its own token.
     */
    protected function withUserToken(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }
}
