<?php

namespace Tests\Feature;

use App\Models\BranchProduct;
use App\Models\Category;
use App\Models\ModifierGroup;
use App\Models\Product;
use Tests\TestCase;

/**
 * Combo deals: the restaurant builds them, the customer app lists the running
 * ones, and ordering a deal produces ordinary order lines at the deal price.
 */
class DealTest extends TestCase
{
    private function product($restaurant, string $name, float $price): Product
    {
        $category = Category::create(['restaurant_id' => $restaurant->id, 'name' => 'Mains', 'slug' => 'mains-'.uniqid()]);

        return Product::create([
            'restaurant_id' => $restaurant->id, 'category_id' => $category->id,
            'name' => $name, 'slug' => \Illuminate\Support\Str::slug($name).'-'.uniqid(), 'base_price' => $price,
        ]);
    }

    private function customerToken($restaurant, string $phone = '5553000'): string
    {
        return $this->postJson("/api/v1/app/auth/register?restaurant={$restaurant->slug}", [
            'phone' => $phone, 'password' => 'secret123',
        ])->assertStatus(201)->json('data.token');
    }

    /** @return array{0: mixed, 1: mixed, 2: string, 3: Product, 4: Product} */
    private function setup3(string $plan = 'premium'): array
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Deal Co', planSlug: $plan);
        $burger = $this->product($restaurant, 'Burger', 10);
        $fries = $this->product($restaurant, 'Fries', 5);

        return [$restaurant, $branch, $this->actingAsUser($owner), $burger, $fries];
    }

    private function createDeal(string $token, Product $a, Product $b, array $extra = []): array
    {
        return $this->withUserToken($token)->postJson('/api/v1/deals', $extra + [
            'name' => 'Burger Combo', 'description' => 'Burger + fries', 'price' => 12,
            'items' => [['product_id' => $a->id, 'quantity' => 1], ['product_id' => $b->id, 'quantity' => 2]],
        ])->assertStatus(201)->json('data');
    }

    public function test_owner_creates_lists_updates_and_deletes_a_deal(): void
    {
        [, , $token, $burger, $fries] = $this->setup3();

        $deal = $this->createDeal($token, $burger, $fries);
        $this->assertSame('LIVE', $deal['state']);
        $this->assertEquals(20.0, $deal['original_price']);
        $this->assertEquals(12.0, $deal['price']);
        $this->assertCount(2, $deal['items']);

        $this->withUserToken($token)->getJson('/api/v1/deals')->assertOk()->assertJsonCount(1, 'data');

        $this->withUserToken($token)->patchJson("/api/v1/deals/{$deal['id']}", ['status' => 'INACTIVE', 'price' => 11])
            ->assertOk()->assertJsonPath('data.state', 'PAUSED')->assertJsonPath('data.price', 11);

        $this->withUserToken($token)->patchJson("/api/v1/deals/{$deal['id']}", [
            'items' => [['product_id' => $burger->id, 'quantity' => 3]],
        ])->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.original_price', 30);

        $this->withUserToken($token)->deleteJson("/api/v1/deals/{$deal['id']}")->assertOk();
        $this->withUserToken($token)->getJson('/api/v1/deals')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_deal_states_follow_its_dates(): void
    {
        [, , $token, $burger, $fries] = $this->setup3();

        $future = $this->createDeal($token, $burger, $fries, ['starts_on' => now()->addDays(3)->toDateString()]);
        $past = $this->createDeal($token, $burger, $fries, ['starts_on' => now()->subDays(9)->toDateString(), 'ends_on' => now()->subDay()->toDateString()]);

        $this->assertSame('SCHEDULED', $future['state']);
        $this->assertSame('ENDED', $past['state']);
        $this->assertFalse($future['is_running']);
    }

    public function test_validation_rejects_empty_deals_bad_dates_and_foreign_or_option_products(): void
    {
        [$restaurant, , $token, $burger, $fries] = $this->setup3();
        $h = fn () => $this->withUserToken($token);

        $h()->postJson('/api/v1/deals', ['name' => 'X', 'price' => 5, 'items' => []])->assertStatus(422);
        $h()->postJson('/api/v1/deals', ['name' => 'X', 'price' => 5])->assertStatus(422);
        $h()->postJson('/api/v1/deals', [
            'name' => 'X', 'price' => 5, 'starts_on' => '2030-02-02', 'ends_on' => '2030-01-01',
            'items' => [['product_id' => $burger->id, 'quantity' => 1]],
        ])->assertStatus(422);
        $h()->postJson('/api/v1/deals', [
            'name' => 'X', 'price' => 5, 'items' => [['product_id' => 999999, 'quantity' => 1]],
        ])->assertStatus(422);

        // A product the customer must pick options for can't be in a combo.
        $group = ModifierGroup::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Size', 'selection_type' => 'SINGLE',
            'is_required' => true, 'min_selections' => 1, 'max_selections' => 1,
        ]);
        $burger->modifierGroups()->attach($group->id, ['display_order' => 0]);
        $h()->postJson('/api/v1/deals', [
            'name' => 'X', 'price' => 5, 'items' => [['product_id' => $burger->id, 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors(['items.0.product_id']);

        $this->assertNotNull($fries);
    }

    public function test_deals_are_private_to_their_restaurant(): void
    {
        [, , $tokenA, $burgerA, $friesA] = $this->setup3();
        [$restaurantB, , $ownerB] = $this->makeRestaurantWithOwner('Other Co', planSlug: 'premium');
        $tokenB = $this->actingAsUser($ownerB);
        $productB = $this->product($restaurantB, 'Taco', 4);

        $deal = $this->createDeal($tokenA, $burgerA, $friesA);

        $this->withUserToken($tokenB)->getJson('/api/v1/deals')->assertOk()->assertJsonCount(0, 'data');
        $this->withUserToken($tokenB)->getJson("/api/v1/deals/{$deal['id']}")->assertStatus(404);
        $this->withUserToken($tokenB)->patchJson("/api/v1/deals/{$deal['id']}", ['price' => 1])->assertStatus(404);
        $this->withUserToken($tokenB)->deleteJson("/api/v1/deals/{$deal['id']}")->assertStatus(404);

        // ...and another restaurant's product can't be put in a deal.
        $this->withUserToken($tokenA)->postJson('/api/v1/deals', [
            'name' => 'Sneaky', 'price' => 1, 'items' => [['product_id' => $productB->id, 'quantity' => 1]],
        ])->assertStatus(422);
    }

    public function test_the_public_list_only_shows_running_deals_available_at_the_branch(): void
    {
        [$restaurant, $branch, $token, $burger, $fries] = $this->setup3();
        $running = $this->createDeal($token, $burger, $fries);
        $this->createDeal($token, $burger, $fries, ['name' => 'Paused', 'status' => 'INACTIVE']);
        $this->createDeal($token, $burger, $fries, ['name' => 'Soon', 'starts_on' => now()->addDays(2)->toDateString()]);
        $this->createDeal($token, $burger, $fries, ['name' => 'Over', 'ends_on' => now()->subDay()->toDateString()]);

        $res = $this->getJson("/api/v1/app/deals?restaurant={$restaurant->slug}&branch={$branch->id}")->assertOk();
        $this->assertSame([$running['id']], collect($res->json('data.deals'))->pluck('id')->all());
        $this->assertEquals(20.0, $res->json('data.deals.0.original_price'));
        $this->assertEquals(8.0, $res->json('data.deals.0.savings'));
        $this->assertCount(2, $res->json('data.deals.0.items'));

        // One ingredient switched off at this branch hides the deal there.
        BranchProduct::create([
            'restaurant_id' => $restaurant->id, 'branch_id' => $branch->id, 'product_id' => $fries->id, 'is_available' => false,
        ]);
        $this->getJson("/api/v1/app/deals?restaurant={$restaurant->slug}&branch={$branch->id}")
            ->assertOk()->assertJsonCount(0, 'data.deals');
    }

    public function test_ordering_a_deal_stores_plain_lines_that_add_up_to_the_deal_price(): void
    {
        [$restaurant, $branch, $token, $burger, $fries] = $this->setup3();
        $deal = $this->createDeal($token, $burger, $fries, ['price' => 13.33]);
        $customer = $this->customerToken($restaurant);

        $order = $this->withUserToken($customer)->postJson('/api/v1/customer/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'deals' => [['deal_id' => $deal['id'], 'quantity' => 2]],
        ])->assertStatus(201)->json('data');

        $this->assertEquals(26.66, $order['subtotal']);

        $items = \App\Models\OrderItem::withoutGlobalScopes()->where('order_id', $order['id'])->get();
        $this->assertCount(2, $items);
        $this->assertEquals(26.66, round($items->sum('line_total'), 2));
        $this->assertEqualsCanonicalizing([2, 4], $items->pluck('quantity')->all());
        $this->assertSame(['Burger Combo'], $items->pluck('deal_name')->unique()->values()->all());
        $this->assertCount(1, $items->pluck('deal_ref')->unique());
        $this->assertSame([$deal['id']], $items->pluck('deal_id')->unique()->values()->all());

        // The customer's own view of the order says which lines came from the deal.
        $shown = $this->withUserToken($customer)->getJson("/api/v1/customer/orders/{$order['id']}")->assertOk();
        $this->assertSame('Burger Combo', $shown->json('data.items.0.deal_name'));
        $this->assertSame($deal['id'], $shown->json('data.items.0.deal_id'));
    }

    public function test_deals_and_ordinary_items_mix_in_one_order(): void
    {
        [$restaurant, $branch, $token, $burger, $fries] = $this->setup3();
        $deal = $this->createDeal($token, $burger, $fries);
        $customer = $this->customerToken($restaurant);

        $order = $this->withUserToken($customer)->postJson('/api/v1/customer/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $burger->id, 'quantity' => 1]],
            'deals' => [['deal_id' => $deal['id'], 'quantity' => 1]],
        ])->assertStatus(201)->json('data');

        $this->assertEquals(22.0, $order['subtotal']); // 10 + 12
    }

    public function test_an_ended_paused_or_unavailable_deal_cannot_be_ordered(): void
    {
        [$restaurant, $branch, $token, $burger, $fries] = $this->setup3();
        $customer = $this->customerToken($restaurant);
        $place = fn (int $id) => $this->withUserToken($customer)->postJson('/api/v1/customer/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY', 'deals' => [['deal_id' => $id, 'quantity' => 1]],
        ]);

        $ended = $this->createDeal($token, $burger, $fries, ['ends_on' => now()->subDay()->toDateString()]);
        $paused = $this->createDeal($token, $burger, $fries, ['status' => 'INACTIVE']);
        $place($ended['id'])->assertStatus(422);
        $place($paused['id'])->assertStatus(422);
        $place(987654)->assertStatus(422);

        $live = $this->createDeal($token, $burger, $fries);
        BranchProduct::create([
            'restaurant_id' => $restaurant->id, 'branch_id' => $branch->id, 'product_id' => $burger->id, 'is_available' => false,
        ]);
        $place($live['id'])->assertStatus(422);
    }

    public function test_an_order_needs_items_or_deals(): void
    {
        [$restaurant, $branch] = $this->setup3();
        $customer = $this->customerToken($restaurant);

        $this->withUserToken($customer)->postJson('/api/v1/customer/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
        ])->assertStatus(422);
    }

    public function test_a_deal_photo_can_be_uploaded_and_only_images_are_accepted(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        [, , $token] = $this->setup3();

        $res = $this->withUserToken($token)->post('/api/v1/deals/image', [
            'file' => \Illuminate\Http\UploadedFile::fake()->image('combo.jpg', 600, 400),
        ], ['Accept' => 'application/json'])->assertStatus(201);
        $this->assertStringContainsString('/storage/restaurants/', $res->json('data.url'));

        $this->withUserToken($token)->post('/api/v1/deals/image', [
            'file' => \Illuminate\Http\UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }
}
