<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use Tests\TestCase;

/**
 * Spec #139 applied to orders: Restaurant A must never see or touch
 * Restaurant B's orders, tables, or the products/branches referenced
 * while placing one — exactly the same guarantee already proven for
 * branches, users, and the menu module.
 */
class OrderIsolationTest extends TestCase
{
    public function test_restaurant_cannot_view_another_restaurants_order(): void
    {
        [$restaurantA, , $ownerA] = $this->makeRestaurantWithOwner('Order A');
        [$restaurantB, $branchB, $ownerB] = $this->makeRestaurantWithOwner('Order B');

        $category = Category::create(['restaurant_id' => $restaurantB->id, 'name' => 'Cat', 'slug' => 'cat-'.uniqid()]);
        $product = Product::create([
            'restaurant_id' => $restaurantB->id, 'category_id' => $category->id,
            'name' => 'Item', 'slug' => 'item-'.uniqid(), 'base_price' => 5,
        ]);

        $tokenB = $this->actingAsUser($ownerB);
        $order = $this->withUserToken($tokenB)->postJson('/api/v1/orders', [
            'branch_id' => $branchB->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201)->json('data');

        $tokenA = $this->actingAsUser($ownerA);
        $this->withUserToken($tokenA)->getJson("/api/v1/orders/{$order['id']}")->assertStatus(404);
    }

    public function test_cannot_place_an_order_against_another_restaurants_branch_or_product(): void
    {
        [$restaurantA, , $ownerA] = $this->makeRestaurantWithOwner('Order A');
        [$restaurantB, $branchB, ] = $this->makeRestaurantWithOwner('Order B');

        $category = Category::create(['restaurant_id' => $restaurantB->id, 'name' => 'Cat', 'slug' => 'cat-'.uniqid()]);
        $product = Product::create([
            'restaurant_id' => $restaurantB->id, 'category_id' => $category->id,
            'name' => 'Item', 'slug' => 'item-'.uniqid(), 'base_price' => 5,
        ]);

        $tokenA = $this->actingAsUser($ownerA);

        // Restaurant A's owner is restaurant-wide *within their own
        // restaurant*, so `branch.access` lets the request through to the
        // controller; the tenant scope on Branch::findOrFail() is what
        // actually stops it — same 404-for-cross-tenant convention as every
        // other isolation test in this suite (see TenantIsolationTest).
        $this->withUserToken($tokenA)->postJson('/api/v1/orders', [
            'branch_id' => $branchB->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(404);
    }

    public function test_order_listing_never_includes_another_restaurants_orders(): void
    {
        [$restaurantA, $branchA, $ownerA] = $this->makeRestaurantWithOwner('Order A');
        [$restaurantB, $branchB, $ownerB] = $this->makeRestaurantWithOwner('Order B');

        $catA = Category::create(['restaurant_id' => $restaurantA->id, 'name' => 'CatA', 'slug' => 'cata-'.uniqid()]);
        $productA = Product::create([
            'restaurant_id' => $restaurantA->id, 'category_id' => $catA->id,
            'name' => 'ItemA', 'slug' => 'itema-'.uniqid(), 'base_price' => 5,
        ]);
        $catB = Category::create(['restaurant_id' => $restaurantB->id, 'name' => 'CatB', 'slug' => 'catb-'.uniqid()]);
        $productB = Product::create([
            'restaurant_id' => $restaurantB->id, 'category_id' => $catB->id,
            'name' => 'ItemB', 'slug' => 'itemb-'.uniqid(), 'base_price' => 5,
        ]);

        $tokenA = $this->actingAsUser($ownerA);
        $orderA = $this->withUserToken($tokenA)->postJson('/api/v1/orders', [
            'branch_id' => $branchA->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $productA->id, 'quantity' => 1]],
        ])->assertStatus(201)->json('data');

        $tokenB = $this->actingAsUser($ownerB);
        $this->withUserToken($tokenB)->postJson('/api/v1/orders', [
            'branch_id' => $branchB->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $productB->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $response = $this->withUserToken($tokenA)->getJson('/api/v1/orders');
        $ids = collect($response->json('data'))->pluck('id');

        $this->assertSame([$orderA['id']], $ids->all());
    }

    /**
     * Regression test: `Customer` is not tenant-scoped by a global scope
     * (see its model docblock — Sanctum has to resolve it before any
     * TenantContext exists, same reasoning as `User`), so
     * `OrderService::createOrder()` must scope the `customer_id` lookup
     * itself rather than relying on one. Before that fix this returned
     * 200 and silently attached restaurant B's customer to restaurant
     * A's order.
     */
    public function test_cannot_attach_another_restaurants_customer_id_to_an_order(): void
    {
        [$restaurantA, $branchA, $ownerA] = $this->makeRestaurantWithOwner('Order A');
        [$restaurantB, , ] = $this->makeRestaurantWithOwner('Order B');

        $customerB = Customer::create([
            'restaurant_id' => $restaurantB->id, 'phone' => '5550777',
            'password' => 'secret123', 'status' => 'ACTIVE',
        ]);

        $category = Category::create(['restaurant_id' => $restaurantA->id, 'name' => 'Cat', 'slug' => 'cat-'.uniqid()]);
        $product = Product::create([
            'restaurant_id' => $restaurantA->id, 'category_id' => $category->id,
            'name' => 'Item', 'slug' => 'item-'.uniqid(), 'base_price' => 5,
        ]);

        $tokenA = $this->actingAsUser($ownerA);

        $this->withUserToken($tokenA)->postJson('/api/v1/orders', [
            'branch_id' => $branchA->id, 'order_type' => 'TAKEAWAY', 'customer_id' => $customerB->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(404);
    }
}
