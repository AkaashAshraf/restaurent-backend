<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use Tests\TestCase;

/** Customers rate a completed order (food, rider, app); staff read the reviews. */
class OrderReviewTest extends TestCase
{
    private function setupShop(): array
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Review Co', planSlug: 'premium');
        $restaurant->settings->update(['order_types' => ['DINE_IN', 'TAKEAWAY', 'DELIVERY'], 'customer_order_types' => ['DELIVERY', 'TAKEAWAY']]);
        $category = Category::create(['restaurant_id' => $restaurant->id, 'name' => 'Mains', 'slug' => 'mains-'.uniqid()]);
        $product = Product::create([
            'restaurant_id' => $restaurant->id, 'category_id' => $category->id,
            'name' => 'Karahi', 'slug' => 'karahi-'.uniqid(), 'base_price' => 10, 'image' => 'https://example.test/karahi.jpg',
        ]);

        return [$restaurant, $branch, $owner, $product];
    }

    private function customer($restaurant, string $phone): string
    {
        return $this->postJson("/api/v1/app/auth/register?restaurant={$restaurant->slug}", [
            'phone' => $phone, 'password' => 'secret123',
        ])->assertStatus(201)->json('data.token');
    }

    private function order(string $token, $branch, $product, string $type = 'TAKEAWAY', bool $complete = true): int
    {
        $payload = ['branch_id' => $branch->id, 'order_type' => $type, 'items' => [['product_id' => $product->id, 'quantity' => 1]]];
        if ($type === 'DELIVERY') {
            $payload['delivery_address'] = '1 Main St';
        }
        $id = $this->withUserToken($token)->postJson('/api/v1/customer/orders', $payload)->assertStatus(201)->json('data.id');
        if ($complete) {
            Order::withoutGlobalScopes()->where('id', $id)->update(['status' => 'COMPLETED']);
        }

        return $id;
    }

    public function test_only_a_completed_order_can_be_reviewed(): void
    {
        [$restaurant, $branch, , $product] = $this->setupShop();
        $token = $this->customer($restaurant, '5557001');
        $open = $this->order($token, $branch, $product, complete: false);

        $this->withUserToken($token)->postJson("/api/v1/customer/orders/{$open}/review", ['food_rating' => 5])->assertStatus(422);
    }

    public function test_a_delivery_order_takes_food_rider_and_app_ratings_and_can_be_edited(): void
    {
        [$restaurant, $branch, , $product] = $this->setupShop();
        $token = $this->customer($restaurant, '5557002');
        $id = $this->order($token, $branch, $product, 'DELIVERY');

        $this->withUserToken($token)->postJson("/api/v1/customer/orders/{$id}/review", [
            'food_rating' => 5, 'food_review' => 'Hot and tasty', 'rider_rating' => 4, 'rider_review' => 'Polite', 'app_rating' => 3,
        ])->assertStatus(201)->assertJsonPath('data.rider_rating', 4);

        // The order now shows its review, and a second post edits it instead of adding another.
        $this->withUserToken($token)->postJson("/api/v1/customer/orders/{$id}/review", ['food_rating' => 4])->assertOk();
        $this->assertSame(1, \App\Models\OrderReview::withoutGlobalScopes()->where('order_id', $id)->count());
        $this->withUserToken($token)->getJson("/api/v1/customer/orders/{$id}")->assertOk()
            ->assertJsonPath('data.review.food_rating', 4);
    }

    public function test_a_takeaway_order_ignores_the_rider_rating(): void
    {
        [$restaurant, $branch, , $product] = $this->setupShop();
        $token = $this->customer($restaurant, '5557003');
        $id = $this->order($token, $branch, $product);

        $this->withUserToken($token)->postJson("/api/v1/customer/orders/{$id}/review", ['food_rating' => 5, 'rider_rating' => 1])
            ->assertStatus(201)->assertJsonPath('data.rider_rating', null);
    }

    public function test_at_least_one_rating_is_needed_and_stars_are_one_to_five(): void
    {
        [$restaurant, $branch, , $product] = $this->setupShop();
        $token = $this->customer($restaurant, '5557004');
        $id = $this->order($token, $branch, $product);

        $this->withUserToken($token)->postJson("/api/v1/customer/orders/{$id}/review", ['food_review' => 'only words'])->assertStatus(422);
        $this->withUserToken($token)->postJson("/api/v1/customer/orders/{$id}/review", ['food_rating' => 6])->assertStatus(422);
    }

    public function test_you_cannot_review_someone_elses_order(): void
    {
        [$restaurant, $branch, , $product] = $this->setupShop();
        $mine = $this->customer($restaurant, '5557005');
        $theirs = $this->customer($restaurant, '5557006');
        $id = $this->order($mine, $branch, $product);

        $this->withUserToken($theirs)->postJson("/api/v1/customer/orders/{$id}/review", ['food_rating' => 5])->assertStatus(404);
    }

    public function test_the_order_list_carries_dish_photos_and_the_review(): void
    {
        [$restaurant, $branch, , $product] = $this->setupShop();
        $token = $this->customer($restaurant, '5557007');
        $id = $this->order($token, $branch, $product);

        $this->withUserToken($token)->getJson('/api/v1/customer/orders')->assertOk()
            ->assertJsonPath('data.0.items.0.product.image', 'https://example.test/karahi.jpg')
            ->assertJsonPath('data.0.review', null);
    }

    public function test_staff_see_reviews_with_averages(): void
    {
        [$restaurant, $branch, $owner, $product] = $this->setupShop();
        $a = $this->customer($restaurant, '5557008');
        $b = $this->customer($restaurant, '5557009');
        $this->withUserToken($a)->postJson('/api/v1/customer/orders/'.$this->order($a, $branch, $product).'/review', ['food_rating' => 5, 'app_rating' => 4])->assertStatus(201);
        $this->withUserToken($b)->postJson('/api/v1/customer/orders/'.$this->order($b, $branch, $product, 'DELIVERY').'/review', ['food_rating' => 3, 'rider_rating' => 2, 'food_review' => 'Cold'])->assertStatus(201);

        $res = $this->withUserToken($this->actingAsUser($owner))->getJson('/api/v1/reviews')->assertOk();
        $res->assertJsonPath('data.summary.total', 2)
            ->assertJsonPath('data.summary.food.average', 4)
            ->assertJsonPath('data.summary.food.count', 2)
            ->assertJsonPath('data.summary.rider.average', 2)
            ->assertJsonPath('data.summary.app.count', 1);
        $this->assertCount(2, $res->json('data.reviews'));

        $low = $this->withUserToken($this->actingAsUser($owner))->getJson('/api/v1/reviews?max_rating=3')->assertOk();
        $this->assertCount(1, $low->json('data.reviews'));
        $this->assertSame('Cold', $low->json('data.reviews.0.food_review'));
    }
}
