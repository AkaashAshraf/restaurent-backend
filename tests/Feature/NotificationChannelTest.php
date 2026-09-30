<?php

namespace Tests\Feature;

use App\Contracts\PushGateway;
use App\Contracts\SmsGateway;
use App\Models\Category;
use App\Models\Product;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\OrderPlacedNotification;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationChannelTest extends TestCase
{
    private function makeProduct($restaurant, float $price = 10.00): Product
    {
        $category = Category::create(['restaurant_id' => $restaurant->id, 'name' => 'Pizzas', 'slug' => 'pizzas-'.uniqid()]);

        return Product::create([
            'restaurant_id' => $restaurant->id, 'category_id' => $category->id,
            'name' => 'Margherita', 'slug' => 'margherita-'.uniqid(), 'base_price' => $price,
        ]);
    }

    public function test_order_placed_dispatches_only_database_when_nothing_is_opted_in(): void
    {
        Notification::fake();

        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Chan Co');
        $product = $this->makeProduct($restaurant);
        $token = $this->actingAsUser($owner);

        $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201);

        Notification::assertSentTo($owner, OrderPlacedNotification::class, fn ($notification, $channels) => $channels === ['database']);
    }

    public function test_order_placed_also_dispatches_mail_once_the_recipient_opts_in(): void
    {
        Notification::fake();

        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Chan Co');
        $product = $this->makeProduct($restaurant);
        $token = $this->actingAsUser($owner);

        $this->withUserToken($token)->putJson('/api/v1/notification-preferences', [
            'preferences' => [['event_key' => 'order.placed', 'channel' => 'mail', 'enabled' => true]],
        ])->assertOk();

        $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201);

        Notification::assertSentTo($owner, OrderPlacedNotification::class, function ($notification, $channels) {
            return in_array('database', $channels, true)
                && in_array('mail', $channels, true)
                && ! in_array(SmsChannel::class, $channels, true);
        });
    }

    /** Two staff members at the same branch can have completely independent channel selections for the same event. */
    public function test_channel_selection_is_independent_per_recipient(): void
    {
        Notification::fake();

        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Chan Co');
        $kitchen = $this->makeBranchScopedUser($restaurant, $branch, 'kitchen');
        $product = $this->makeProduct($restaurant);

        $this->withUserToken($this->actingAsUser($owner))->putJson('/api/v1/notification-preferences', [
            'preferences' => [['event_key' => 'order.placed', 'channel' => 'mail', 'enabled' => true]],
        ])->assertOk();
        // Kitchen never opts in to anything.

        $this->withUserToken($this->actingAsUser($owner))->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201);

        Notification::assertSentTo($owner, OrderPlacedNotification::class, fn ($n, $channels) => in_array('mail', $channels, true));
        Notification::assertSentTo($kitchen, OrderPlacedNotification::class, fn ($n, $channels) => $channels === ['database']);
    }

    public function test_sms_gateway_is_called_with_the_recipients_phone_when_opted_in(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Chan Co');
        $owner->update(['phone' => '+15551234567']);
        $product = $this->makeProduct($restaurant);
        $token = $this->actingAsUser($owner);

        $this->withUserToken($token)->putJson('/api/v1/notification-preferences', [
            'preferences' => [['event_key' => 'order.placed', 'channel' => 'sms', 'enabled' => true]],
        ])->assertOk();

        $this->mock(SmsGateway::class, function ($mock) {
            $mock->shouldReceive('send')
                ->once()
                ->with('+15551234567', \Mockery::type('string'));
        });

        $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201);
    }

    /** No phone on file means SmsChannel is a silent no-op, not a failure. */
    public function test_sms_gateway_is_not_called_when_the_recipient_has_no_phone_on_file(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Chan Co');
        $product = $this->makeProduct($restaurant);
        $token = $this->actingAsUser($owner);

        $this->withUserToken($token)->putJson('/api/v1/notification-preferences', [
            'preferences' => [['event_key' => 'order.placed', 'channel' => 'sms', 'enabled' => true]],
        ])->assertOk();

        $this->mock(SmsGateway::class, function ($mock) {
            $mock->shouldNotReceive('send');
        });

        $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201);
    }

    public function test_push_gateway_fans_out_to_every_registered_device_token(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Chan Co', planSlug: 'premium');
        $restaurant->settings->update(['order_types' => ['DINE_IN', 'TAKEAWAY', 'DELIVERY']]);
        $rider = $this->makeBranchScopedUser($restaurant, $branch, 'rider');
        $product = $this->makeProduct($restaurant);
        $ownerToken = $this->actingAsUser($owner);
        $riderToken = $this->actingAsUser($rider);

        $this->withUserToken($riderToken)->postJson('/api/v1/device-tokens', ['token' => 'rider-phone', 'platform' => 'ANDROID'])->assertStatus(201);
        $this->withUserToken($riderToken)->postJson('/api/v1/device-tokens', ['token' => 'rider-tablet', 'platform' => 'ANDROID'])->assertStatus(201);
        $this->withUserToken($riderToken)->putJson('/api/v1/notification-preferences', [
            'preferences' => [['event_key' => 'order.rider_assigned', 'channel' => 'push', 'enabled' => true]],
        ])->assertOk();

        $this->mock(PushGateway::class, function ($mock) {
            $mock->shouldReceive('send')->twice();
        });

        $order = $this->withUserToken($ownerToken)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'DELIVERY', 'delivery_address' => '1 Main St',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201)->json('data');

        $this->withUserToken($ownerToken)->patchJson("/api/v1/orders/{$order['id']}/rider", [
            'rider_id' => $rider->id,
        ])->assertOk();
    }
}
