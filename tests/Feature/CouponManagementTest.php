<?php

namespace Tests\Feature;

use App\Models\Coupon;
use Tests\TestCase;

class CouponManagementTest extends TestCase
{
    public function test_owner_can_create_view_update_and_delete_a_coupon(): void
    {
        [$restaurant, , $owner] = $this->makeRestaurantWithOwner('Coupon Co');
        $token = $this->actingAsUser($owner);

        $created = $this->withUserToken($token)->postJson('/api/v1/coupons', [
            'code' => 'welcome10', 'type' => 'PERCENTAGE', 'value' => 10,
        ])->assertStatus(201)->json('data');

        // Codes are normalized to uppercase regardless of how they were typed.
        $this->assertSame('WELCOME10', $created['code']);
        $this->assertTrue($created['is_active']);

        $this->withUserToken($token)->getJson("/api/v1/coupons/{$created['id']}")
            ->assertOk()->assertJsonPath('data.code', 'WELCOME10');

        $this->withUserToken($token)->patchJson("/api/v1/coupons/{$created['id']}", [
            'is_active' => false,
        ])->assertOk()->assertJsonPath('data.is_active', false);

        $this->withUserToken($token)->deleteJson("/api/v1/coupons/{$created['id']}")->assertOk();
        $this->assertNull(Coupon::find($created['id']));
    }

    public function test_duplicate_coupon_code_is_rejected_for_the_same_restaurant(): void
    {
        [$restaurant, , $owner] = $this->makeRestaurantWithOwner('Coupon Co');
        $token = $this->actingAsUser($owner);

        $this->withUserToken($token)->postJson('/api/v1/coupons', [
            'code' => 'SAVE5', 'type' => 'FIXED', 'value' => 5,
        ])->assertStatus(201);

        $this->withUserToken($token)->postJson('/api/v1/coupons', [
            'code' => 'save5', 'type' => 'FIXED', 'value' => 5,
        ])->assertStatus(422)->assertJson(['code' => 'VALIDATION_ERROR']);
    }

    public function test_the_same_code_is_reusable_across_different_restaurants(): void
    {
        [$restaurantA, , $ownerA] = $this->makeRestaurantWithOwner('Coupon A');
        [$restaurantB, , $ownerB] = $this->makeRestaurantWithOwner('Coupon B');

        $this->withUserToken($this->actingAsUser($ownerA))->postJson('/api/v1/coupons', [
            'code' => 'SAVE5', 'type' => 'FIXED', 'value' => 5,
        ])->assertStatus(201);

        $this->withUserToken($this->actingAsUser($ownerB))->postJson('/api/v1/coupons', [
            'code' => 'SAVE5', 'type' => 'FIXED', 'value' => 5,
        ])->assertStatus(201);
    }

    public function test_percentage_coupon_value_over_100_is_rejected(): void
    {
        [, , $owner] = $this->makeRestaurantWithOwner('Coupon Co');
        $token = $this->actingAsUser($owner);

        $this->withUserToken($token)->postJson('/api/v1/coupons', [
            'code' => 'TOOMUCH', 'type' => 'PERCENTAGE', 'value' => 150,
        ])->assertStatus(422);
    }

    public function test_managing_coupons_requires_the_coupons_feature(): void
    {
        // Basic plan does not include COUPONS.
        [, , $owner] = $this->makeRestaurantWithOwner('Coupon Co', planSlug: 'basic');
        $token = $this->actingAsUser($owner);

        $this->withUserToken($token)->postJson('/api/v1/coupons', [
            'code' => 'WELCOME10', 'type' => 'PERCENTAGE', 'value' => 10,
        ])->assertStatus(403)->assertJson(['code' => 'FEATURE_DISABLED']);
    }

    public function test_branch_manager_can_view_coupons_but_not_create_one(): void
    {
        [$restaurant, $branch, ] = $this->makeRestaurantWithOwner('Coupon Co');
        $manager = $this->makeBranchScopedUser($restaurant, $branch);
        $token = $this->actingAsUser($manager);

        $this->withUserToken($token)->getJson('/api/v1/coupons')->assertOk();

        $this->withUserToken($token)->postJson('/api/v1/coupons', [
            'code' => 'WELCOME10', 'type' => 'PERCENTAGE', 'value' => 10,
        ])->assertStatus(403)->assertJson(['code' => 'FORBIDDEN']);
    }

    public function test_cannot_view_another_restaurants_coupon(): void
    {
        [$restaurantA, , $ownerA] = $this->makeRestaurantWithOwner('Coupon A');
        [$restaurantB, , $ownerB] = $this->makeRestaurantWithOwner('Coupon B');

        $couponB = $this->withUserToken($this->actingAsUser($ownerB))->postJson('/api/v1/coupons', [
            'code' => 'BONLY', 'type' => 'FIXED', 'value' => 5,
        ])->assertStatus(201)->json('data');

        $this->withUserToken($this->actingAsUser($ownerA))
            ->getJson("/api/v1/coupons/{$couponB['id']}")->assertStatus(404);
    }
}
