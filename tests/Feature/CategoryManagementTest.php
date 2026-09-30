<?php

namespace Tests\Feature;

use App\Models\Category;
use Tests\TestCase;

class CategoryManagementTest extends TestCase
{
    public function test_owner_can_create_a_category(): void
    {
        [, , $owner] = $this->makeRestaurantWithOwner('Menu Co');
        $token = $this->actingAsUser($owner);

        $response = $this->withUserToken($token)->postJson('/api/v1/categories', [
            'name' => 'Pizzas',
            'description' => 'Wood-fired pizzas',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.slug', 'pizzas');
        $response->assertJsonPath('data.status', 'ACTIVE');
    }

    public function test_creating_a_second_category_with_the_same_name_gets_a_unique_slug(): void
    {
        [, , $owner] = $this->makeRestaurantWithOwner('Menu Co');
        $token = $this->actingAsUser($owner);

        $this->withUserToken($token)->postJson('/api/v1/categories', ['name' => 'Drinks'])->assertStatus(201);
        $second = $this->withUserToken($token)->postJson('/api/v1/categories', ['name' => 'Drinks'])->assertStatus(201);

        $this->assertSame('drinks-1', $second->json('data.slug'));
    }

    public function test_owner_can_list_update_and_delete_a_category(): void
    {
        [$restaurant, , $owner] = $this->makeRestaurantWithOwner('Menu Co');
        $token = $this->actingAsUser($owner);

        $category = Category::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Starters',
            'slug' => 'starters',
        ]);

        $this->withUserToken($token)->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->withUserToken($token)->patchJson("/api/v1/categories/{$category->id}", [
            'status' => 'INACTIVE',
        ])->assertOk()->assertJsonPath('data.status', 'INACTIVE');

        $this->withUserToken($token)->deleteJson("/api/v1/categories/{$category->id}")
            ->assertOk()->assertJsonPath('data.deleted', true);

        $this->assertSoftDeleted('categories', ['id' => $category->id]);
    }

    public function test_waiter_can_view_categories_but_not_create_one(): void
    {
        [$restaurant, $branch, ] = $this->makeRestaurantWithOwner('Menu Co');
        $waiter = $this->makeBranchScopedUser($restaurant, $branch, 'waiter');
        $token = $this->actingAsUser($waiter);

        $this->withUserToken($token)->getJson('/api/v1/categories')->assertOk();

        $this->withUserToken($token)->postJson('/api/v1/categories', ['name' => 'Desserts'])
            ->assertStatus(403);
    }
}
