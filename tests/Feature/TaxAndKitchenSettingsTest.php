<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Table;
use Tests\TestCase;

/**
 * Restaurant-level settings added for Pakistan-style billing and kitchen
 * routing:
 *  - tax depends on how the customer pays (cash tax / card tax), fixed by
 *    the first payment;
 *  - an FBR number printed on the bill;
 *  - which kinds of orders reach the kitchen;
 *  - waiters can only place dine-in orders;
 *  - the kitchen's "Picked up" list.
 */
class TaxAndKitchenSettingsTest extends TestCase
{
    private $restaurant;
    private $branch;
    private $product;
    private string $ownerToken;
    private string $cashierToken;
    private string $waiterToken;
    private string $kitchenToken;
    private Table $table;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->restaurant, $this->branch, $owner] = $this->makeRestaurantWithOwner('Tax Co');
        $category = Category::create(['restaurant_id' => $this->restaurant->id, 'name' => 'Mains', 'slug' => 'mains-'.uniqid()]);
        $this->product = Product::create([
            'restaurant_id' => $this->restaurant->id, 'category_id' => $category->id,
            'name' => 'Karahi', 'slug' => 'k-'.uniqid(), 'base_price' => 1000.00,
        ]);
        $this->table = Table::create([
            'restaurant_id' => $this->restaurant->id, 'branch_id' => $this->branch->id,
            'table_number' => 'T1', 'capacity' => 4, 'status' => 'AVAILABLE',
        ]);

        $this->restaurant->settings()->updateOrCreate([], [
            'order_types' => ['DINE_IN', 'TAKEAWAY', 'DELIVERY'],
            'tax_enabled' => true,
            'tax_percentage' => 13,
            'cash_tax_percentage' => 13,
            'card_tax_percentage' => 8,
            'fbr_number' => 'FBR-12345',
        ]);

        $this->ownerToken = $this->actingAsUser($owner);
        $this->cashierToken = $this->actingAsUser($this->makeBranchScopedUser($this->restaurant, $this->branch, 'cashier'));
        $this->waiterToken = $this->actingAsUser($this->makeBranchScopedUser($this->restaurant, $this->branch, 'waiter'));
        $this->kitchenToken = $this->actingAsUser($this->makeBranchScopedUser($this->restaurant, $this->branch, 'kitchen'));
    }

    private function takeaway(int $qty = 1): array
    {
        return $this->withUserToken($this->cashierToken)->postJson('/api/v1/orders', [
            'branch_id' => $this->branch->id,
            'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $this->product->id, 'quantity' => $qty]],
        ])->assertStatus(201)->json('data');
    }

    private function pay(int $orderId, string $method, ?float $amount = null, int $expect = 201)
    {
        $body = ['method' => $method] + ($amount !== null ? ['amount' => $amount] : []);

        return $this->withUserToken($this->cashierToken)->postJson("/api/v1/orders/{$orderId}/payments", $body)->assertStatus($expect);
    }

    // ---- tax by payment method -------------------------------------------------

    public function test_a_new_order_shows_the_bill_for_both_cash_and_card(): void
    {
        $order = $this->takeaway(2);   // subtotal 2000

        $this->assertSame('FBR-12345', $order['fbr_number']);
        $this->assertEquals(260.0, $order['tax_options']['CASH']['tax_amount']);
        $this->assertEquals(2260.0, $order['tax_options']['CASH']['total_amount']);
        $this->assertEquals(160.0, $order['tax_options']['CARD']['tax_amount']);
        $this->assertEquals(2160.0, $order['tax_options']['CARD']['total_amount']);
        $this->assertFalse($order['tax_options']['locked']);
    }

    public function test_paying_by_card_applies_the_card_tax_and_fixes_it(): void
    {
        $order = $this->takeaway(2);

        $this->pay($order['id'], 'CARD');   // no amount: pays what is left

        $fresh = $this->withUserToken($this->cashierToken)->getJson("/api/v1/orders/{$order['id']}")->json('data');
        $this->assertEquals(160.0, $fresh['tax_amount']);
        $this->assertEquals(8.0, $fresh['tax_rate']);
        $this->assertSame('CARD', $fresh['tax_method']);
        $this->assertEquals(2160.0, $fresh['total_amount']);
        $this->assertEquals(2160.0, (float) $fresh['payments'][0]['amount']);
        $this->assertTrue($fresh['tax_options']['locked']);
    }

    public function test_paying_by_cash_applies_the_cash_tax(): void
    {
        $order = $this->takeaway(1);   // subtotal 1000

        $this->pay($order['id'], 'CASH', 1130.00);

        $fresh = $this->withUserToken($this->cashierToken)->getJson("/api/v1/orders/{$order['id']}")->json('data');
        $this->assertEquals(130.0, $fresh['tax_amount']);
        $this->assertSame('CASH', $fresh['tax_method']);
    }

    public function test_the_tax_stays_fixed_when_the_rest_is_paid_another_way(): void
    {
        $order = $this->takeaway(1);

        $this->pay($order['id'], 'CASH', 500.00);       // first payment fixes the cash rate
        $this->pay($order['id'], 'CARD');               // pays the remaining 630

        $fresh = $this->withUserToken($this->cashierToken)->getJson("/api/v1/orders/{$order['id']}")->json('data');
        $this->assertEquals(1130.0, $fresh['total_amount']);
        $this->assertSame('CASH', $fresh['tax_method']);
        $this->assertEquals(1130.0, array_sum(array_map(fn ($p) => (float) $p['amount'], $fresh['payments'])));
    }

    public function test_items_added_after_the_tax_is_fixed_use_the_same_rate(): void
    {
        $order = $this->takeaway(1);
        $this->pay($order['id'], 'CARD', 500.00);       // card rate 8% now fixed

        $this->withUserToken($this->cashierToken)->postJson("/api/v1/orders/{$order['id']}/items", [
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $fresh = $this->withUserToken($this->cashierToken)->getJson("/api/v1/orders/{$order['id']}")->json('data');
        $this->assertEquals(2000.0, $fresh['subtotal']);
        $this->assertEquals(160.0, $fresh['tax_amount']);   // 8% of 2000, not 13%
    }

    public function test_when_no_card_rate_is_set_card_uses_the_default(): void
    {
        $this->restaurant->settings->update(['card_tax_percentage' => null]);
        $order = $this->takeaway(1);

        $this->assertEquals(130.0, $order['tax_options']['CARD']['tax_amount']);
    }

    public function test_tax_off_means_no_tax_on_either_method(): void
    {
        $this->restaurant->settings->update(['tax_enabled' => false]);
        $order = $this->takeaway(1);

        $this->assertEquals(0.0, $order['tax_options']['CASH']['tax_amount']);
        $this->assertEquals(0.0, $order['tax_options']['CARD']['tax_amount']);
        $this->assertEquals(1000.0, $order['total_amount']);
    }

    // ---- admin settings --------------------------------------------------------

    public function test_the_owner_can_set_cash_tax_card_tax_fbr_number_and_kitchen_types(): void
    {
        $this->withUserToken($this->ownerToken)->patchJson('/api/v1/settings/ordering', [
            'cash_tax_percentage' => 16,
            'card_tax_percentage' => 5,
            'fbr_number' => 'POS-998877',
            'kitchen_order_types' => ['DINE_IN', 'DELIVERY'],
        ])->assertStatus(200)
            ->assertJsonPath('data.fbr_number', 'POS-998877')
            ->assertJsonPath('data.kitchen_order_types', ['DINE_IN', 'DELIVERY']);

        $settings = $this->restaurant->settings->fresh();
        $this->assertEquals(16.0, (float) $settings->tax_percentage, 'default rate follows the cash rate');
        $this->assertEquals(5.0, (float) $settings->card_tax_percentage);
    }

    public function test_kitchen_types_must_be_valid_and_not_empty(): void
    {
        $this->withUserToken($this->ownerToken)->patchJson('/api/v1/settings/ordering', ['kitchen_order_types' => []])
            ->assertStatus(422);
        $this->withUserToken($this->ownerToken)->patchJson('/api/v1/settings/ordering', ['kitchen_order_types' => ['PICKUP']])
            ->assertStatus(422);
    }

    public function test_the_apps_config_exposes_tax_and_kitchen_settings(): void
    {
        $this->withUserToken($this->kitchenToken)->getJson('/api/v1/config')->assertStatus(200)
            ->assertJsonPath('data.tax.cash_percentage', 13)
            ->assertJsonPath('data.tax.card_percentage', 8)
            ->assertJsonPath('data.tax.fbr_number', 'FBR-12345')
            ->assertJsonPath('data.kitchen.order_types', ['DINE_IN', 'TAKEAWAY', 'DELIVERY']);
    }

    // ---- kitchen routing -------------------------------------------------------

    public function test_the_kitchen_only_gets_the_order_types_the_restaurant_chose(): void
    {
        $this->takeaway();
        $this->restaurant->settings->update(['kitchen_order_types' => ['DINE_IN']]);

        $this->assertCount(0, $this->withUserToken($this->kitchenToken)->getJson('/api/v1/kitchen-tickets')->json('data'));

        $this->restaurant->settings->update(['kitchen_order_types' => ['DINE_IN', 'TAKEAWAY']]);
        $this->assertCount(1, $this->withUserToken($this->kitchenToken)->getJson('/api/v1/kitchen-tickets')->json('data'));
    }

    // ---- waiters -------------------------------------------------------------

    public function test_a_waiter_order_is_always_dine_in_even_without_an_order_type(): void
    {
        $response = $this->withUserToken($this->waiterToken)->postJson('/api/v1/orders', [
            'branch_id' => $this->branch->id,
            'table_id' => $this->table->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $this->assertSame('DINE_IN', $response->json('data.order_type'));
    }

    public function test_a_waiter_cannot_place_takeaway_or_delivery(): void
    {
        foreach (['TAKEAWAY', 'DELIVERY'] as $type) {
            $this->withUserToken($this->waiterToken)->postJson('/api/v1/orders', [
                'branch_id' => $this->branch->id,
                'order_type' => $type,
                'delivery_address' => 'Somewhere',
                'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
            ])->assertStatus(422);
        }
    }

    // ---- picked up tab ---------------------------------------------------------

    public function test_picked_up_tickets_are_listed_on_request_and_only_recent_ones(): void
    {
        $order = $this->takeaway();
        $ticket = $this->withUserToken($this->kitchenToken)->getJson('/api/v1/kitchen-tickets')->json('data.0');
        foreach (['CONFIRMED', 'PREPARING', 'READY', 'PICKED_UP'] as $status) {
            $this->withUserToken($this->kitchenToken)->patchJson("/api/v1/kitchen-tickets/{$ticket['id']}/status", ['status' => $status])->assertStatus(200);
        }

        $this->assertCount(0, $this->withUserToken($this->kitchenToken)->getJson('/api/v1/kitchen-tickets')->json('data'));
        $picked = $this->withUserToken($this->kitchenToken)->getJson('/api/v1/kitchen-tickets?status=PICKED_UP')->json('data');
        $this->assertCount(1, $picked);
        $this->assertSame($order['order_number'], $picked[0]['order']['order_number']);

        \App\Models\KitchenTicket::whereKey($ticket['id'])->update(['picked_up_at' => now()->subHours(13)]);
        $this->assertCount(0, $this->withUserToken($this->kitchenToken)->getJson('/api/v1/kitchen-tickets?status=PICKED_UP')->json('data'));
    }
}
