<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Tests\TestCase;

class PaymentTest extends TestCase
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

    public function test_cash_payment_is_recorded_and_settles_immediately(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Pay Co');
        [$order, $token] = $this->makeOrder($restaurant, $branch, $owner, 10.00);

        $response = $this->withUserToken($token)->postJson("/api/v1/orders/{$order['id']}/payments", [
            'method' => 'CASH', 'amount' => 10.00,
        ]);

        $response->assertStatus(201);
        $this->assertSame('PAID', $response->json('data.status'));
        $this->assertNotNull($response->json('data.paid_at'));

        $list = $this->withUserToken($token)->getJson("/api/v1/orders/{$order['id']}/payments")->assertOk();
        $this->assertCount(1, $list->json('data'));
    }

    public function test_split_payment_across_two_methods_can_fully_settle_an_order(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Pay Co');
        [$order, $token] = $this->makeOrder($restaurant, $branch, $owner, 10.00);

        $this->withUserToken($token)->postJson("/api/v1/orders/{$order['id']}/payments", [
            'method' => 'CASH', 'amount' => 6.00,
        ])->assertStatus(201);

        $this->withUserToken($token)->postJson("/api/v1/orders/{$order['id']}/payments", [
            'method' => 'CARD', 'amount' => 4.00,
        ])->assertStatus(201);

        // A third payment for anything above the (now zero) outstanding
        // balance is rejected.
        $this->withUserToken($token)->postJson("/api/v1/orders/{$order['id']}/payments", [
            'method' => 'CASH', 'amount' => 0.01,
        ])->assertStatus(422)->assertJson(['code' => 'PAYMENT_ERROR']);
    }

    public function test_payment_amount_cannot_exceed_the_orders_outstanding_balance(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Pay Co');
        [$order, $token] = $this->makeOrder($restaurant, $branch, $owner, 10.00);

        $this->withUserToken($token)->postJson("/api/v1/orders/{$order['id']}/payments", [
            'method' => 'CASH', 'amount' => 15.00,
        ])->assertStatus(422)->assertJson(['code' => 'PAYMENT_ERROR']);
    }

    public function test_online_payment_starts_pending_and_needs_confirmation(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Pay Co'); // standard plan has ONLINE_PAYMENTS
        [$order, $token] = $this->makeOrder($restaurant, $branch, $owner, 10.00);

        $payment = $this->withUserToken($token)->postJson("/api/v1/orders/{$order['id']}/payments", [
            'method' => 'ONLINE', 'amount' => 10.00,
        ])->assertStatus(201)->json('data');

        $this->assertSame('PENDING', $payment['status']);
        $this->assertNotNull($payment['transaction_reference']);

        $confirmed = $this->withUserToken($token)->patchJson("/api/v1/payments/{$payment['id']}/confirm")
            ->assertOk()->json('data');
        $this->assertSame('PAID', $confirmed['status']);

        // Already PAID -> confirming again is rejected.
        $this->withUserToken($token)->patchJson("/api/v1/payments/{$payment['id']}/confirm")
            ->assertStatus(422)->assertJson(['code' => 'PAYMENT_ERROR']);
    }

    public function test_online_payment_requires_the_online_payments_feature(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Pay Co', planSlug: 'basic');
        [$order, $token] = $this->makeOrder($restaurant, $branch, $owner, 10.00);

        $this->withUserToken($token)->postJson("/api/v1/orders/{$order['id']}/payments", [
            'method' => 'ONLINE', 'amount' => 10.00,
        ])->assertStatus(403)->assertJson(['code' => 'FEATURE_DISABLED']);
    }

    public function test_refunding_a_paid_payment_reopens_the_outstanding_balance(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Pay Co');
        [$order, $token] = $this->makeOrder($restaurant, $branch, $owner, 10.00);

        $payment = $this->withUserToken($token)->postJson("/api/v1/orders/{$order['id']}/payments", [
            'method' => 'CASH', 'amount' => 10.00,
        ])->assertStatus(201)->json('data');

        $refunded = $this->withUserToken($token)->patchJson("/api/v1/payments/{$payment['id']}/refund")
            ->assertOk()->json('data');
        $this->assertSame('REFUNDED', $refunded['status']);

        // Outstanding balance is back to the full amount, so it can be paid again.
        $this->withUserToken($token)->postJson("/api/v1/orders/{$order['id']}/payments", [
            'method' => 'CARD', 'amount' => 10.00,
        ])->assertStatus(201);

        // A refunded (not PAID) payment cannot be refunded again.
        $this->withUserToken($token)->patchJson("/api/v1/payments/{$payment['id']}/refund")
            ->assertStatus(422)->assertJson(['code' => 'PAYMENT_ERROR']);
    }

    public function test_recording_a_payment_requires_the_payments_create_permission(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Pay Co');
        [$order, ] = $this->makeOrder($restaurant, $branch, $owner, 10.00);

        // Kitchen staff have no payments.* permission at all.
        $kitchenUser = $this->makeBranchScopedUser($restaurant, $branch, 'kitchen');
        $token = $this->actingAsUser($kitchenUser);

        $this->withUserToken($token)->postJson("/api/v1/orders/{$order['id']}/payments", [
            'method' => 'CASH', 'amount' => 10.00,
        ])->assertStatus(403)->assertJson(['code' => 'FORBIDDEN']);
    }

    public function test_a_branch_scoped_user_cannot_record_a_payment_for_another_branchs_order(): void
    {
        [$restaurant, $branchA, $owner] = $this->makeRestaurantWithOwner('Pay Co');
        [$order, ] = $this->makeOrder($restaurant, $branchA, $owner, 10.00);

        $branchB = \App\Models\Branch::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Second Branch', 'branch_code' => 'SECOND', 'status' => 'ACTIVE',
        ]);
        $cashierAtOtherBranch = $this->makeBranchScopedUser($restaurant, $branchB, 'cashier');
        $token = $this->actingAsUser($cashierAtOtherBranch);

        $this->withUserToken($token)->postJson("/api/v1/orders/{$order['id']}/payments", [
            'method' => 'CASH', 'amount' => 10.00,
        ])->assertStatus(403)->assertJson(['code' => 'FORBIDDEN']);
    }

    public function test_customer_can_initiate_and_confirm_an_online_payment_for_their_own_order(): void
    {
        [$restaurant, $branch, ] = $this->makeRestaurantWithOwner('Pay Co');
        $product = $this->makeProduct($restaurant, 10.00);

        $customerToken = $this->postJson("/api/v1/app/auth/register?restaurant={$restaurant->slug}", [
            'phone' => '5559100', 'password' => 'secret123',
        ])->assertStatus(201)->json('data.token');

        $order = $this->withUserToken($customerToken)->postJson('/api/v1/customer/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201)->json('data');

        $payment = $this->withUserToken($customerToken)->postJson("/api/v1/customer/orders/{$order['id']}/payments", [
            'amount' => 10.00,
        ])->assertStatus(201)->json('data');
        $this->assertSame('ONLINE', $payment['method']);
        $this->assertSame('PENDING', $payment['status']);

        $confirmed = $this->withUserToken($customerToken)
            ->patchJson("/api/v1/customer/payments/{$payment['id']}/confirm")
            ->assertOk()->json('data');
        $this->assertSame('PAID', $confirmed['status']);
    }

    public function test_customer_cannot_confirm_another_customers_payment(): void
    {
        [$restaurant, $branch, ] = $this->makeRestaurantWithOwner('Pay Co');
        $product = $this->makeProduct($restaurant, 10.00);

        $tokenA = $this->postJson("/api/v1/app/auth/register?restaurant={$restaurant->slug}", [
            'phone' => '5559101', 'password' => 'secret123',
        ])->assertStatus(201)->json('data.token');
        $tokenB = $this->postJson("/api/v1/app/auth/register?restaurant={$restaurant->slug}", [
            'phone' => '5559102', 'password' => 'secret123',
        ])->assertStatus(201)->json('data.token');

        $orderA = $this->withUserToken($tokenA)->postJson('/api/v1/customer/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201)->json('data');

        $paymentA = $this->withUserToken($tokenA)->postJson("/api/v1/customer/orders/{$orderA['id']}/payments", [
            'amount' => 10.00,
        ])->assertStatus(201)->json('data');

        $this->withUserToken($tokenB)
            ->patchJson("/api/v1/customer/payments/{$paymentA['id']}/confirm")
            ->assertStatus(404);
    }

    public function test_cannot_record_a_payment_against_a_cancelled_order(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Pay Co');
        [$order, $token] = $this->makeOrder($restaurant, $branch, $owner, 10.00);

        $this->withUserToken($token)->patchJson("/api/v1/orders/{$order['id']}/status", [
            'status' => 'CANCELLED',
        ])->assertOk();

        $this->withUserToken($token)->postJson("/api/v1/orders/{$order['id']}/payments", [
            'method' => 'CASH', 'amount' => 10.00,
        ])->assertStatus(422)->assertJson(['code' => 'PAYMENT_ERROR']);
    }
}
