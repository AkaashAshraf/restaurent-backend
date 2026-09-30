<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\OrderPlacedNotification;
use App\Notifications\OrderRiderAssignedNotification;
use App\Notifications\OrderStatusChangedNotification;
use App\Notifications\PaymentReceivedNotification;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CustomerNotificationTest extends TestCase
{
    private function makeProduct($restaurant, float $price = 10.00): Product
    {
        $category = Category::create(['restaurant_id' => $restaurant->id, 'name' => 'Pizzas', 'slug' => 'pizzas-'.uniqid()]);

        return Product::create([
            'restaurant_id' => $restaurant->id, 'category_id' => $category->id,
            'name' => 'Margherita', 'slug' => 'margherita-'.uniqid(), 'base_price' => $price,
        ]);
    }

    private function makeCustomer($restaurant): array
    {
        $token = $this->postJson("/api/v1/app/auth/register?restaurant={$restaurant->slug}", [
            'name' => 'Alex', 'phone' => '5559'.random_int(1000, 9999), 'password' => 'secret123',
        ])->assertStatus(201)->json('data.token');

        return [Customer::where('phone', 'like', '5559%')->latest('id')->first(), $token];
    }

    public function test_a_customer_placed_order_notifies_the_customer_as_well_as_staff(): void
    {
        [$restaurant, $branch, ] = $this->makeRestaurantWithOwner('Cust Notify Co');
        [$customer, $customerToken] = $this->makeCustomer($restaurant);
        $product = $this->makeProduct($restaurant);

        $this->withUserToken($customerToken)->postJson('/api/v1/customer/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $inbox = $this->withUserToken($customerToken)->getJson('/api/v1/customer/notifications')
            ->assertOk()->json('data.data');
        $this->assertNotEmpty($inbox);
        $this->assertSame('order.placed', $inbox[0]['data']['type']);
        $this->assertStringContainsString('Your', $inbox[0]['data']['body']);
    }

    /** A staff-placed order with a known customer_id notifies that customer too, not just the order's own placer. */
    public function test_a_staff_placed_order_with_a_known_customer_also_notifies_that_customer(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Cust Notify Co');
        [$customer, ] = $this->makeCustomer($restaurant);
        $product = $this->makeProduct($restaurant);

        $this->withUserToken($this->actingAsUser($owner))->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY', 'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => Customer::class, 'notifiable_id' => $customer->id,
        ]);
    }

    /** A meaningful status change reaches both the branch staff and the customer, each with their own worded body. */
    public function test_order_status_changed_notifies_both_the_customer_and_staff(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Cust Notify Co');
        [$customer, $customerToken] = $this->makeCustomer($restaurant);
        $product = $this->makeProduct($restaurant);
        $ownerToken = $this->actingAsUser($owner);

        $order = $this->withUserToken($customerToken)->postJson('/api/v1/customer/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201)->json('data');

        $this->withUserToken($customerToken)->patchJson('/api/v1/customer/notifications/read-all')->assertOk();

        $this->withUserToken($ownerToken)->patchJson("/api/v1/orders/{$order['id']}/status", ['status' => 'CONFIRMED'])->assertOk();
        $this->withUserToken($ownerToken)->patchJson("/api/v1/orders/{$order['id']}/status", ['status' => 'PREPARING'])->assertOk();

        // Internal kitchen steps stay silent for the customer too.
        $unreadAfterInternal = $this->withUserToken($customerToken)
            ->getJson('/api/v1/customer/notifications?unread_only=1')->assertOk()->json('data.data');
        $this->assertEmpty($unreadAfterInternal);

        $this->withUserToken($ownerToken)->patchJson("/api/v1/orders/{$order['id']}/status", ['status' => 'READY'])->assertOk();

        $unreadAfterReady = $this->withUserToken($customerToken)
            ->getJson('/api/v1/customer/notifications?unread_only=1')->assertOk()->json('data.data');
        $this->assertCount(1, $unreadAfterReady);
        $this->assertSame('Your order is now READY.', $unreadAfterReady[0]['data']['body']);
    }

    /** order.rider_assigned and payment.received are staff/rider-only — a customer is never sent either. */
    public function test_rider_assignment_and_payment_received_never_reach_the_customer(): void
    {
        Notification::fake();

        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Cust Notify Co', planSlug: 'premium');
        $restaurant->settings->update(['order_types' => ['DINE_IN', 'TAKEAWAY', 'DELIVERY']]);
        [$customer, $customerToken] = $this->makeCustomer($restaurant);
        $rider = $this->makeBranchScopedUser($restaurant, $branch, 'rider');
        $product = $this->makeProduct($restaurant);
        $ownerToken = $this->actingAsUser($owner);

        $order = $this->withUserToken($customerToken)->postJson('/api/v1/customer/orders', [
            'branch_id' => $branch->id, 'order_type' => 'DELIVERY', 'delivery_address' => '1 Main St',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201)->json('data');

        $this->withUserToken($ownerToken)->patchJson("/api/v1/orders/{$order['id']}/rider", ['rider_id' => $rider->id])->assertOk();
        $this->withUserToken($ownerToken)->postJson("/api/v1/orders/{$order['id']}/payments", ['method' => 'CASH', 'amount' => 10.00])->assertStatus(201);

        Notification::assertNotSentTo($customer, OrderRiderAssignedNotification::class);
        Notification::assertNotSentTo($customer, PaymentReceivedNotification::class);
    }

    public function test_a_customer_only_ever_sees_their_own_notifications(): void
    {
        [$restaurant, $branch, ] = $this->makeRestaurantWithOwner('Cust Notify Co');
        [, $customerAToken] = $this->makeCustomer($restaurant);
        [, $customerBToken] = $this->makeCustomer($restaurant);
        $product = $this->makeProduct($restaurant);

        $this->withUserToken($customerAToken)->postJson('/api/v1/customer/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $bInbox = $this->withUserToken($customerBToken)->getJson('/api/v1/customer/notifications')->assertOk()->json('data.data');
        $this->assertEmpty($bInbox);
    }

    public function test_a_customer_opting_into_sms_gets_the_sms_gateway_called_with_their_phone(): void
    {
        [$restaurant, $branch, ] = $this->makeRestaurantWithOwner('Cust Notify Co');
        [$customer, $customerToken] = $this->makeCustomer($restaurant);
        $product = $this->makeProduct($restaurant);

        $this->withUserToken($customerToken)->putJson('/api/v1/customer/notification-preferences', [
            'preferences' => [['event_key' => 'order.placed', 'channel' => 'sms', 'enabled' => true]],
        ])->assertOk();

        $this->mock(\App\Contracts\SmsGateway::class, function ($mock) use ($customer) {
            $mock->shouldReceive('send')->once()->with($customer->phone, \Mockery::type('string'));
        });

        $this->withUserToken($customerToken)->postJson('/api/v1/customer/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201);
    }

    public function test_customer_channel_opt_in_never_affects_staff_who_opted_in_to_nothing(): void
    {
        Notification::fake();

        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Cust Notify Co');
        [, $customerToken] = $this->makeCustomer($restaurant);
        $product = $this->makeProduct($restaurant);

        $this->withUserToken($customerToken)->putJson('/api/v1/customer/notification-preferences', [
            'preferences' => [['event_key' => 'order.placed', 'channel' => 'mail', 'enabled' => true]],
        ])->assertOk();

        $this->withUserToken($customerToken)->postJson('/api/v1/customer/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201);

        Notification::assertSentTo($owner, OrderPlacedNotification::class, fn ($n, $channels) => $channels === ['database']);
    }
}
