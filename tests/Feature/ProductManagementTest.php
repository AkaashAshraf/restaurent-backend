<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Tests\TestCase;

class ProductManagementTest extends TestCase
{
    private function makeCategory($restaurant, string $name = 'Pizzas'): Category
    {
        return Category::create([
            'restaurant_id' => $restaurant->id,
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name).'-'.uniqid(),
        ]);
    }

    public function test_owner_can_create_a_product_under_a_category(): void
    {
        [$restaurant, , $owner] = $this->makeRestaurantWithOwner('Menu Co');
        $category = $this->makeCategory($restaurant);
        $token = $this->actingAsUser($owner);

        $response = $this->withUserToken($token)->postJson('/api/v1/products', [
            'category_id' => $category->id,
            'name' => 'Margherita',
            'base_price' => 8.50,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.category_id', $category->id);
        $response->assertJsonPath('data.status', 'ACTIVE');
    }

    public function test_cannot_create_a_product_under_another_restaurants_category(): void
    {
        [$restaurantA, , $ownerA] = $this->makeRestaurantWithOwner('Restaurant A');
        [$restaurantB, , ] = $this->makeRestaurantWithOwner('Restaurant B');
        $categoryB = $this->makeCategory($restaurantB);

        $token = $this->actingAsUser($ownerA);

        $response = $this->withUserToken($token)->postJson('/api/v1/products', [
            'category_id' => $categoryB->id,
            'name' => 'Smuggled Item',
            'base_price' => 5,
        ]);

        // The tenant scope on Category means findOrFail() 404s — Restaurant
        // A's owner can't even see that this category exists, let alone
        // attach a product to it.
        $response->assertStatus(404);
    }

    public function test_owner_can_update_and_delete_a_product(): void
    {
        [$restaurant, , $owner] = $this->makeRestaurantWithOwner('Menu Co');
        $category = $this->makeCategory($restaurant);
        $product = Product::create([
            'restaurant_id' => $restaurant->id,
            'category_id' => $category->id,
            'name' => 'Margherita',
            'slug' => 'margherita',
            'base_price' => 8.50,
        ]);

        $token = $this->actingAsUser($owner);

        $this->withUserToken($token)->patchJson("/api/v1/products/{$product->id}", [
            'base_price' => 9.00,
        ])->assertOk()->assertJsonPath('data.base_price', '9.00');

        $this->withUserToken($token)->deleteJson("/api/v1/products/{$product->id}")
            ->assertOk();

        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }

    public function test_syncing_modifier_groups_drops_ids_belonging_to_another_restaurant(): void
    {
        [$restaurantA, , $ownerA] = $this->makeRestaurantWithOwner('Restaurant A');
        [$restaurantB, , ] = $this->makeRestaurantWithOwner('Restaurant B');
        $categoryA = $this->makeCategory($restaurantA);

        $product = Product::create([
            'restaurant_id' => $restaurantA->id,
            'category_id' => $categoryA->id,
            'name' => 'Pizza',
            'slug' => 'pizza',
            'base_price' => 10,
        ]);

        $groupA = \App\Models\ModifierGroup::create([
            'restaurant_id' => $restaurantA->id,
            'name' => 'Size',
            'selection_type' => 'SINGLE',
        ]);
        $groupB = \App\Models\ModifierGroup::create([
            'restaurant_id' => $restaurantB->id,
            'name' => 'Foreign Group',
            'selection_type' => 'SINGLE',
        ]);

        $token = $this->actingAsUser($ownerA);

        $response = $this->withUserToken($token)->patchJson("/api/v1/products/{$product->id}/modifier-groups", [
            'modifier_group_ids' => [$groupA->id, $groupB->id],
        ]);

        $response->assertOk();
        $ids = collect($response->json('data.modifier_groups'))->pluck('id');
        $this->assertTrue($ids->contains($groupA->id));
        $this->assertFalse($ids->contains($groupB->id));
    }

    public function test_branch_manager_can_set_a_branch_price_override_for_their_own_branch(): void
    {
        [$restaurant, $branch, ] = $this->makeRestaurantWithOwner('Menu Co');
        $category = $this->makeCategory($restaurant);
        $product = Product::create([
            'restaurant_id' => $restaurant->id,
            'category_id' => $category->id,
            'name' => 'Margherita',
            'slug' => 'margherita',
            'base_price' => 8.50,
        ]);

        $manager = $this->makeBranchScopedUser($restaurant, $branch, 'branch-manager');
        $token = $this->actingAsUser($manager);

        $response = $this->withUserToken($token)->patchJson("/api/v1/products/{$product->id}/branches/{$branch->id}", [
            'is_available' => false,
            'price_override' => 7.00,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('branch_products', [
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'is_available' => false,
        ]);
    }

    public function test_branch_manager_cannot_set_an_override_for_a_branch_they_are_not_assigned_to(): void
    {
        [$restaurant, $branchA, ] = $this->makeRestaurantWithOwner('Menu Co');
        $category = $this->makeCategory($restaurant);
        $product = Product::create([
            'restaurant_id' => $restaurant->id,
            'category_id' => $category->id,
            'name' => 'Margherita',
            'slug' => 'margherita',
            'base_price' => 8.50,
        ]);

        $branchB = \App\Models\Branch::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Second Branch',
            'branch_code' => 'SEC',
            'status' => 'ACTIVE',
        ]);

        $manager = $this->makeBranchScopedUser($restaurant, $branchA, 'branch-manager');
        $token = $this->actingAsUser($manager);

        $response = $this->withUserToken($token)->patchJson("/api/v1/products/{$product->id}/branches/{$branchB->id}", [
            'is_available' => false,
        ]);

        $response->assertStatus(403);
    }
}
