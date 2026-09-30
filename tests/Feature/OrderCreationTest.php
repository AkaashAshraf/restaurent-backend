<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\Table;
use Tests\TestCase;

class OrderCreationTest extends TestCase
{
    private function makeProduct($restaurant, float $price = 10.00): Product
    {
        $category = Category::create(['restaurant_id' => $restaurant->id, 'name' => 'Pizzas', 'slug' => 'pizzas-'.uniqid()]);

        return Product::create([
            'restaurant_id' => $restaurant->id, 'category_id' => $category->id,
            'name' => 'Margherita', 'slug' => 'margherita-'.uniqid(), 'base_price' => $price,
        ]);
    }

    public function test_takeaway_order_computes_subtotal_and_generates_an_order_number(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Order Co');
        $product = $this->makeProduct($restaurant, 10.00);
        $token = $this->actingAsUser($owner);

        $response = $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id,
            'order_type' => 'TAKEAWAY',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertEquals(20.0, $response->json('data.subtotal'));
        $this->assertEquals(20.0, $response->json('data.total_amount'));
        $this->assertSame('PENDING', $response->json('data.status'));
        $this->assertStringStartsWith('ORD-', $response->json('data.order_number'));
    }

    public function test_second_order_gets_the_next_sequence_number(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Order Co');
        $product = $this->makeProduct($restaurant);
        $token = $this->actingAsUser($owner);

        $first = $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $second = $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $this->assertSame('ORD-0001', $first->json('data.order_number'));
        $this->assertSame('ORD-0002', $second->json('data.order_number'));
    }

    public function test_dine_in_order_requires_and_occupies_a_table(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Order Co');
        $product = $this->makeProduct($restaurant);
        $table = Table::create(['restaurant_id' => $restaurant->id, 'branch_id' => $branch->id, 'table_number' => 'T1']);
        $token = $this->actingAsUser($owner);

        // No table_id at all -> rejected.
        $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'DINE_IN',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(422);

        $response = $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'DINE_IN', 'table_id' => $table->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $response->assertStatus(201);
        $this->assertSame('OCCUPIED', $table->fresh()->status->value);

        // A second dine-in order on the same (now occupied) table is rejected.
        $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'DINE_IN', 'table_id' => $table->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(422);
    }

    public function test_delivery_order_requires_an_address_and_applies_fee_and_free_threshold(): void
    {
        // DELIVERY is only enabled on the Premium plan's feature set.
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Order Co', planSlug: 'premium');
        $restaurant->settings->update([
            'order_types' => ['DINE_IN', 'TAKEAWAY', 'DELIVERY'],
            'delivery_fee' => 5.00,
            'free_delivery_threshold' => 50.00,
        ]);
        $product = $this->makeProduct($restaurant, 10.00);
        $token = $this->actingAsUser($owner);

        // Missing delivery_address -> rejected.
        $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'DELIVERY',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(422);

        $small = $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'DELIVERY', 'delivery_address' => '123 Main St',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201);
        $this->assertEquals(5.0, $small->json('data.delivery_fee'));
        $this->assertEquals(15.0, $small->json('data.total_amount'));

        $big = $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'DELIVERY', 'delivery_address' => '123 Main St',
            'items' => [['product_id' => $product->id, 'quantity' => 6]],
        ])->assertStatus(201);
        $this->assertEquals(0.0, $big->json('data.delivery_fee')); // subtotal 60 >= free threshold 50
    }

    public function test_delivery_order_rejected_when_delivery_feature_is_not_on_the_plan(): void
    {
        // Standard plan does not include DELIVERY.
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Order Co', planSlug: 'standard');
        $product = $this->makeProduct($restaurant);
        $token = $this->actingAsUser($owner);

        $response = $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'DELIVERY', 'delivery_address' => 'x',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $response->assertStatus(403);
        $response->assertJson(['code' => 'FEATURE_DISABLED']);
    }

    public function test_order_type_not_in_branch_order_types_is_rejected_even_if_the_feature_is_enabled(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Order Co', planSlug: 'premium');
        // Feature is enabled restaurant-wide, but this branch has narrowed
        // its own accepted order types down to takeaway only.
        $branch->settings()->create(['restaurant_id' => $restaurant->id, 'order_types' => ['TAKEAWAY']]);
        $product = $this->makeProduct($restaurant);
        $token = $this->actingAsUser($owner);

        $response = $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'DELIVERY', 'delivery_address' => 'x',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $response->assertStatus(422);
    }

    public function test_minimum_order_amount_is_enforced(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Order Co');
        $restaurant->settings->update(['min_order_amount' => 50]);
        $product = $this->makeProduct($restaurant, 10.00);
        $token = $this->actingAsUser($owner);

        $response = $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $response->assertStatus(422);
    }

    public function test_tax_is_applied_when_enabled(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Order Co');
        $restaurant->settings->update(['tax_enabled' => true, 'tax_percentage' => 10]);
        $product = $this->makeProduct($restaurant, 100.00);
        $token = $this->actingAsUser($owner);

        $response = $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $response->assertStatus(201);
        $this->assertEquals(10.0, $response->json('data.tax_amount'));
        $this->assertEquals(110.0, $response->json('data.total_amount'));
    }

    public function test_unavailable_product_is_rejected(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Order Co');
        $product = $this->makeProduct($restaurant);
        $product->update(['status' => 'INACTIVE']);
        $token = $this->actingAsUser($owner);

        $response = $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $response->assertStatus(422);
    }

    public function test_product_unavailable_at_branch_via_override_is_rejected(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Order Co');
        $product = $this->makeProduct($restaurant);
        \App\Models\BranchProduct::create([
            'restaurant_id' => $restaurant->id, 'branch_id' => $branch->id,
            'product_id' => $product->id, 'is_available' => false,
        ]);
        $token = $this->actingAsUser($owner);

        $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(422);
    }

    public function test_branch_price_override_is_used_for_line_total(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Order Co');
        $product = $this->makeProduct($restaurant, 10.00);
        \App\Models\BranchProduct::create([
            'restaurant_id' => $restaurant->id, 'branch_id' => $branch->id,
            'product_id' => $product->id, 'is_available' => true, 'price_override' => 7.00,
        ]);
        $token = $this->actingAsUser($owner);

        $response = $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ]);

        $response->assertStatus(201);
        $this->assertEquals(14.0, $response->json('data.subtotal'));
    }

    public function test_modifier_selection_is_validated_and_priced(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Order Co');
        $product = $this->makeProduct($restaurant, 10.00);
        $size = ModifierGroup::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Size', 'selection_type' => 'SINGLE', 'is_required' => true,
        ]);
        $small = $size->modifiers()->create(['restaurant_id' => $restaurant->id, 'name' => 'Small', 'price_adjustment' => 0]);
        $large = $size->modifiers()->create(['restaurant_id' => $restaurant->id, 'name' => 'Large', 'price_adjustment' => 3]);
        $product->modifierGroups()->attach($size->id);
        $token = $this->actingAsUser($owner);

        // Required group with nothing selected -> rejected.
        $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(422);

        // Two selections on a SINGLE group -> rejected.
        $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'modifier_ids' => [$small->id, $large->id]]],
        ])->assertStatus(422);

        $response = $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'modifier_ids' => [$large->id]]],
        ]);
        $response->assertStatus(201);
        // (10 base + 3 modifier) * 2 = 26
        $this->assertEquals(26.0, $response->json('data.subtotal'));
    }

    public function test_modifier_from_an_unattached_group_is_rejected(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Order Co');
        $product = $this->makeProduct($restaurant);
        $group = ModifierGroup::create(['restaurant_id' => $restaurant->id, 'name' => 'Toppings', 'selection_type' => 'MULTIPLE']);
        $modifier = $group->modifiers()->create(['restaurant_id' => $restaurant->id, 'name' => 'Cheese', 'price_adjustment' => 1]);
        // Deliberately not attached to $product.
        $token = $this->actingAsUser($owner);

        $this->withUserToken($token)->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'modifier_ids' => [$modifier->id]]],
        ])->assertStatus(422);
    }
}
