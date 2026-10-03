<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PlatformSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PlatformSettingsTest extends TestCase
{
    private function superToken(?User $user = null): string
    {
        return $this->actingAsUser($user ?? $this->makeSuperAdmin());
    }

    public function test_defaults_are_returned_and_settings_can_be_updated(): void
    {
        $token = $this->superToken();

        $show = $this->withUserToken($token)->getJson('/api/v1/super-admin/settings');
        $show->assertOk();
        $this->assertSame('Restaurant Platform', $show->json('data.platform.platform_name'));
        $this->assertFalse($show->json('data.platform.maintenance_mode'));

        $update = $this->withUserToken($token)->patchJson('/api/v1/super-admin/settings', [
            'platform_name' => 'FoodCloud',
            'support_email' => 'help@foodcloud.test',
            'support_phone' => '+92 300 0000000',
            'session_days' => 7,
        ]);
        $update->assertOk();
        $this->assertSame('FoodCloud', $update->json('data.platform.platform_name'));
        $this->assertSame(7, $update->json('data.platform.session_days'));

        // Public info (no sign-in) carries the branding.
        $public = $this->getJson('/api/v1/platform');
        $public->assertOk();
        $this->assertSame('FoodCloud', $public->json('data.platform_name'));
        $this->assertSame('help@foodcloud.test', $public->json('data.support_email'));

        // A blank value resets to the default.
        $this->withUserToken($token)->patchJson('/api/v1/super-admin/settings', ['support_email' => '', 'session_days' => 0])->assertOk();
        $this->assertNull(PlatformSettings::get('support_email'));
        $this->assertSame(0, PlatformSettings::get('session_days'));
    }

    public function test_only_a_super_admin_can_use_settings(): void
    {
        [, , $owner] = $this->makeRestaurantWithOwner();
        $token = $this->actingAsUser($owner);

        $this->withUserToken($token)->getJson('/api/v1/super-admin/settings')->assertStatus(403);
        $this->withUserToken($token)->patchJson('/api/v1/super-admin/settings', ['platform_name' => 'Hacked'])->assertStatus(403);
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->getJson('/api/v1/super-admin/settings')->assertStatus(401);
    }

    public function test_logo_upload_and_removal(): void
    {
        Storage::fake('public');
        $token = $this->superToken();

        $res = $this->withUserToken($token)->post('/api/v1/super-admin/settings/logo', [
            'file' => UploadedFile::fake()->image('logo.png', 200, 200),
        ], ['Accept' => 'application/json']);
        $res->assertStatus(201);
        $this->assertNotEmpty($res->json('data.platform.logo_url'));
        $this->assertNotEmpty($this->getJson('/api/v1/platform')->json('data.logo_url'));

        $this->withUserToken($token)->deleteJson('/api/v1/super-admin/settings/logo')->assertOk();
        $this->assertNull($this->getJson('/api/v1/platform')->json('data.logo_url'));
    }

    public function test_account_name_and_email_can_be_changed_but_email_must_be_unique(): void
    {
        $admin = $this->makeSuperAdmin();
        $other = $this->makeSuperAdmin();
        $token = $this->superToken($admin);

        $this->withUserToken($token)->patchJson('/api/v1/super-admin/settings/account', [
            'name' => 'New Name', 'email' => $other->email,
        ])->assertStatus(422);

        $ok = $this->withUserToken($token)->patchJson('/api/v1/super-admin/settings/account', [
            'name' => 'New Name', 'email' => 'new.admin@platform.test',
        ]);
        $ok->assertOk();
        $this->assertSame('new.admin@platform.test', $admin->fresh()->email);
        // Keeping your own email is fine.
        $this->withUserToken($token)->patchJson('/api/v1/super-admin/settings/account', [
            'name' => 'Again', 'email' => 'new.admin@platform.test',
        ])->assertOk();
    }

    public function test_password_change_needs_the_current_password_and_signs_out_other_sessions(): void
    {
        $admin = $this->makeSuperAdmin(); // password: "password"
        $token = $this->superToken($admin);
        $otherDevice = $admin->createToken('phone')->plainTextToken;

        $this->withUserToken($token)->putJson('/api/v1/super-admin/settings/account/password', [
            'current_password' => 'wrong', 'password' => 'BrandNewPass1', 'password_confirmation' => 'BrandNewPass1',
        ])->assertStatus(422);

        $this->withUserToken($token)->putJson('/api/v1/super-admin/settings/account/password', [
            'current_password' => 'password', 'password' => 'short', 'password_confirmation' => 'short',
        ])->assertStatus(422);

        $this->withUserToken($token)->putJson('/api/v1/super-admin/settings/account/password', [
            'current_password' => 'password', 'password' => 'BrandNewPass1', 'password_confirmation' => 'BrandNewPass1',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'BrandNewPass1'])->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'password'])->assertStatus(422);

        // This session survives; the other device is signed out.
        $this->withUserToken($token)->getJson('/api/v1/super-admin/settings')->assertOk();
        $this->withUserToken($otherDevice)->getJson('/api/v1/super-admin/settings')->assertStatus(401);
    }

    public function test_sign_out_other_sessions(): void
    {
        $admin = $this->makeSuperAdmin();
        $token = $this->superToken($admin);
        $admin->createToken('a');
        $admin->createToken('b');

        $res = $this->withUserToken($token)->postJson('/api/v1/super-admin/settings/account/sign-out-others');
        $res->assertOk();
        $this->assertSame(2, $res->json('data.signed_out'));
        $this->assertSame(1, $admin->tokens()->count());
    }

    public function test_maintenance_mode_blocks_everyone_but_the_super_admin(): void
    {
        [$restaurant, , $owner] = $this->makeRestaurantWithOwner();
        $ownerToken = $this->actingAsUser($owner);
        $superToken = $this->superToken();

        $this->withUserToken($ownerToken)->getJson('/api/v1/branches')->assertOk();

        $this->withUserToken($superToken)->patchJson('/api/v1/super-admin/settings', [
            'maintenance_mode' => true, 'maintenance_message' => 'Back at 6pm.',
        ])->assertOk();

        // Signed-in staff are refused with 503 and the message.
        $blocked = $this->withUserToken($ownerToken)->getJson('/api/v1/branches');
        $blocked->assertStatus(503);
        $this->assertSame('MAINTENANCE', $blocked->json('code'));
        $this->assertSame('Back at 6pm.', $blocked->json('message'));

        // Staff can't sign in either…
        $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'password'])->assertStatus(503);
        // …but the Super Admin can, and keeps working.
        $super = User::where('is_super_admin', true)->first();
        $this->postJson('/api/v1/auth/login', ['email' => $super->email, 'password' => 'password'])->assertOk();
        $this->withUserToken($superToken)->getJson('/api/v1/super-admin/dashboard')->assertOk();

        // The customer app is told to show its maintenance screen.
        $restaurant->forceFill(['app_key' => 'rk_testkey'])->save();
        $config = $this->withHeader('X-App-Key', 'rk_testkey')->getJson("/api/v1/app/config?restaurant={$restaurant->slug}");
        $config->assertOk();
        $this->assertTrue($config->json('data.branding.maintenance_mode'));
        $this->assertSame('Back at 6pm.', $config->json('data.branding.maintenance_message'));
        $this->assertTrue($this->getJson('/api/v1/platform')->json('data.maintenance_mode'));

        // Turning it off restores access.
        $this->withUserToken($superToken)->patchJson('/api/v1/super-admin/settings', ['maintenance_mode' => false])->assertOk();
        $this->withUserToken($ownerToken)->getJson('/api/v1/branches')->assertOk();
    }

    public function test_session_length_sets_a_token_expiry(): void
    {
        [, , $owner] = $this->makeRestaurantWithOwner();
        $superToken = $this->superToken();

        $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'password'])->assertOk();
        $this->assertNull($owner->tokens()->latest('id')->first()->expires_at);

        $this->withUserToken($superToken)->patchJson('/api/v1/super-admin/settings', ['session_days' => 3])->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'password'])->assertOk();
        $expires = $owner->tokens()->latest('id')->first()->expires_at;
        $this->assertNotNull($expires);
        $this->assertTrue($expires->between(now()->addDays(3)->subMinute(), now()->addDays(3)->addMinute()));
    }
}
