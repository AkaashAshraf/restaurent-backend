<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Restaurant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * "Continue with Google" / "Sign in with Apple" for the customer app, and the
 * phone number that has to be added before the first order. Tokens are signed
 * with a throwaway RSA key and the providers' key endpoints are faked, so the
 * real signature / issuer / audience / expiry checks run.
 */
class SocialLoginTest extends TestCase
{
    private const GOOGLE_WEB = '123456-web.apps.googleusercontent.com';

    private const GOOGLE_IOS = '123456-ios.apps.googleusercontent.com';

    private const BUNDLE = 'com.spiceroute.customer';

    private $key;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->key = $this->newKey();
        $this->fakeProviderKeys($this->key, 'kid-1');
    }

    private function newKey()
    {
        return openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    }

    private function b64u(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function fakeProviderKeys($key, string $kid): void
    {
        $rsa = openssl_pkey_get_details($key)['rsa'];
        $jwks = ['keys' => [['kty' => 'RSA', 'kid' => $kid, 'alg' => 'RS256', 'use' => 'sig', 'n' => $this->b64u($rsa['n']), 'e' => $this->b64u($rsa['e'])]]];

        Http::swap(new \Illuminate\Http\Client\Factory());   // drop any earlier fake — the first matching fake wins
        Http::fake([
            'www.googleapis.com/*' => Http::response($jwks),
            'appleid.apple.com/*' => Http::response($jwks),
        ]);
    }

    private function jwt(array $claims, ?string $kid = 'kid-1', $signWith = null): string
    {
        $header = $this->b64u(json_encode(['alg' => 'RS256', 'kid' => $kid, 'typ' => 'JWT']));
        $payload = $this->b64u(json_encode($claims));
        openssl_sign("$header.$payload", $signature, $signWith ?? $this->key, OPENSSL_ALGO_SHA256);

        return "$header.$payload.".$this->b64u($signature);
    }

    private function googleToken(array $override = [], ?string $kid = 'kid-1', $signWith = null): string
    {
        return $this->jwt($override + [
            'iss' => 'https://accounts.google.com', 'aud' => self::GOOGLE_WEB, 'sub' => 'g-1001',
            'email' => 'sara@gmail.com', 'email_verified' => true, 'name' => 'Sara Khan', 'exp' => time() + 3600,
        ], $kid, $signWith);
    }

    private function appleToken(array $override = []): string
    {
        return $this->jwt($override + [
            'iss' => 'https://appleid.apple.com', 'aud' => self::BUNDLE, 'sub' => 'a-2001',
            'email' => 'sara@privaterelay.appleid.com', 'email_verified' => 'true', 'exp' => time() + 3600,
        ]);
    }

    private function restaurant(array $branding = null): Restaurant
    {
        [$restaurant] = $this->makeRestaurantWithOwner('Social Co');
        $restaurant->app_branding = $branding ?? [
            'google_web_client_id' => self::GOOGLE_WEB, 'google_ios_client_id' => self::GOOGLE_IOS, 'ios_bundle_id' => self::BUNDLE,
        ];
        $restaurant->save();

        return $restaurant;
    }

    private function google($restaurant, string $token, array $extra = [])
    {
        return $this->postJson("/api/v1/app/auth/google?restaurant={$restaurant->slug}", ['id_token' => $token] + $extra);
    }

    private function apple($restaurant, string $token, array $extra = [])
    {
        return $this->postJson("/api/v1/app/auth/apple?restaurant={$restaurant->slug}", ['identity_token' => $token] + $extra);
    }

    // ---- Google ---------------------------------------------------------

    public function test_google_sign_in_creates_a_customer_without_a_phone_and_signs_in_again_later(): void
    {
        $restaurant = $this->restaurant();

        $first = $this->google($restaurant, $this->googleToken())->assertStatus(201);
        $first->assertJsonPath('data.customer.name', 'Sara Khan')
            ->assertJsonPath('data.customer.email', 'sara@gmail.com')
            ->assertJsonPath('data.customer.has_phone', false)
            ->assertJsonPath('data.customer.signed_in_with', ['google']);

        // The token it returns is a normal customer session.
        $this->withUserToken($first->json('data.token'))->getJson('/api/v1/customer/me')
            ->assertOk()->assertJsonPath('data.email', 'sara@gmail.com');

        $second = $this->google($restaurant, $this->googleToken())->assertOk();
        $this->assertSame($first->json('data.customer.id'), $second->json('data.customer.id'));
        $this->assertSame(1, Customer::where('restaurant_id', $restaurant->id)->count());
    }

    public function test_the_ios_google_client_id_is_accepted_too(): void
    {
        $restaurant = $this->restaurant();

        $this->google($restaurant, $this->googleToken(['aud' => self::GOOGLE_IOS]))->assertStatus(201);
    }

    public function test_untrustworthy_google_tokens_are_refused(): void
    {
        $restaurant = $this->restaurant();

        // Minted for a different app.
        $this->google($restaurant, $this->googleToken(['aud' => 'someone-else.apps.googleusercontent.com']))
            ->assertStatus(401)->assertJson(['code' => 'SOCIAL_AUTH_FAILED']);
        // Wrong issuer.
        $this->google($restaurant, $this->googleToken(['iss' => 'https://evil.example']))->assertStatus(401);
        // Expired.
        $this->google($restaurant, $this->googleToken(['exp' => time() - 600]))->assertStatus(401);
        // Signed with a key Google doesn't publish.
        $this->google($restaurant, $this->googleToken([], 'kid-1', $this->newKey()))->assertStatus(401);
        // Unknown key id.
        $this->google($restaurant, $this->googleToken([], 'kid-unknown'))->assertStatus(401);
        // Not a JWT at all.
        $this->google($restaurant, 'garbage')->assertStatus(401);

        $this->assertSame(0, Customer::where('restaurant_id', $restaurant->id)->count());
    }

    public function test_google_is_refused_when_the_restaurant_has_not_set_it_up(): void
    {
        $restaurant = $this->restaurant(['ios_bundle_id' => self::BUNDLE]);

        $this->google($restaurant, $this->googleToken())->assertStatus(422)->assertJson(['code' => 'SOCIAL_NOT_CONFIGURED']);
    }

    public function test_a_provider_outage_is_reported_not_treated_as_a_bad_token(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['www.googleapis.com/*' => Http::response('nope', 500)]);

        $this->google($this->restaurant(), $this->googleToken())->assertStatus(503)->assertJson(['code' => 'SOCIAL_PROVIDER_UNAVAILABLE']);
    }

    public function test_a_rotated_provider_key_is_picked_up(): void
    {
        $restaurant = $this->restaurant();
        $this->google($restaurant, $this->googleToken())->assertStatus(201);   // caches kid-1

        $rotated = $this->newKey();
        $this->fakeProviderKeys($rotated, 'kid-2');
        $this->google($restaurant, $this->googleToken(['sub' => 'g-2002', 'email' => 'other@gmail.com'], 'kid-2', $rotated))->assertStatus(201);
    }

    // ---- Apple ----------------------------------------------------------

    public function test_apple_sign_in_uses_the_bundle_id_and_the_name_the_app_passes(): void
    {
        $restaurant = $this->restaurant();

        $this->apple($restaurant, $this->appleToken(), ['name' => 'Sara Khan'])
            ->assertStatus(201)
            ->assertJsonPath('data.customer.name', 'Sara Khan')
            ->assertJsonPath('data.customer.signed_in_with', ['apple'])
            ->assertJsonPath('data.customer.has_phone', false);

        // Wrong bundle id → refused.
        $this->apple($restaurant, $this->appleToken(['aud' => 'com.other.app', 'sub' => 'a-9']))->assertStatus(401);
    }

    public function test_apple_is_refused_without_a_bundle_id(): void
    {
        $restaurant = $this->restaurant(['google_web_client_id' => self::GOOGLE_WEB]);

        $this->apple($restaurant, $this->appleToken())->assertStatus(422)->assertJson(['code' => 'SOCIAL_NOT_CONFIGURED']);
    }

    // ---- account matching ------------------------------------------------

    public function test_google_and_apple_with_the_same_verified_email_are_one_customer(): void
    {
        $restaurant = $this->restaurant();

        $viaGoogle = $this->google($restaurant, $this->googleToken())->assertStatus(201)->json('data.customer.id');
        $viaApple = $this->apple($restaurant, $this->appleToken(['email' => 'sara@gmail.com']))->assertOk()->json('data.customer');

        $this->assertSame($viaGoogle, $viaApple['id']);
        $this->assertEqualsCanonicalizing(['google', 'apple'], $viaApple['signed_in_with']);
    }

    public function test_google_never_takes_over_a_phone_and_password_account_by_email(): void
    {
        $restaurant = $this->restaurant();
        $existing = Customer::create([
            'restaurant_id' => $restaurant->id, 'phone' => '+923001112222', 'email' => 'sara@gmail.com',
            'password' => 'secret123', 'status' => 'ACTIVE',
        ]);

        $res = $this->google($restaurant, $this->googleToken())->assertStatus(201);

        $this->assertNotSame($existing->id, $res->json('data.customer.id'));
        $this->assertNull($existing->fresh()->google_id);
    }

    public function test_the_same_google_account_is_a_different_customer_at_another_restaurant(): void
    {
        $a = $this->restaurant();
        $b = $this->restaurant();

        $idA = $this->google($a, $this->googleToken())->assertStatus(201)->json('data.customer.id');
        $idB = $this->google($b, $this->googleToken())->assertStatus(201)->json('data.customer.id');

        $this->assertNotSame($idA, $idB);
    }

    public function test_a_deactivated_customer_cannot_sign_in_with_google(): void
    {
        $restaurant = $this->restaurant();
        $this->google($restaurant, $this->googleToken())->assertStatus(201);
        Customer::where('restaurant_id', $restaurant->id)->update(['status' => 'INACTIVE']);

        $this->google($restaurant, $this->googleToken())->assertStatus(403);
    }

    // ---- phone before the first order -------------------------------------

    private function signedInCustomer(Restaurant $restaurant): string
    {
        return $this->google($restaurant, $this->googleToken())->assertStatus(201)->json('data.token');
    }

    public function test_a_customer_can_add_their_phone_number(): void
    {
        $restaurant = $this->restaurant();
        $token = $this->signedInCustomer($restaurant);

        $this->withUserToken($token)->putJson('/api/v1/customer/phone', ['phone' => '+92 300 123-4567'])
            ->assertOk()->assertJsonPath('data.phone', '+923001234567')->assertJsonPath('data.has_phone', true);

        $this->withUserToken($token)->putJson('/api/v1/customer/phone', ['phone' => 'abc'])->assertStatus(422);
        $this->withUserToken($token)->putJson('/api/v1/customer/phone', ['phone' => '123'])->assertStatus(422);
    }

    public function test_a_number_already_on_another_account_is_refused_but_fine_at_another_restaurant(): void
    {
        $restaurant = $this->restaurant();
        Customer::create(['restaurant_id' => $restaurant->id, 'phone' => '+923001234567', 'password' => 'secret123', 'status' => 'ACTIVE']);
        $token = $this->signedInCustomer($restaurant);

        $this->withUserToken($token)->putJson('/api/v1/customer/phone', ['phone' => '+92 300 1234567'])
            ->assertStatus(422)->assertJsonValidationErrors('phone');

        $other = $this->restaurant();
        Customer::create(['restaurant_id' => $other->id, 'phone' => '+923009998888', 'password' => 'secret123', 'status' => 'ACTIVE']);
        $otherToken = $this->signedInCustomer($other);
        $this->withUserToken($otherToken)->putJson('/api/v1/customer/phone', ['phone' => '+923001234567'])->assertOk();
    }

    public function test_ordering_needs_a_phone_number(): void
    {
        $restaurant = $this->restaurant();
        $branch = \App\Models\Branch::withoutGlobalScopes()->where('restaurant_id', $restaurant->id)->firstOrFail();
        $category = Category::create(['restaurant_id' => $restaurant->id, 'name' => 'Pizzas', 'slug' => 'pizzas']);
        $product = Product::create([
            'restaurant_id' => $restaurant->id, 'category_id' => $category->id, 'name' => 'Margherita', 'slug' => 'margherita', 'base_price' => 10,
        ]);
        $token = $this->signedInCustomer($restaurant);
        $order = ['branch_id' => $branch->id, 'order_type' => 'TAKEAWAY', 'items' => [['product_id' => $product->id, 'quantity' => 1]]];

        $this->withUserToken($token)->postJson('/api/v1/customer/orders', $order)
            ->assertStatus(422)->assertJson(['code' => 'PHONE_REQUIRED']);

        $this->withUserToken($token)->putJson('/api/v1/customer/phone', ['phone' => '+923001234567'])->assertOk();

        $this->withUserToken($token)->postJson('/api/v1/customer/orders', $order)->assertStatus(201);
    }

    // ---- what the app is told ---------------------------------------------

    public function test_app_config_says_which_sign_in_options_to_show(): void
    {
        $restaurant = $this->restaurant();

        $this->getJson("/api/v1/app/config?restaurant={$restaurant->slug}")->assertOk()
            ->assertJsonPath('data.auth.google.enabled', true)
            ->assertJsonPath('data.auth.google.web_client_id', self::GOOGLE_WEB)
            ->assertJsonPath('data.auth.google.ios_client_id', self::GOOGLE_IOS)
            ->assertJsonPath('data.auth.apple.enabled', true);

        $bare = $this->restaurant(['app_name' => 'Plain']);
        $this->getJson("/api/v1/app/config?restaurant={$bare->slug}")->assertOk()
            ->assertJsonPath('data.auth.google.enabled', false)
            ->assertJsonPath('data.auth.apple.enabled', false);
    }

    public function test_super_admin_saves_the_google_client_ids_and_bad_ones_are_rejected(): void
    {
        $restaurant = $this->restaurant([]);
        $token = $this->actingAsUser($this->makeSuperAdmin());
        $url = "/api/v1/super-admin/restaurants/{$restaurant->id}/customer-app/branding";

        $this->withUserToken($token)->putJson($url, ['google_web_client_id' => 'not-a-client-id'])->assertStatus(422);

        $this->withUserToken($token)->putJson($url, [
            'google_web_client_id' => self::GOOGLE_WEB, 'google_ios_client_id' => self::GOOGLE_IOS, 'ios_bundle_id' => self::BUNDLE,
        ])->assertOk()->assertJsonPath('data.branding.google_web_client_id', self::GOOGLE_WEB);

        $this->getJson("/api/v1/app/config?restaurant={$restaurant->slug}")
            ->assertJsonPath('data.auth.google.enabled', true)->assertJsonPath('data.auth.apple.enabled', true);
    }
}
