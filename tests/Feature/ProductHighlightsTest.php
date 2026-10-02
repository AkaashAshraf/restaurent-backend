<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Tests\TestCase;

/** "Most selling" (public, per branch) and "products you like" (the customer's own history). */
class ProductHighlightsTest extends TestCase
{
    private function product($restaurant, string $name, float $price = 5): Product
    {
        $category = Category::create(['restaurant_id' => $restaurant->id, 'name' => 'Mains', 'slug' => 'mains-'.uniqid()]);

        return Product::create([
            'restaurant_id' => $restaurant->id, 'category_id' => $category->id,
            'name' => $name, 'slug' => \Illuminate\Support\Str::slug($name).'-'.uniqid(), 'base_price' => $price,
        ]);
    }

    private function customer($restaurant, string $phone): string
    {
        return $this->postJson("/api/v1/app/auth/register?restaurant={$restaurant->slug}", [
            'phone' => $phone, 'password' => 'secret123',
        ])->assertStatus(201)->json('data.token');
    }

    private function order(string $token, $branch, array $items)
    {
        return $this->withUserToken($token)->postJson('/api/v1/customer/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => array_map(fn ($p, $q) => ['product_id' => $p->id, 'quantity' => $q], array_keys($items) ? array_column($items, 0) : [], array_column($items, 1)),
        ])->assertStatus(201)->json('data');
    }

    public function test_best_sellers_rank_by_units_ignore_cancelled_orders_and_old_ones(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Best Co', planSlug: 'premium');
        $pizza = $this->product($restaurant, 'Pizza');
        $pasta = $this->product($restaurant, 'Pasta');
        $soup = $this->product($restaurant, 'Soup');
        $a = $this->customer($restaurant, '5554001');
        $b = $this->customer($restaurant, '5554002');

        $this->order($a, $branch, [[$pizza, 1], [$pasta, 3]]);
        $this->order($b, $branch, [[$pizza, 1], [$pasta, 1]]);
        $cancelled = $this->order($b, $branch, [[$soup, 20]]);
        \App\Models\Order::withoutGlobalScopes()->where('id', $cancelled['id'])->update(['status' => 'CANCELLED']);
        $old = $this->order($a, $branch, [[$soup, 30]]);
        \App\Models\Order::withoutGlobalScopes()->where('id', $old['id'])->update(['created_at' => now()->subDays(90)]);

        $res = $this->getJson("/api/v1/app/best-sellers?restaurant={$restaurant->slug}&branch={$branch->id}")->assertOk();
        $this->assertSame([$pasta->id, $pizza->id], $res->json('data.product_ids'));
    }

    public function test_best_sellers_are_for_the_one_restaurant_and_branch(): void
    {
        [$restaurant, $branch] = $this->makeRestaurantWithOwner('Best Co', planSlug: 'premium');
        [$other, $otherBranch] = $this->makeRestaurantWithOwner('Rival Co', planSlug: 'premium');
        $pizza = $this->product($restaurant, 'Pizza');
        $taco = $this->product($other, 'Taco');
        $this->order($this->customer($restaurant, '5554011'), $branch, [[$pizza, 2]]);
        $this->order($this->customer($other, '5554012'), $otherBranch, [[$taco, 9]]);

        $this->getJson("/api/v1/app/best-sellers?restaurant={$restaurant->slug}")->assertOk()->assertJsonPath('data.product_ids', [$pizza->id]);
        $this->getJson("/api/v1/app/best-sellers?restaurant={$restaurant->slug}&branch={$otherBranch->id}")->assertOk()->assertJsonPath('data.product_ids', []);
        $this->getJson("/api/v1/app/best-sellers?restaurant={$other->slug}")->assertOk()->assertJsonPath('data.product_ids', [$taco->id]);
    }

    public function test_favorites_are_only_this_customers_most_ordered_products(): void
    {
        [$restaurant, $branch] = $this->makeRestaurantWithOwner('Fav Co', planSlug: 'premium');
        $pizza = $this->product($restaurant, 'Pizza');
        $pasta = $this->product($restaurant, 'Pasta');
        $soup = $this->product($restaurant, 'Soup');
        $mine = $this->customer($restaurant, '5554021');
        $theirs = $this->customer($restaurant, '5554022');

        $this->order($mine, $branch, [[$pasta, 1]]);
        $this->order($mine, $branch, [[$pizza, 2], [$pasta, 2]]);
        $this->order($theirs, $branch, [[$soup, 50]]);

        $this->withUserToken($mine)->getJson('/api/v1/customer/favorites')
            ->assertOk()->assertJsonPath('data.product_ids', [$pasta->id, $pizza->id]);
        $this->withUserToken($theirs)->getJson('/api/v1/customer/favorites')
            ->assertOk()->assertJsonPath('data.product_ids', [$soup->id]);

        // A new customer has none yet; a signed-out request is refused.
        $this->withUserToken($this->customer($restaurant, '5554023'))->getJson('/api/v1/customer/favorites')
            ->assertOk()->assertJsonPath('data.product_ids', []);
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', '')->getJson('/api/v1/customer/favorites')->assertStatus(401);
    }
}
