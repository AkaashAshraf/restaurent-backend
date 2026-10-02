<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Services\BranchHoursService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Opening hours per branch, with separate dine-in / takeaway / delivery hours:
 * how they are stored and managed, how "open now" is worked out (overnight
 * windows, a service window inside the branch's hours, timezones), the public
 * list the customer app shows, and that customers can't order while closed.
 *
 * Dates used: 2026-10-05 is a Monday.
 */
class BranchHoursTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Carbon\Carbon::setTestNow();
        parent::tearDown();
    }

    private function week(?string $open = '12:00', ?string $close = '23:00', array $overrides = []): array
    {
        $days = [];
        foreach (range(0, 6) as $dow) {
            $days[] = ($overrides[$dow] ?? []) + [
                'day_of_week' => $dow, 'is_closed' => false, 'is_24_hours' => false,
                'open_time' => $open, 'close_time' => $close,
            ];
        }

        return $days;
    }

    private function at(string $datetime, string $tz = 'UTC'): void
    {
        $now = CarbonImmutable::parse($datetime, $tz);
        CarbonImmutable::setTestNow($now);
        \Carbon\Carbon::setTestNow($now);
    }

    private function service(): BranchHoursService
    {
        return app(BranchHoursService::class);
    }

    private function setHours(Branch $branch, array $schedule): void
    {
        $this->service()->replace($branch, $schedule);
    }

    // ---- "open now" logic -------------------------------------------------

    public function test_a_branch_without_any_hours_is_always_open(): void
    {
        [, $branch] = $this->makeRestaurantWithOwner();

        $status = $this->service()->status($branch, 'DELIVERY', 'UTC');

        $this->assertTrue($status['is_open']);
        $this->assertNull($status['opens_at']);
    }

    public function test_general_hours_open_and_close(): void
    {
        [, $branch] = $this->makeRestaurantWithOwner();
        $this->setHours($branch, ['GENERAL' => $this->week('12:00', '23:00')]);

        $this->at('2026-10-05 13:00');
        $open = $this->service()->status($branch, 'GENERAL', 'UTC');
        $this->assertTrue($open['is_open']);
        $this->assertSame('2026-10-05T23:00:00+00:00', $open['closes_at']);

        $this->at('2026-10-05 08:00');
        $closed = $this->service()->status($branch, 'GENERAL', 'UTC');
        $this->assertFalse($closed['is_open']);
        $this->assertSame('2026-10-05T12:00:00+00:00', $closed['opens_at']);

        $this->at('2026-10-05 23:30');
        $late = $this->service()->status($branch, 'GENERAL', 'UTC');
        $this->assertFalse($late['is_open']);
        $this->assertSame('2026-10-06T12:00:00+00:00', $late['opens_at']);
    }

    public function test_an_overnight_window_runs_into_the_next_morning(): void
    {
        [, $branch] = $this->makeRestaurantWithOwner();
        $this->setHours($branch, ['GENERAL' => $this->week('12:00', '02:00')]);

        $this->at('2026-10-05 01:00');   // still Sunday night's session
        $status = $this->service()->status($branch, 'GENERAL', 'UTC');
        $this->assertTrue($status['is_open']);
        $this->assertSame('2026-10-05T02:00:00+00:00', $status['closes_at']);

        $this->at('2026-10-05 03:00');
        $this->assertFalse($this->service()->isOpen($branch, 'GENERAL', 'UTC'));
    }

    public function test_a_closed_day_and_a_24_hour_day(): void
    {
        [, $branch] = $this->makeRestaurantWithOwner();
        // Monday closed, Tuesday open all day.
        $this->setHours($branch, ['GENERAL' => $this->week('12:00', '23:00', [
            1 => ['is_closed' => true],
            2 => ['is_24_hours' => true, 'open_time' => null, 'close_time' => null],
        ])]);

        $this->at('2026-10-05 15:00');   // Monday
        $monday = $this->service()->status($branch, 'GENERAL', 'UTC');
        $this->assertFalse($monday['is_open']);
        $this->assertSame('2026-10-06T00:00:00+00:00', $monday['opens_at']);   // Tuesday 24h starts at midnight

        $this->at('2026-10-06 03:00');   // Tuesday small hours
        $this->assertTrue($this->service()->isOpen($branch, 'GENERAL', 'UTC'));
    }

    public function test_a_service_with_its_own_hours_is_limited_to_them_and_others_follow_the_branch(): void
    {
        [, $branch] = $this->makeRestaurantWithOwner();
        $this->setHours($branch, [
            'GENERAL' => $this->week('12:00', '00:30'),
            'DELIVERY' => $this->week('14:00', '23:00'),
        ]);

        $this->at('2026-10-05 13:00');
        $this->assertTrue($this->service()->isOpen($branch, 'DINE_IN', 'UTC'), 'dine-in follows the branch');
        $this->assertTrue($this->service()->isOpen($branch, 'TAKEAWAY', 'UTC'));
        $delivery = $this->service()->status($branch, 'DELIVERY', 'UTC');
        $this->assertFalse($delivery['is_open']);
        $this->assertSame('2026-10-05T14:00:00+00:00', $delivery['opens_at']);

        $this->at('2026-10-05 15:00');
        $this->assertTrue($this->service()->isOpen($branch, 'DELIVERY', 'UTC'));
        $this->assertSame('2026-10-05T23:00:00+00:00', $this->service()->status($branch, 'DELIVERY', 'UTC')['closes_at']);
    }

    public function test_a_service_window_cannot_open_the_branch_early(): void
    {
        [, $branch] = $this->makeRestaurantWithOwner();
        $this->setHours($branch, [
            'GENERAL' => $this->week('12:00', '23:00'),
            'TAKEAWAY' => $this->week('10:00', '22:00'),
        ]);

        $this->at('2026-10-05 11:00');
        $status = $this->service()->status($branch, 'TAKEAWAY', 'UTC');
        $this->assertFalse($status['is_open']);
        $this->assertSame('2026-10-05T12:00:00+00:00', $status['opens_at']);

        $this->at('2026-10-05 22:30');   // branch open, takeaway window over
        $this->assertFalse($this->service()->isOpen($branch, 'TAKEAWAY', 'UTC'));
        $this->assertTrue($this->service()->isOpen($branch, 'DINE_IN', 'UTC'));
    }

    public function test_hours_are_read_in_the_restaurants_timezone(): void
    {
        [, $branch] = $this->makeRestaurantWithOwner();
        $this->setHours($branch, ['GENERAL' => $this->week('12:00', '23:00')]);

        // 07:30 UTC is 12:30 in Karachi (UTC+5): open there, closed in UTC.
        $this->at('2026-10-05 07:30');
        $this->assertTrue($this->service()->isOpen($branch, 'GENERAL', 'Asia/Karachi'));
        $this->assertFalse($this->service()->isOpen($branch, 'GENERAL', 'UTC'));
    }

    public function test_a_bad_stored_timezone_falls_back_to_utc_instead_of_failing(): void
    {
        [$restaurant, $branch] = $this->makeRestaurantWithOwner('Odd Tz Co');
        $restaurant->forceFill(['timezone' => 'Not/AZone'])->save();
        $this->setHours($branch, ['GENERAL' => $this->week('12:00', '23:00')]);

        $this->at('2026-10-05 13:00');
        $this->assertTrue($this->service()->isOpen($branch, 'GENERAL', 'Not/AZone'));
        $this->getJson('/api/v1/app/hours?restaurant='.$restaurant->slug)->assertOk()->assertJsonPath('data.timezone', 'UTC');
    }

    // ---- management API ---------------------------------------------------

    public function test_owner_sets_and_reads_hours_and_a_service_can_be_reset_to_follow_the_branch(): void
    {
        [, $branch, $owner] = $this->makeRestaurantWithOwner('Hours Co');
        $token = $this->actingAsUser($owner);

        $res = $this->withUserToken($token)->putJson("/api/v1/branches/{$branch->id}/hours", [
            'GENERAL' => $this->week('12:00', '00:30'),
            'DELIVERY' => $this->week('13:00', '23:00'),
        ])->assertOk();

        $res->assertJsonPath('data.hours_configured', true)
            ->assertJsonPath('data.services.GENERAL.custom', false)   // GENERAL is never "custom"
            ->assertJsonPath('data.services.DELIVERY.custom', true)
            ->assertJsonPath('data.services.DELIVERY.days.1.open_time', '13:00')
            ->assertJsonPath('data.services.DINE_IN.custom', false)
            ->assertJsonPath('data.services.DINE_IN.days.1.close_time', '00:30');

        $this->withUserToken($token)->getJson("/api/v1/branches/{$branch->id}/hours")
            ->assertOk()->assertJsonPath('data.services.TAKEAWAY.days.0.open_time', '12:00');

        // null → delivery follows the branch again.
        $this->withUserToken($token)->putJson("/api/v1/branches/{$branch->id}/hours", ['DELIVERY' => null])
            ->assertOk()->assertJsonPath('data.services.DELIVERY.custom', false);

        $this->assertDatabaseMissing('branch_hours', ['branch_id' => $branch->id, 'service' => 'DELIVERY']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'branch.hours_updated', 'branch_id' => $branch->id]);
    }

    public function test_hours_are_validated(): void
    {
        [, $branch, $owner] = $this->makeRestaurantWithOwner('Hours Co');
        $token = $this->actingAsUser($owner);
        $url = "/api/v1/branches/{$branch->id}/hours";

        // Not seven days.
        $this->withUserToken($token)->putJson($url, ['GENERAL' => array_slice($this->week(), 0, 3)])->assertStatus(422);

        // The same day twice.
        $dupe = $this->week();
        $dupe[6]['day_of_week'] = 5;
        $this->withUserToken($token)->putJson($url, ['GENERAL' => $dupe])->assertStatus(422);

        // Open day without times, and open == close.
        $this->withUserToken($token)->putJson($url, ['GENERAL' => $this->week(null, null)])->assertStatus(422);
        $this->withUserToken($token)->putJson($url, ['GENERAL' => $this->week('12:00', '12:00')])->assertStatus(422);

        // Bad time and general can't be removed.
        $this->withUserToken($token)->putJson($url, ['GENERAL' => $this->week('noon', '23:00')])->assertStatus(422);
        $this->withUserToken($token)->putJson($url, ['GENERAL' => null])->assertStatus(422);

        $this->assertDatabaseCount('branch_hours', 0);
    }

    public function test_only_staff_who_can_update_branches_may_change_hours_and_tenants_are_isolated(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Hours Co');
        $cashier = $this->makeBranchScopedUser($restaurant, $branch, 'cashier');
        $url = "/api/v1/branches/{$branch->id}/hours";

        $this->withUserToken($this->actingAsUser($cashier))->putJson($url, ['GENERAL' => $this->week()])->assertStatus(403);

        [, , $otherOwner] = $this->makeRestaurantWithOwner('Other Co');
        $this->withUserToken($this->actingAsUser($otherOwner))->getJson($url)->assertStatus(404);
        $this->withUserToken($this->actingAsUser($otherOwner))->putJson($url, ['GENERAL' => $this->week()])->assertStatus(404);
    }

    // ---- public list for the customer app -----------------------------------

    public function test_public_hours_list_every_active_branch_with_services_and_open_now(): void
    {
        [$restaurant, $branch] = $this->makeRestaurantWithOwner('Public Co', planSlug: 'premium');
        $restaurant->update(['timezone' => 'Asia/Karachi']);
        $restaurant->settings->update(['customer_order_types' => ['TAKEAWAY', 'DELIVERY']]);
        $second = Branch::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Second', 'branch_code' => 'B2', 'status' => 'ACTIVE', 'priority' => 5,
        ]);
        Branch::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Shut', 'branch_code' => 'B3', 'status' => 'INACTIVE',
        ]);
        $this->setHours($branch, ['GENERAL' => $this->week('12:00', '23:00'), 'DELIVERY' => $this->week('14:00', '22:00')]);
        // $second has no hours configured → always open.

        $this->at('2026-10-05 07:30');   // 12:30 in Karachi

        $res = $this->getJson('/api/v1/app/hours?restaurant='.$restaurant->slug)->assertOk();
        $res->assertJsonPath('data.timezone', 'Asia/Karachi')
            ->assertJsonPath('data.today', 1)   // Monday
            ->assertJsonPath('data.now', '2026-10-05T12:30:00+05:00')
            ->assertJsonCount(2, 'data.branches')
            ->assertJsonPath('data.branches.0.id', $second->id)   // priority order
            ->assertJsonPath('data.branches.0.hours_configured', false)
            ->assertJsonPath('data.branches.0.services.GENERAL.status.is_open', true);

        $res->assertJsonPath('data.branches.1.id', $branch->id)
            ->assertJsonPath('data.branches.1.hours_configured', true)
            ->assertJsonPath('data.branches.1.services.GENERAL.status.is_open', true)
            ->assertJsonPath('data.branches.1.services.TAKEAWAY.custom', false)
            ->assertJsonPath('data.branches.1.services.DELIVERY.custom', true)
            ->assertJsonPath('data.branches.1.services.DELIVERY.status.is_open', false)
            ->assertJsonPath('data.branches.1.services.DELIVERY.status.opens_at', '2026-10-05T14:00:00+05:00');

        $this->getJson('/api/v1/app/hours?restaurant='.$restaurant->slug.'&branch_id='.$branch->id)
            ->assertOk()->assertJsonCount(1, 'data.branches');
    }

    public function test_public_hours_only_list_services_the_restaurant_offers(): void
    {
        [$restaurant, $branch] = $this->makeRestaurantWithOwner('Public Co');   // standard plan, no delivery
        // The app offers delivery and takeaway; this plan has no delivery feature.
        $restaurant->settings->update(['customer_order_types' => ['TAKEAWAY', 'DELIVERY']]);
        $this->setHours($branch, ['GENERAL' => $this->week()]);

        $res = $this->getJson('/api/v1/app/hours?restaurant='.$restaurant->slug)->assertOk();

        $this->assertEqualsCanonicalizing(['GENERAL', 'TAKEAWAY'], array_keys($res->json('data.branches.0.services')));
        $this->assertSame(['TAKEAWAY'], $res->json('data.order_types'));
    }

    public function test_public_hours_for_an_unknown_restaurant_is_404(): void
    {
        $this->getJson('/api/v1/app/hours?restaurant=nope-nope')->assertStatus(404);
    }

    // ---- customers can't order while closed ---------------------------------

    private function customerToken($restaurant): string
    {
        return $this->postJson("/api/v1/app/auth/register?restaurant={$restaurant->slug}", [
            'phone' => '5559000', 'password' => 'secret123',
        ])->assertStatus(201)->json('data.token');
    }

    private function product($restaurant): Product
    {
        $category = Category::create(['restaurant_id' => $restaurant->id, 'name' => 'Pizzas', 'slug' => 'pizzas-'.uniqid()]);

        return Product::create([
            'restaurant_id' => $restaurant->id, 'category_id' => $category->id,
            'name' => 'Margherita', 'slug' => 'margherita-'.uniqid(), 'base_price' => 10,
        ]);
    }

    public function test_a_customer_cannot_order_while_the_branch_is_closed(): void
    {
        [$restaurant, $branch] = $this->makeRestaurantWithOwner('Closed Co');
        $this->setHours($branch, ['GENERAL' => $this->week('12:00', '23:00')]);
        $product = $this->product($restaurant);
        $token = $this->customerToken($restaurant);
        $payload = [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ];

        $this->at('2026-10-05 08:00');
        $this->withUserToken($token)->postJson('/api/v1/customer/orders', $payload)
            ->assertStatus(422)
            ->assertJson(['code' => 'VALIDATION_ERROR'])
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Takeaway is closed') && str_contains($m, 'opens Mon 12:00 PM'));

        $this->at('2026-10-05 13:00');
        $this->withUserToken($token)->postJson('/api/v1/customer/orders', $payload)->assertStatus(201);
    }

    public function test_only_the_closed_service_is_blocked(): void
    {
        [$restaurant, $branch] = $this->makeRestaurantWithOwner('Closed Co');
        $this->setHours($branch, [
            'GENERAL' => $this->week('12:00', '23:00'),
            'DELIVERY' => $this->week('18:00', '22:00'),
        ]);
        $product = $this->product($restaurant);
        $token = $this->customerToken($restaurant);
        $items = [['product_id' => $product->id, 'quantity' => 1]];

        $this->at('2026-10-05 13:00');
        $this->withUserToken($token)->postJson('/api/v1/customer/orders', [
            'branch_id' => $branch->id, 'order_type' => 'DELIVERY', 'items' => $items,
        ])->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Delivery is closed'));

        // Takeaway follows the branch hours, so it is open.
        $this->withUserToken($token)->postJson('/api/v1/customer/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY', 'items' => $items,
        ])->assertStatus(201);
    }

    public function test_staff_can_still_place_orders_outside_opening_hours(): void
    {
        [$restaurant, $branch, $owner] = $this->makeRestaurantWithOwner('Closed Co');
        $this->setHours($branch, ['GENERAL' => $this->week('12:00', '23:00')]);
        $product = $this->product($restaurant);

        $this->at('2026-10-05 08:00');
        $this->withUserToken($this->actingAsUser($owner))->postJson('/api/v1/orders', [
            'branch_id' => $branch->id, 'order_type' => 'TAKEAWAY',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(201);
    }
}
