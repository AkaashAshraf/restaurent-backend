<?php

namespace Tests\Feature;

use App\Models\Feature;
use App\Models\SubscriptionPlan;
use Tests\TestCase;

/** Flow 1 / Flow 5 from the MVP spec, end to end through the real API. */
class SuperAdminOnboardingTest extends TestCase
{
    public function test_super_admin_can_onboard_a_restaurant_assign_a_plan_and_owner_can_then_operate(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $token = $this->actingAsUser($superAdmin);

        $create = $this->withUserToken($token)->postJson('/api/v1/super-admin/restaurants', [
            'name' => 'Fresh Onboard Co',
            'owner_name' => 'Jane Owner',
            'owner_email' => 'jane@onboard.test',
            'owner_password' => 'password123',
        ]);
        $create->assertStatus(201);
        $restaurantId = $create->json('data.restaurant.id');

        $plan = SubscriptionPlan::where('slug', 'standard')->first();

        $assign = $this->withUserToken($token)
            ->postJson("/api/v1/super-admin/restaurants/{$restaurantId}/subscription", [
                'subscription_plan_id' => $plan->id,
                'start_date' => now()->toDateString(),
            ]);
        $assign->assertStatus(201);

        // Owner logs in and can now create their first branch.
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'jane@onboard.test',
            'password' => 'password123',
        ]);
        $login->assertOk();
        $ownerToken = $login->json('data.token');

        $branch = $this->withUserToken($ownerToken)->postJson('/api/v1/branches', [
            'name' => 'Main Branch', 'branch_code' => 'MAIN',
        ]);
        $branch->assertStatus(201);
    }

    public function test_super_admin_dashboard_reflects_restaurant_counts(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        [$active, , ] = $this->makeRestaurantWithOwner('Active Co');
        [$suspended, , ] = $this->makeRestaurantWithOwner('Suspended Co');
        $suspended->update(['status' => 'SUSPENDED']);

        $token = $this->actingAsUser($superAdmin);
        $response = $this->withUserToken($token)->getJson('/api/v1/super-admin/dashboard');

        $response->assertOk();
        $this->assertSame(2, $response->json('data.total_restaurants'));
        $this->assertSame(1, $response->json('data.active_restaurants'));
    }
}
