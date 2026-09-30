<?php

namespace Tests\Feature;

use App\Models\DeviceToken;
use Tests\TestCase;

class CustomerDeviceTokenTest extends TestCase
{
    private function makeCustomer($restaurant): string
    {
        return $this->postJson("/api/v1/app/auth/register?restaurant={$restaurant->slug}", [
            'name' => 'Alex', 'phone' => '5559'.random_int(1000, 9999), 'password' => 'secret123',
        ])->assertStatus(201)->json('data.token');
    }

    public function test_a_customer_can_register_a_device_token(): void
    {
        [$restaurant, , ] = $this->makeRestaurantWithOwner('Cust Device Co');
        $token = $this->makeCustomer($restaurant);

        $this->withUserToken($token)->postJson('/api/v1/customer/device-tokens', [
            'token' => 'cust-abc', 'platform' => 'IOS',
        ])->assertStatus(201);

        $row = DeviceToken::where('token', 'cust-abc')->first();
        $this->assertNotNull($row->customer_id);
        $this->assertNull($row->user_id);
    }

    /** A device handed from a staff member to a customer (or vice versa) reassigns cleanly, clearing the old owner column. */
    public function test_reassigning_a_token_from_staff_to_a_customer_clears_the_staff_owner_column(): void
    {
        [$restaurant, , $owner] = $this->makeRestaurantWithOwner('Cust Device Co');
        $customerToken = $this->makeCustomer($restaurant);

        $this->withUserToken($this->actingAsUser($owner))->postJson('/api/v1/device-tokens', [
            'token' => 'shared-device', 'platform' => 'ANDROID',
        ])->assertStatus(201);

        $this->withUserToken($customerToken)->postJson('/api/v1/customer/device-tokens', [
            'token' => 'shared-device', 'platform' => 'ANDROID',
        ])->assertStatus(201);

        $row = DeviceToken::where('token', 'shared-device')->first();
        $this->assertNull($row->user_id);
        $this->assertNotNull($row->customer_id);
        $this->assertSame(1, DeviceToken::where('token', 'shared-device')->count());
    }

    public function test_a_customer_can_unregister_their_own_token(): void
    {
        [$restaurant, , ] = $this->makeRestaurantWithOwner('Cust Device Co');
        $token = $this->makeCustomer($restaurant);

        $this->withUserToken($token)->postJson('/api/v1/customer/device-tokens', [
            'token' => 'to-remove', 'platform' => 'IOS',
        ])->assertStatus(201);

        $this->withUserToken($token)->deleteJson('/api/v1/customer/device-tokens', ['token' => 'to-remove'])->assertOk();

        $this->assertDatabaseMissing('device_tokens', ['token' => 'to-remove']);
    }

    public function test_a_customer_cannot_unregister_a_staff_members_token(): void
    {
        [$restaurant, , $owner] = $this->makeRestaurantWithOwner('Cust Device Co');
        $customerToken = $this->makeCustomer($restaurant);

        $this->withUserToken($this->actingAsUser($owner))->postJson('/api/v1/device-tokens', [
            'token' => 'owner-device', 'platform' => 'IOS',
        ])->assertStatus(201);

        $this->withUserToken($customerToken)->deleteJson('/api/v1/customer/device-tokens', ['token' => 'owner-device'])->assertOk();

        // Still there — the delete only ever touches the caller's own tokens.
        $this->assertDatabaseHas('device_tokens', ['token' => 'owner-device']);
    }
}
