<?php

namespace Tests\Feature;

use App\Models\DeviceToken;
use Tests\TestCase;

class DeviceTokenTest extends TestCase
{
    public function test_a_device_token_can_be_registered(): void
    {
        [, , $owner] = $this->makeRestaurantWithOwner('Device Co');
        $token = $this->actingAsUser($owner);

        $this->withUserToken($token)->postJson('/api/v1/device-tokens', [
            'token' => 'abc123', 'platform' => 'ANDROID',
        ])->assertStatus(201);

        $this->assertDatabaseHas('device_tokens', [
            'token' => 'abc123', 'user_id' => $owner->id, 'platform' => 'ANDROID',
        ]);
    }

    /** A shared/reset device shouldn't keep push-notifying whoever used it last. */
    public function test_registering_the_same_token_again_reassigns_it_to_the_new_owner(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Device Co');
        $manager = $this->makeBranchScopedUser($restaurant, $branch, 'branch-manager');

        $this->withUserToken($this->actingAsUser($owner))->postJson('/api/v1/device-tokens', [
            'token' => 'shared-device', 'platform' => 'ANDROID',
        ])->assertStatus(201);

        $this->withUserToken($this->actingAsUser($manager))->postJson('/api/v1/device-tokens', [
            'token' => 'shared-device', 'platform' => 'ANDROID',
        ])->assertStatus(201);

        $this->assertSame(1, DeviceToken::where('token', 'shared-device')->count());
        $this->assertSame($manager->id, DeviceToken::where('token', 'shared-device')->first()->user_id);
    }

    public function test_a_user_can_unregister_their_own_token(): void
    {
        [, , $owner] = $this->makeRestaurantWithOwner('Device Co');
        $token = $this->actingAsUser($owner);

        $this->withUserToken($token)->postJson('/api/v1/device-tokens', [
            'token' => 'to-remove', 'platform' => 'IOS',
        ])->assertStatus(201);

        $this->withUserToken($token)->deleteJson('/api/v1/device-tokens', ['token' => 'to-remove'])->assertOk();

        $this->assertDatabaseMissing('device_tokens', ['token' => 'to-remove']);
    }

    public function test_a_user_cannot_unregister_someone_elses_token(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Device Co');
        $manager = $this->makeBranchScopedUser($restaurant, $branch, 'branch-manager');

        $this->withUserToken($this->actingAsUser($owner))->postJson('/api/v1/device-tokens', [
            'token' => 'owner-device', 'platform' => 'IOS',
        ])->assertStatus(201);

        $this->withUserToken($this->actingAsUser($manager))
            ->deleteJson('/api/v1/device-tokens', ['token' => 'owner-device'])->assertOk();

        // Still there — the delete only ever touches the caller's own tokens.
        $this->assertDatabaseHas('device_tokens', ['token' => 'owner-device']);
    }
}
