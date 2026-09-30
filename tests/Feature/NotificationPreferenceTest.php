<?php

namespace Tests\Feature;

use App\Models\NotificationPreference;
use Tests\TestCase;

class NotificationPreferenceTest extends TestCase
{
    public function test_default_preferences_are_all_disabled(): void
    {
        [, , $owner] = $this->makeRestaurantWithOwner('Pref Co');
        $token = $this->actingAsUser($owner);

        $prefs = $this->withUserToken($token)->getJson('/api/v1/notification-preferences')->assertOk()->json('data');

        $this->assertFalse($prefs['order.placed']['mail']);
        $this->assertFalse($prefs['order.placed']['sms']);
        $this->assertFalse($prefs['order.placed']['push']);
        $this->assertFalse($prefs['payment.received']['mail']);
    }

    public function test_updating_preferences_persists_and_is_reflected_back(): void
    {
        [, , $owner] = $this->makeRestaurantWithOwner('Pref Co');
        $token = $this->actingAsUser($owner);

        $updated = $this->withUserToken($token)->putJson('/api/v1/notification-preferences', [
            'preferences' => [
                ['event_key' => 'order.placed', 'channel' => 'mail', 'enabled' => true],
                ['event_key' => 'payment.received', 'channel' => 'sms', 'enabled' => true],
            ],
        ])->assertOk()->json('data');

        $this->assertTrue($updated['order.placed']['mail']);
        $this->assertTrue($updated['payment.received']['sms']);
        $this->assertFalse($updated['order.placed']['sms']);

        // Persisted, not just echoed back on the same response.
        $refetched = $this->withUserToken($token)->getJson('/api/v1/notification-preferences')->assertOk()->json('data');
        $this->assertTrue($refetched['order.placed']['mail']);
        $this->assertTrue($refetched['payment.received']['sms']);
    }

    public function test_updating_a_preference_twice_toggles_it_rather_than_duplicating_the_row(): void
    {
        [, , $owner] = $this->makeRestaurantWithOwner('Pref Co');
        $token = $this->actingAsUser($owner);

        $this->withUserToken($token)->putJson('/api/v1/notification-preferences', [
            'preferences' => [['event_key' => 'order.placed', 'channel' => 'mail', 'enabled' => true]],
        ])->assertOk();

        $this->withUserToken($token)->putJson('/api/v1/notification-preferences', [
            'preferences' => [['event_key' => 'order.placed', 'channel' => 'mail', 'enabled' => false]],
        ])->assertOk();

        $prefs = $this->withUserToken($token)->getJson('/api/v1/notification-preferences')->assertOk()->json('data');
        $this->assertFalse($prefs['order.placed']['mail']);
        $this->assertSame(1, NotificationPreference::where('user_id', $owner->id)->count());
    }

    public function test_an_unknown_event_key_is_rejected(): void
    {
        [, , $owner] = $this->makeRestaurantWithOwner('Pref Co');
        $token = $this->actingAsUser($owner);

        $this->withUserToken($token)->putJson('/api/v1/notification-preferences', [
            'preferences' => [['event_key' => 'not.a.real.event', 'channel' => 'mail', 'enabled' => true]],
        ])->assertStatus(422);
    }

    /** `database` is always on and isn't a settable preference — see NotificationPreferenceService's docblock. */
    public function test_the_database_channel_cannot_be_set_through_this_endpoint(): void
    {
        [, , $owner] = $this->makeRestaurantWithOwner('Pref Co');
        $token = $this->actingAsUser($owner);

        $this->withUserToken($token)->putJson('/api/v1/notification-preferences', [
            'preferences' => [['event_key' => 'order.placed', 'channel' => 'database', 'enabled' => false]],
        ])->assertStatus(422);
    }

    public function test_preferences_are_scoped_to_the_authenticated_user(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Pref Co');
        $manager = $this->makeBranchScopedUser($restaurant, $branch, 'branch-manager');

        $this->withUserToken($this->actingAsUser($owner))->putJson('/api/v1/notification-preferences', [
            'preferences' => [['event_key' => 'order.placed', 'channel' => 'mail', 'enabled' => true]],
        ])->assertOk();

        $managerPrefs = $this->withUserToken($this->actingAsUser($manager))
            ->getJson('/api/v1/notification-preferences')->assertOk()->json('data');
        $this->assertFalse($managerPrefs['order.placed']['mail']);
    }
}
