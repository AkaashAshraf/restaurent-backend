<?php

namespace Tests\Feature;

use App\Models\BranchProduct;
use App\Models\Category;
use App\Models\ModifierGroup;
use App\Models\Product;
use Tests\TestCase;

class PublicMenuEndpointTest extends TestCase
{
    public function test_public_menu_resolves_by_slug_and_includes_categories_products_and_modifiers(): void
    {
        [$restaurant, , ] = $this->makeRestaurantWithOwner('Menu Co');

        $category = Category::create(['restaurant_id' => $restaurant->id, 'name' => 'Pizzas', 'slug' => 'pizzas']);
        $product = Product::create([
            'restaurant_id' => $restaurant->id, 'category_id' => $category->id,
            'name' => 'Margherita', 'slug' => 'margherita', 'base_price' => 8.50,
        ]);
        $group = ModifierGroup::create(['restaurant_id' => $restaurant->id, 'name' => 'Size', 'selection_type' => 'SINGLE']);
        $group->modifiers()->create(['restaurant_id' => $restaurant->id, 'name' => 'Large', 'price_adjustment' => 3]);
        $product->modifierGroups()->attach($group->id);

        $response = $this->getJson("/api/v1/app/menu?restaurant={$restaurant->slug}");

        $response->assertOk();
        $categories = $response->json('data.categories');
        $this->assertSame('Pizzas', $categories[0]['name']);
        $this->assertSame('Margherita', $categories[0]['products'][0]['name']);
        $this->assertSame(8.5, $categories[0]['products'][0]['price']);
        $this->assertSame('Size', $categories[0]['products'][0]['modifier_groups'][0]['name']);
        $this->assertSame('Large', $categories[0]['products'][0]['modifier_groups'][0]['modifiers'][0]['name']);
    }

    public function test_public_menu_applies_branch_price_and_availability_overrides(): void
    {
        [$restaurant, $branch, ] = $this->makeRestaurantWithOwner('Menu Co');

        $category = Category::create(['restaurant_id' => $restaurant->id, 'name' => 'Pizzas', 'slug' => 'pizzas']);
        $available = Product::create([
            'restaurant_id' => $restaurant->id, 'category_id' => $category->id,
            'name' => 'Margherita', 'slug' => 'margherita', 'base_price' => 8.50,
        ]);
        $hidden = Product::create([
            'restaurant_id' => $restaurant->id, 'category_id' => $category->id,
            'name' => 'Pepperoni', 'slug' => 'pepperoni', 'base_price' => 9.50,
        ]);

        BranchProduct::create([
            'restaurant_id' => $restaurant->id, 'branch_id' => $branch->id,
            'product_id' => $available->id, 'is_available' => true, 'price_override' => 7.00,
        ]);
        BranchProduct::create([
            'restaurant_id' => $restaurant->id, 'branch_id' => $branch->id,
            'product_id' => $hidden->id, 'is_available' => false,
        ]);

        $response = $this->getJson("/api/v1/app/menu?restaurant={$restaurant->slug}&branch={$branch->id}");

        $response->assertOk();
        $products = collect($response->json('data.categories.0.products'))->keyBy('name');

        $this->assertEquals(7.0, $products['Margherita']['price']);
        $this->assertFalse($products->has('Pepperoni')); // filtered out: unavailable at this branch
    }

    public function test_public_menu_for_unknown_restaurant_returns_not_found(): void
    {
        $response = $this->getJson('/api/v1/app/menu?restaurant=does-not-exist');

        $response->assertStatus(404);
        $response->assertJson(['code' => 'NOT_FOUND']);
    }

    public function test_public_menu_never_leaks_a_different_restaurants_categories(): void
    {
        [$restaurantA, , ] = $this->makeRestaurantWithOwner('Menu A');
        [$restaurantB, , ] = $this->makeRestaurantWithOwner('Menu B');

        Category::create(['restaurant_id' => $restaurantA->id, 'name' => 'A Category', 'slug' => 'a-category']);
        Category::create(['restaurant_id' => $restaurantB->id, 'name' => 'B Category', 'slug' => 'b-category']);

        $response = $this->getJson("/api/v1/app/menu?restaurant={$restaurantA->slug}");

        $names = collect($response->json('data.categories'))->pluck('name');
        $this->assertTrue($names->contains('A Category'));
        $this->assertFalse($names->contains('B Category'));
    }
}
