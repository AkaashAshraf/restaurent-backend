<?php

namespace Tests\Feature;

use App\Models\Table;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A restaurant's own white-label customer app: the super admin creates a key,
 * the app sends it as X-App-Key, and the server answers with that restaurant's
 * branding and data.
 */
class CustomerAppKeyTest extends TestCase
{
    private function base(string $restaurantId): string
    {
        return "/api/v1/super-admin/restaurants/{$restaurantId}/customer-app";
    }

    private function asSuperAdmin(): static
    {
        return $this->withUserToken($this->actingAsUser($this->makeSuperAdmin()));
    }

    public function test_super_admin_creates_key_and_app_resolves_restaurant_by_it(): void
    {
        [$restaurant] = $this->makeRestaurantWithOwner('Spice Route');

        $res = $this->asSuperAdmin()->postJson($this->base($restaurant->id).'/key')->assertCreated();
        $key = $res->json('data.app_key');
        $this->assertStringStartsWith('rk_', $key);

        $this->app['auth']->forgetGuards();
        $config = $this->withHeaders(['X-App-Key' => $key])->getJson('/api/v1/app/config')->assertOk();
        $this->assertSame('Spice Route', $config->json('data.restaurant.name'));
        $this->assertSame('Spice Route', $config->json('data.branding.app_name'));
        $this->assertArrayHasKey('ordering', $config->json('data'));
        $this->assertTrue($config->json('data.restaurant.operational'));
    }

    public function test_wrong_key_never_falls_back_to_another_restaurant(): void
    {
        [$restaurant] = $this->makeRestaurantWithOwner('Spice Route');

        $this->withHeaders(['X-App-Key' => 'rk_nope'])
            ->getJson("/api/v1/app/config?restaurant={$restaurant->slug}")
            ->assertNotFound();
    }

    public function test_rotating_the_key_invalidates_the_old_one_and_revoking_turns_the_app_off(): void
    {
        [$restaurant] = $this->makeRestaurantWithOwner();

        $old = $this->asSuperAdmin()->postJson($this->base($restaurant->id).'/key')->json('data.app_key');
        $new = $this->asSuperAdmin()->postJson($this->base($restaurant->id).'/key')->assertOk()->json('data.app_key');
        $this->assertNotSame($old, $new);

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['X-App-Key' => $old])->getJson('/api/v1/app/config')->assertNotFound();
        $this->withHeaders(['X-App-Key' => $new])->getJson('/api/v1/app/config')->assertOk();

        $this->asSuperAdmin()->deleteJson($this->base($restaurant->id).'/key')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['X-App-Key' => $new])->getJson('/api/v1/app/config')->assertNotFound();
    }

    public function test_branding_is_saved_resolved_with_defaults_and_validated(): void
    {
        [$restaurant, , $owner] = $this->makeRestaurantWithOwner('Stack & Bun');

        $this->asSuperAdmin()->putJson($this->base($restaurant->id).'/branding', [
            'colors' => ['primary' => 'red'],
        ])->assertStatus(422);

        $res = $this->asSuperAdmin()->putJson($this->base($restaurant->id).'/branding', [
            'tagline' => 'Smashed to order',
            'colors' => ['primary' => '#FFB800', 'secondary' => ''],
            'font' => 'Montserrat',
            'corner_radius' => 'pill',
            'logo_url' => '/storage/x.png',
        ])->assertOk();

        $resolved = $res->json('data.resolved');
        $this->assertSame('Stack & Bun', $resolved['app_name']);       // default from the restaurant
        $this->assertSame('#FFB800', $resolved['colors']['primary']);
        $this->assertSame('Montserrat', $resolved['font']);
        $this->assertSame(24, $resolved['corner_radius_px']);
        $this->assertStringStartsWith('http', $resolved['logo_url']);  // made absolute
        $this->assertArrayNotHasKey('secondary', $res->json('data.branding.colors'));

        // A restaurant's own staff can't edit it — only the platform's super admin.
        $this->withUserToken($this->actingAsUser($owner))
            ->putJson($this->base($restaurant->id).'/branding', ['tagline' => 'x'])->assertForbidden();
    }

    public function test_asset_upload_stores_the_image_and_sets_it_in_the_branding(): void
    {
        Storage::fake('public');
        [$restaurant] = $this->makeRestaurantWithOwner();

        $res = $this->asSuperAdmin()->postJson($this->base($restaurant->id).'/assets', [
            'type' => 'logo',
            'file' => UploadedFile::fake()->image('logo.png', 600, 600),
        ])->assertCreated();
        $this->assertNotEmpty($res->json('data.url'));
        $this->assertSame($res->json('data.url'), $res->json('data.branding.logo_url'));

        $res = $this->asSuperAdmin()->postJson($this->base($restaurant->id).'/assets', [
            'type' => 'banner',
            'file' => UploadedFile::fake()->image('b.jpg', 1200, 500),
        ])->assertCreated();
        $this->assertCount(1, $res->json('data.branding.banner_urls'));

        // The launcher icon has to be a big square.
        $this->asSuperAdmin()->postJson($this->base($restaurant->id).'/assets', [
            'type' => 'icon',
            'file' => UploadedFile::fake()->image('icon.png', 300, 200),
        ])->assertStatus(422);
    }

    public function test_customer_registers_and_sees_tables_through_the_key(): void
    {
        [$restaurant, $branch] = $this->makeRestaurantWithOwner();
        Table::create(['restaurant_id' => $restaurant->id, 'branch_id' => $branch->id, 'table_number' => 'T1', 'capacity' => 4, 'status' => 'AVAILABLE']);

        $key = $this->asSuperAdmin()->postJson($this->base($restaurant->id).'/key')->json('data.app_key');
        $this->app['auth']->forgetGuards();

        $this->withHeaders(['X-App-Key' => $key])
            ->postJson('/api/v1/app/auth/register', ['name' => 'Sara', 'phone' => '0300111', 'password' => 'secret12'])
            ->assertCreated();

        $this->withHeaders(['X-App-Key' => $key])->getJson("/api/v1/app/tables?branch_id={$branch->id}")
            ->assertOk()->assertJsonPath('data.0.table_number', 'T1');
    }

    public function test_the_key_is_never_leaked_by_the_restaurant_apis(): void
    {
        [$restaurant] = $this->makeRestaurantWithOwner();
        $this->asSuperAdmin()->postJson($this->base($restaurant->id).'/key')->assertCreated();

        $this->asSuperAdmin()->getJson("/api/v1/super-admin/restaurants/{$restaurant->id}")
            ->assertOk()->assertJsonMissingPath('data.app_key');
    }
}
