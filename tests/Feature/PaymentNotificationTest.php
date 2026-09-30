<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Notifications\PaymentReceivedNotification;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PaymentNotificationTest extends TestCase
{
    private function makeProduct($restaurant, float $price = 10.00): Product
    {
        $category = Category::create(['restaurant_id' => $restaurant->id, 'name' => 'Pizzas', 'slug' => 'pizzas-'.uniqid()]);

        return Product::create([
            'restaurant_id' => $restaurant->id, 'category_id' => $category->id,
            'name' => 'Margherita', 'slug' => 'margherita-'.uniqid(), 'base_price' => $price,
        ]);
    }

    private function makeOrder($restaurant, $branch, $owner, float $price = 10.00): array
    {
        $product = $this->makeProduct($restaurant, $price);
        $token = $this->actingAsUser($owner);

        $order = $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201)->json('data');

        return [$order, $token];
    }

    public function test_an_immediately_settled_cash_payment_notifies_staff_with_payments_view(): void
    {
        Notification::fake();

        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Notify Pay Co');
        [$order, $token] = $this->makeOrder($restaurant, $branch, $owner, 10.00);

        $this->withUserToken($token)->postJson("/api/v1/orders/{$order['id']}/payments", [
            'method' => 'CASH', 'amount' => 10.00,
        ])->assertStatus(201);

        // Owner is restaurant-wide and has payments.view via the '*' role template.
        Notification::assertSentTo($owner, PaymentReceivedNotification::class);
    }

    /** Kitchen staff have `orders.view` but not `payments.view` — they should never see a payment.received notification. */
    public function test_staff_without_payments_view_are_not_notified(): void
    {
        Notification::fake();

        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Notify Pay Co');
        $kitchen = $this->makeBranchScopedUser($restaurant, $branch, 'kitchen');
        [$order, $token] = $this->makeOrder($restaurant, $branch, $owner, 10.00);

        $this->withUserToken($token)->postJson("/api/v1/orders/{$order['id']}/payments", [
            'method' => 'CASH', 'amount' => 10.00,
        ])->assertStatus(201);

        Notification::assertNotSentTo($kitchen, PaymentReceivedNotification::class);
    }

    /** A cashier does have `payments.view` and should be notified alongside the owner. */
    public function test_a_cashier_with_payments_view_is_notified(): void
    {
        Notification::fake();

        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Notify Pay Co');
        $cashier = $this->makeBranchScopedUser($restaurant, $branch, 'cashier');
        [$order, $token] = $this->makeOrder($restaurant, $branch, $owner, 10.00);

        $this->withUserToken($token)->postJson("/api/v1/orders/{$order['id']}/payments", [
            'method' => 'CASH', 'amount' => 10.00,
        ])->assertStatus(201);

        Notification::assertSentTo($cashier, PaymentReceivedNotification::class);
    }

    /** ONLINE payments are PENDING until confirm() — no payment.received notification until then. */
    public function test_an_online_payment_only_notifies_once_confirmed(): void
    {
        Notification::fake();

        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Notify Pay Co', planSlug: 'premium');
        [$order, $token] = $this->makeOrder($restaurant, $branch, $owner, 10.00);

        $payment = $this->withUserToken($token)->postJson("/api/v1/orders/{$order['id']}/payments", [
            'method' => 'ONLINE', 'amount' => 10.00,
        ])->assertStatus(201)->json('data');

        Notification::assertNotSentTo($owner, PaymentReceivedNotification::class);

        $this->withUserToken($token)->patchJson("/api/v1/payments/{$payment['id']}/confirm")->assertOk();

        Notification::assertSentTo($owner, PaymentReceivedNotification::class);
    }
}
