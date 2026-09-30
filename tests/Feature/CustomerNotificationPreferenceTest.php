<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\NotificationPreference;
use Tests\TestCase;

class CustomerNotificationPreferenceTest extends TestCase
{
    private function makeCustomer($restaurant): string
    {
        return $this->postJson("/api/v1/app/auth/register?restaurant={$restaurant->slug}", [
            'name' => 'Alex', 'phone' => '5559'.random_int(1000, 9999), 'password' => 'secret123',
        ])->assertStatus(201)->json('data.token');
    }

    public function test_a_customers_default_preference_matrix_only_lists_the_two_events_that_apply_to_them(): void
    {
        [$restaurant, , ] = $this->makeRestaurantWithOwner('Cust Pref Co');
        $token = $this->makeCustomer($restaurant);

        $prefs = $this->withUserToken($token)->getJson('/api/v1/customer/notification-preferences')->assertOk()->json('data');

        $this->assertArrayHasKey('order.placed', $prefs);
        $this->assertArrayHasKey('order.status_changed', $prefs);
        $this->assertArrayNotHasKey('order.rider_assigned', $prefs);
        $this->assertArrayNotHasKey('payment.received', $prefs);
        $this->assertFalse($prefs['order.placed']['sms']);
    }

    public function test_a_customer_cannot_set_a_preference_for_a_staff_only_event(): void
    {
        [$restaurant, , ] = $this->makeRestaurantWithOwner('Cust Pref Co');
        $token = $this->makeCustomer($restaurant);

        $this->withUserToken($token)->putJson('/api/v1/customer/notification-preferences', [
            'preferences' => [['event_key' => 'payment.received', 'channel' => 'sms', 'enabled' => true]],
        ])->assertStatus(422);
    }

    public function test_updating_a_customers_preference_persists(): void
    {
        [$restaurant, , ] = $this->makeRestaurantWithOwner('Cust Pref Co');
        $token = $this->makeCustomer($restaurant);

        $this->withUserToken($token)->putJson('/api/v1/customer/notification-preferences', [
            'preferences' => [['event_key' => 'order.status_changed', 'channel' => 'sms', 'enabled' => true]],
        ])->assertOk();

        $refetched = $this->withUserToken($token)->getJson('/api/v1/customer/notification-preferences')->assertOk()->json('data');
        $this->assertTrue($refetched['order.status_changed']['sms']);
    }

    /** A staff user and a customer at the same restaurant never collide, even though the underlying table is shared. */
    public function test_staff_and_customer_preferences_are_stored_independently(): void
    {
        [$restaurant, , $owner] = $this->makeRestaurantWithOwner('Cust Pref Co');
        $customerToken = $this->makeCustomer($restaurant);
        $ownerToken = $this->actingAsUser($owner);

        $this->withUserToken($ownerToken)->putJson('/api/v1/notification-preferences', [
            'preferences' => [['event_key' => 'order.placed', 'channel' => 'mail', 'enabled' => true]],
        ])->assertOk();

        $customerPrefs = $this->withUserToken($customerToken)
            ->getJson('/api/v1/customer/notification-preferences')->assertOk()->json('data');
        $this->assertFalse($customerPrefs['order.placed']['mail']);

        $this->assertSame(1, NotificationPreference::whereNotNull('user_id')->count());
        $this->assertSame(0, NotificationPreference::whereNotNull('customer_id')->count());
    }

    public function test_two_customers_preferences_never_leak_into_each_other(): void
    {
        [$restaurant, , ] = $this->makeRestaurantWithOwner('Cust Pref Co');
        $tokenA = $this->makeCustomer($restaurant);
        $tokenB = $this->makeCustomer($restaurant);

        $this->withUserToken($tokenA)->putJson('/api/v1/customer/notification-preferences', [
            'preferences' => [['event_key' => 'order.placed', 'channel' => 'push', 'enabled' => true]],
        ])->assertOk();

        $bPrefs = $this->withUserToken($tokenB)->getJson('/api/v1/customer/notification-preferences')->assertOk()->json('data');
        $this->assertFalse($bPrefs['order.placed']['push']);
    }
}
