<?php

namespace Tests\Feature;

use Tests\TestCase;

class CustomerAddressTest extends TestCase
{
    private function registerCustomer($restaurant, string $phone = '5551000'): string
    {
        return $this->postJson("/api/v1/app/auth/register?restaurant={$restaurant->slug}", [
            'phone' => $phone, 'password' => 'secret123',
        ])->assertStatus(201)->json('data.token');
    }

    public function test_customer_can_create_list_update_and_delete_their_own_address(): void
    {
        [$restaurant, , ] = $this->makeRestaurantWithOwner('Addr Co');
        $token = $this->registerCustomer($restaurant);

        $created = $this->withUserToken($token)->postJson('/api/v1/customer/addresses', [
            'label' => 'Home', 'full_address' => '1 Main St', 'latitude' => 24.86, 'longitude' => 67.00,
        ])->assertStatus(201)->json('data');

        $this->withUserToken($token)->getJson('/api/v1/customer/addresses')->assertOk()->assertJsonCount(1, 'data');

        $this->withUserToken($token)->patchJson("/api/v1/customer/addresses/{$created['id']}", ['label' => 'Office'])
            ->assertOk()->assertJsonPath('data.label', 'Office');

        $this->withUserToken($token)->deleteJson("/api/v1/customer/addresses/{$created['id']}")->assertOk();
        $this->withUserToken($token)->getJson('/api/v1/customer/addresses')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_setting_an_address_as_default_unsets_the_previous_default(): void
    {
        [$restaurant, , ] = $this->makeRestaurantWithOwner('Addr Co');
        $token = $this->registerCustomer($restaurant);

        $first = $this->withUserToken($token)->postJson('/api/v1/customer/addresses', [
            'full_address' => '1 Main St', 'is_default' => true,
        ])->assertStatus(201)->json('data');
        $this->assertTrue($first['is_default']);

        $second = $this->withUserToken($token)->postJson('/api/v1/customer/addresses', [
            'full_address' => '2 Main St', 'is_default' => true,
        ])->assertStatus(201)->json('data');
        $this->assertTrue($second['is_default']);

        $refreshedFirst = $this->withUserToken($token)->getJson("/api/v1/customer/addresses")
            ->json('data');
        $firstNow = collect($refreshedFirst)->firstWhere('id', $first['id']);
        $this->assertFalse($firstNow['is_default']);
    }

    public function test_a_customer_cannot_read_update_or_delete_another_customers_address(): void
    {
        [$restaurant, , ] = $this->makeRestaurantWithOwner('Addr Co');
        $tokenA = $this->registerCustomer($restaurant, '5551001');
        $tokenB = $this->registerCustomer($restaurant, '5551002');

        $addressA = $this->withUserToken($tokenA)->postJson('/api/v1/customer/addresses', [
            'full_address' => '1 Main St',
        ])->assertStatus(201)->json('data');

        $this->withUserToken($tokenB)->patchJson("/api/v1/customer/addresses/{$addressA['id']}", ['label' => 'Hijacked'])
            ->assertStatus(404);
        $this->withUserToken($tokenB)->deleteJson("/api/v1/customer/addresses/{$addressA['id']}")
            ->assertStatus(404);
        $this->withUserToken($tokenB)->getJson('/api/v1/customer/addresses')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_customer_address_routes_require_the_customer_app_feature(): void
    {
        // Basic plan does not include CUSTOMER_APP.
        [$restaurant, , ] = $this->makeRestaurantWithOwner('Addr Co', planSlug: 'basic');
        $token = $this->registerCustomer($restaurant);

        $this->withUserToken($token)->getJson('/api/v1/customer/addresses')
            ->assertStatus(403)->assertJson(['code' => 'FEATURE_DISABLED']);
    }
}
