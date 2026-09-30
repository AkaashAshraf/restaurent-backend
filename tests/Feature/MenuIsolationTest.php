<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Product;
use Tests\TestCase;

/**
 * Spec #139 applied to the menu module: a restaurant's categories,
 * products and modifier groups/modifiers must be exactly as invisible to
 * another restaurant as branches and users already are.
 */
class MenuIsolationTest extends TestCase
{
    public function test_restaurant_cannot_view_another_restaurants_category_or_product(): void
    {
        [$restaurantA, , $ownerA] = $this->makeRestaurantWithOwner('Restaurant A');
        [$restaurantB, , ] = $this->makeRestaurantWithOwner('Restaurant B');

        $categoryB = Category::create(['restaurant_id' => $restaurantB->id, 'name' => 'Secret Menu', 'slug' => 'secret-menu']);
        $productB = Product::create([
            'restaurant_id' => $restaurantB->id, 'category_id' => $categoryB->id,
            'name' => 'Secret Item', 'slug' => 'secret-item', 'base_price' => 5,
        ]);

        $token = $this->actingAsUser($ownerA);

        $this->withUserToken($token)->getJson("/api/v1/categories/{$categoryB->id}")->assertStatus(404);
        $this->withUserToken($token)->getJson("/api/v1/products/{$productB->id}")->assertStatus(404);
    }

    public function test_category_listing_never_includes_another_restaurants_categories(): void
    {
        [$restaurantA, , $ownerA] = $this->makeRestaurantWithOwner('Restaurant A');
        [$restaurantB, , ] = $this->makeRestaurantWithOwner('Restaurant B');

        $categoryA = Category::create(['restaurant_id' => $restaurantA->id, 'name' => 'Mine', 'slug' => 'mine']);
        Category::create(['restaurant_id' => $restaurantB->id, 'name' => 'Theirs', 'slug' => 'theirs']);

        $token = $this->actingAsUser($ownerA);
        $response = $this->withUserToken($token)->getJson('/api/v1/categories');

        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Mine'));
        $this->assertFalse($names->contains('Theirs'));
        $this->assertSame([$categoryA->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_modifier_group_and_modifier_isolation(): void
    {
        [$restaurantA, , $ownerA] = $this->makeRestaurantWithOwner('Restaurant A');
        [$restaurantB, , ] = $this->makeRestaurantWithOwner('Restaurant B');

        $groupB = ModifierGroup::create(['restaurant_id' => $restaurantB->id, 'name' => 'Theirs', 'selection_type' => 'SINGLE']);
        $modifierB = Modifier::create([
            'restaurant_id' => $restaurantB->id, 'modifier_group_id' => $groupB->id,
            'name' => 'Their Modifier', 'price_adjustment' => 1,
        ]);

        $token = $this->actingAsUser($ownerA);

        $this->withUserToken($token)->getJson("/api/v1/modifier-groups/{$groupB->id}")->assertStatus(404);
        $this->withUserToken($token)->patchJson("/api/v1/modifiers/{$modifierB->id}", ['name' => 'Hacked'])->assertStatus(404);
    }
}
