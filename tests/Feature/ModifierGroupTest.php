<?php

namespace Tests\Feature;

use App\Models\ModifierGroup;
use Tests\TestCase;

class ModifierGroupTest extends TestCase
{
    public function test_owner_can_create_a_modifier_group_and_add_modifiers_to_it(): void
    {
        [, , $owner] = $this->makeRestaurantWithOwner('Menu Co');
        $token = $this->actingAsUser($owner);

        $group = $this->withUserToken($token)->postJson('/api/v1/modifier-groups', [
            'name' => 'Size',
            'selection_type' => 'SINGLE',
            'is_required' => true,
            'min_selections' => 1,
            'max_selections' => 1,
        ])->assertStatus(201)->json('data');

        $small = $this->withUserToken($token)->postJson("/api/v1/modifier-groups/{$group['id']}/modifiers", [
            'name' => 'Small', 'price_adjustment' => 0, 'is_default' => true,
        ])->assertStatus(201);

        $large = $this->withUserToken($token)->postJson("/api/v1/modifier-groups/{$group['id']}/modifiers", [
            'name' => 'Large', 'price_adjustment' => 3.00,
        ])->assertStatus(201);

        $show = $this->withUserToken($token)->getJson("/api/v1/modifier-groups/{$group['id']}");
        $show->assertOk()->assertJsonCount(2, 'data.modifiers');
    }

    public function test_updating_and_deleting_a_modifier(): void
    {
        [$restaurant, , $owner] = $this->makeRestaurantWithOwner('Menu Co');
        $group = ModifierGroup::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Toppings',
            'selection_type' => 'MULTIPLE',
        ]);
        $modifier = $group->modifiers()->create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Extra Cheese',
            'price_adjustment' => 1.5,
        ]);

        $token = $this->actingAsUser($owner);

        $this->withUserToken($token)->patchJson("/api/v1/modifiers/{$modifier->id}", [
            'price_adjustment' => 2.0,
        ])->assertOk()->assertJsonPath('data.price_adjustment', '2.00');

        $this->withUserToken($token)->deleteJson("/api/v1/modifiers/{$modifier->id}")->assertOk();
        $this->assertSoftDeleted('modifiers', ['id' => $modifier->id]);
    }

    public function test_kitchen_staff_cannot_create_a_modifier_group(): void
    {
        [$restaurant, $branch, ] = $this->makeRestaurantWithOwner('Menu Co');
        $kitchen = $this->makeBranchScopedUser($restaurant, $branch, 'kitchen');
        $token = $this->actingAsUser($kitchen);

        $this->withUserToken($token)->postJson('/api/v1/modifier-groups', [
            'name' => 'Size', 'selection_type' => 'SINGLE',
        ])->assertStatus(403);
    }
}
