<?php

namespace Tests\Feature;

use Tests\TestCase;

class RestaurantAndSubscriptionStatusTest extends TestCase
{
    public function test_suspended_restaurant_blocks_operational_requests(): void
    {
        [$restaurant, , $owner] = $this->makeRestaurantWithOwner('Suspended Co');
        $restaurant->update(['status' => 'SUSPENDED']);

        $token = $this->actingAsUser($owner);

        $response = $this->withUserToken($token)->getJson('/api/v1/branches');

        $response->assertStatus(403);
        $response->assertJson(['code' => 'RESTAURANT_INACTIVE']);
    }

    public function test_expired_subscription_blocks_operational_requests_but_not_login(): void
    {
        [$restaurant, , $owner] = $this->makeRestaurantWithOwner('Expired Sub Co');
        $restaurant->activeSubscription()->first()->update(['status' => 'EXPIRED']);

        // Login itself must still work — expired subscriptions must not
        // delete/hide restaurant data (spec #11), only block new operations.
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $owner->email,
            'password' => 'password',
        ]);
        $login->assertOk();

        $token = $login->json('data.token');

        $response = $this->withUserToken($token)->getJson('/api/v1/branches');
        $response->assertStatus(403);
        $response->assertJson(['code' => 'SUBSCRIPTION_EXPIRED']);
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        [, , $owner] = $this->makeRestaurantWithOwner('Deactivated User Co');
        $owner->update(['status' => 'INACTIVE']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $owner->email,
            'password' => 'password',
        ]);

        $response->assertStatus(403);
        $response->assertJson(['code' => 'FORBIDDEN']);
    }
}
