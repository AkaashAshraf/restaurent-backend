<?php

namespace Tests\Feature;

use App\Models\Customer;
use Tests\TestCase;

/**
 * Phase 6: the customer app/website's own auth — separate from staff's
 * AuthController (email, globally unique) because a customer's identity
 * is restaurant + phone, and the restaurant itself has to be resolved
 * the same way the public app/config and app/menu endpoints already do
 * (Host header, or here in tests, ?restaurant=slug).
 */
class CustomerAuthTest extends TestCase
{
    public function test_customer_can_register_login_fetch_profile_and_logout(): void
    {
        [$restaurant, , ] = $this->makeRestaurantWithOwner('Cust Co');

        $register = $this->postJson("/api/v1/app/auth/register?restaurant={$restaurant->slug}", [
            'name' => 'Alex', 'phone' => '5550001', 'password' => 'secret123',
        ])->assertStatus(201);

        $token = $register->json('data.token');
        $this->assertNotEmpty($token);

        $this->withUserToken($token)->getJson('/api/v1/customer/me')
            ->assertOk()->assertJsonPath('data.phone', '5550001');

        $login = $this->postJson("/api/v1/app/auth/login?restaurant={$restaurant->slug}", [
            'phone' => '5550001', 'password' => 'secret123',
        ])->assertOk();
        $loginToken = $login->json('data.token');

        $this->withUserToken($loginToken)->postJson('/api/v1/customer/auth/logout')->assertOk();

        // The revoked token no longer works.
        $this->withUserToken($loginToken)->getJson('/api/v1/customer/me')->assertStatus(401);
    }

    public function test_login_rejects_wrong_password(): void
    {
        [$restaurant, , ] = $this->makeRestaurantWithOwner('Cust Co');
        Customer::create([
            'restaurant_id' => $restaurant->id, 'phone' => '5550002',
            'password' => 'correct-password', 'status' => 'ACTIVE',
        ]);

        $this->postJson("/api/v1/app/auth/login?restaurant={$restaurant->slug}", [
            'phone' => '5550002', 'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    public function test_cannot_register_twice_with_the_same_phone_in_one_restaurant(): void
    {
        [$restaurant, , ] = $this->makeRestaurantWithOwner('Cust Co');

        $this->postJson("/api/v1/app/auth/register?restaurant={$restaurant->slug}", [
            'phone' => '5550003', 'password' => 'secret123',
        ])->assertStatus(201);

        $this->postJson("/api/v1/app/auth/register?restaurant={$restaurant->slug}", [
            'phone' => '5550003', 'password' => 'secret123',
        ])->assertStatus(422);
    }

    public function test_the_same_phone_number_can_belong_to_customers_of_two_different_restaurants(): void
    {
        [$restaurantA, , ] = $this->makeRestaurantWithOwner('Cust A');
        [$restaurantB, , ] = $this->makeRestaurantWithOwner('Cust B');

        $this->postJson("/api/v1/app/auth/register?restaurant={$restaurantA->slug}", [
            'phone' => '5559999', 'password' => 'secret123',
        ])->assertStatus(201);

        $this->postJson("/api/v1/app/auth/register?restaurant={$restaurantB->slug}", [
            'phone' => '5559999', 'password' => 'secret123',
        ])->assertStatus(201);
    }

    public function test_a_deactivated_customer_cannot_log_in(): void
    {
        [$restaurant, , ] = $this->makeRestaurantWithOwner('Cust Co');
        Customer::create([
            'restaurant_id' => $restaurant->id, 'phone' => '5550004',
            'password' => 'secret123', 'status' => 'INACTIVE',
        ]);

        $this->postJson("/api/v1/app/auth/login?restaurant={$restaurant->slug}", [
            'phone' => '5550004', 'password' => 'secret123',
        ])->assertStatus(403)->assertJson(['code' => 'FORBIDDEN']);
    }

    public function test_registering_against_an_unknown_restaurant_returns_not_found(): void
    {
        $this->postJson('/api/v1/app/auth/register?restaurant=does-not-exist', [
            'phone' => '5550005', 'password' => 'secret123',
        ])->assertStatus(404);
    }

    public function test_a_staff_bearer_token_cannot_reach_customer_routes(): void
    {
        [$restaurant, , $owner] = $this->makeRestaurantWithOwner('Cust Co');
        $staffToken = $this->actingAsUser($owner);

        $this->withUserToken($staffToken)->getJson('/api/v1/customer/me')->assertStatus(403);
    }

    public function test_a_customer_bearer_token_cannot_reach_staff_routes(): void
    {
        [$restaurant, , ] = $this->makeRestaurantWithOwner('Cust Co');
        $register = $this->postJson("/api/v1/app/auth/register?restaurant={$restaurant->slug}", [
            'phone' => '5550006', 'password' => 'secret123',
        ])->assertStatus(201);
        $customerToken = $register->json('data.token');

        $this->withUserToken($customerToken)->getJson('/api/v1/auth/me')->assertStatus(403);
        $this->withUserToken($customerToken)->getJson('/api/v1/branches')->assertStatus(403);
    }
}
