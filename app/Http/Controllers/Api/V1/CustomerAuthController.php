<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Restaurant;
use App\Exceptions\SocialAuthException;
use App\Services\RestaurantResolver;
use App\Services\SocialIdentityService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * The customer app/website's own auth, deliberately separate from
 * AuthController (staff): a Customer isn't tenant-known ahead of login
 * the same way a User is either — restaurant is resolved from the Host
 * header / ?restaurant=slug (RestaurantResolver, same as ConfigController/
 * MenuController's public endpoints), and a Customer's identity is
 * restaurant + phone, not a globally-unique email.
 */
class CustomerAuthController extends Controller
{
    public function __construct(private RestaurantResolver $restaurants, private SocialIdentityService $social)
    {
    }

    public function register(Request $request)
    {
        $restaurant = $this->resolveRestaurant($request);
        if (! $restaurant) {
            return ApiResponse::error('NOT_FOUND', 'No restaurant is configured for this server URL.', 404);
        }
        if (! $restaurant->isOperational()) {
            return ApiResponse::error('RESTAURANT_INACTIVE', 'This restaurant is not currently active.', 403);
        }

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email'],
            'password' => ['required', 'string', 'min:6'],
        ]);

        if (Customer::where('restaurant_id', $restaurant->id)->where('phone', $data['phone'])->exists()) {
            throw ValidationException::withMessages([
                'phone' => ['An account with this phone number already exists.'],
            ]);
        }

        $customer = Customer::create([
            'restaurant_id' => $restaurant->id,
            'name' => $data['name'] ?? null,
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,
            'password' => $data['password'],
            'status' => 'ACTIVE',
        ]);

        $token = $customer->createToken('customer-api')->plainTextToken;

        return ApiResponse::success([
            'token' => $token,
            'customer' => $this->serialize($customer),
        ], 201);
    }

    public function login(Request $request)
    {
        $restaurant = $this->resolveRestaurant($request);
        if (! $restaurant) {
            return ApiResponse::error('NOT_FOUND', 'No restaurant is configured for this server URL.', 404);
        }
        if (! $restaurant->isOperational()) {
            return ApiResponse::error('RESTAURANT_INACTIVE', 'This restaurant is not currently active.', 403);
        }

        $data = $request->validate([
            'phone' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $customer = Customer::where('restaurant_id', $restaurant->id)->where('phone', $data['phone'])->first();

        if (! $customer || ! Hash::check($data['password'], $customer->password)) {
            throw ValidationException::withMessages([
                'phone' => ['These credentials do not match our records.'],
            ]);
        }

        if ($customer->status !== 'ACTIVE') {
            return ApiResponse::error('FORBIDDEN', 'Your account has been deactivated.', 403);
        }

        $token = $customer->createToken('customer-api')->plainTextToken;

        return ApiResponse::success([
            'token' => $token,
            'customer' => $this->serialize($customer),
        ]);
    }

    /**
     * POST app/auth/google { id_token, name? } — "Continue with Google".
     * The token must have been issued for one of this restaurant's Google
     * client ids (set by the super admin on the customer app).
     */
    public function google(Request $request)
    {
        $restaurant = $this->activeRestaurant($request);
        if ($restaurant instanceof \Illuminate\Http\JsonResponse) {
            return $restaurant;
        }

        $data = $request->validate(['id_token' => ['required', 'string'], 'name' => ['nullable', 'string', 'max:150']]);

        $branding = $restaurant->app_branding ?: [];
        $identity = $this->social->verifyGoogle($data['id_token'], [
            $branding['google_web_client_id'] ?? null,
            $branding['google_ios_client_id'] ?? null,
        ]);

        return $this->socialLogin($restaurant, 'google_id', $identity, $data['name'] ?? null);
    }

    /**
     * POST app/auth/apple { identity_token, name? } — "Sign in with Apple".
     * Apple only shares the person's name the very first time, and only with
     * the app, so the app passes it along as `name`.
     */
    public function apple(Request $request)
    {
        $restaurant = $this->activeRestaurant($request);
        if ($restaurant instanceof \Illuminate\Http\JsonResponse) {
            return $restaurant;
        }

        $data = $request->validate(['identity_token' => ['required', 'string'], 'name' => ['nullable', 'string', 'max:150']]);

        $bundleId = ($restaurant->app_branding ?: [])['ios_bundle_id'] ?? null;
        if (! $bundleId) {
            throw new SocialAuthException('Sign in with Apple is not set up for this app.', 422, 'SOCIAL_NOT_CONFIGURED');
        }

        $identity = $this->social->verifyApple($data['identity_token'], $bundleId);

        return $this->socialLogin($restaurant, 'apple_id', $identity, $data['name'] ?? null);
    }

    /**
     * Finds or creates the customer for a verified Google / Apple identity.
     *
     * Matching: first by the provider's own id. Failing that, by email — but
     * only against an account that itself came from a provider (whose email
     * the provider verified). A phone + password account's email is just
     * something the person typed, so signing in with Google must not take
     * over an account on the strength of it; such a customer gets a separate
     * account and can't claim the same phone number (see updatePhone).
     */
    private function socialLogin(Restaurant $restaurant, string $column, array $identity, ?string $nameHint)
    {
        $customer = Customer::where('restaurant_id', $restaurant->id)->where($column, $identity['sub'])->first();

        if (! $customer && $identity['email'] && $identity['email_verified']) {
            $customer = Customer::where('restaurant_id', $restaurant->id)
                ->where('email', $identity['email'])
                ->where(fn ($q) => $q->whereNotNull('google_id')->orWhereNotNull('apple_id'))
                ->first();
            if ($customer) {
                $customer->update([$column => $identity['sub']]);
            }
        }

        $created = false;
        if (! $customer) {
            $customer = Customer::create([
                'restaurant_id' => $restaurant->id,
                'name' => $identity['name'] ?: $nameHint,
                'email' => $identity['email'],
                $column => $identity['sub'],
                'status' => 'ACTIVE',
            ]);
            $created = true;
        } elseif (! $customer->name && ($identity['name'] || $nameHint)) {
            $customer->update(['name' => $identity['name'] ?: $nameHint]);
        }

        if ($customer->status !== 'ACTIVE') {
            return ApiResponse::error('FORBIDDEN', 'Your account has been deactivated.', 403);
        }

        return ApiResponse::success([
            'token' => $customer->createToken('customer-api')->plainTextToken,
            'customer' => $this->serialize($customer),
        ], $created ? 201 : 200);
    }

    /**
     * PUT customer/phone { phone } — the number the restaurant can reach the
     * customer on. Required before the first order for anyone who signed in
     * with Google / Apple. Not verified by SMS yet (phone_verified_at stays null).
     */
    public function updatePhone(Request $request)
    {
        $customer = $request->user();

        $data = $request->validate([
            'phone' => ['required', 'string', 'regex:/^\+?[0-9\s\-()]{7,20}$/'],
        ], ['phone.regex' => 'Enter a valid phone number, e.g. +92 300 1234567.']);

        $phone = preg_replace('/[\s\-()]/', '', $data['phone']);

        $taken = Customer::where('restaurant_id', $customer->restaurant_id)
            ->where('phone', $phone)
            ->where('id', '!=', $customer->id)
            ->exists();
        if ($taken) {
            throw ValidationException::withMessages([
                'phone' => ['This number belongs to another account. Sign in with that account (phone and password), or use a different number.'],
            ]);
        }

        if ($customer->phone !== $phone) {
            $customer->forceFill(['phone' => $phone, 'phone_verified_at' => null])->save();
        }

        return ApiResponse::success($this->serialize($customer->fresh()));
    }

    /** Resolved restaurant if it exists and is open for business, otherwise the error response. */
    private function activeRestaurant(Request $request): Restaurant|\Illuminate\Http\JsonResponse
    {
        $restaurant = $this->resolveRestaurant($request);
        if (! $restaurant) {
            return ApiResponse::error('NOT_FOUND', 'No restaurant is configured for this server URL.', 404);
        }
        if (! $restaurant->isOperational()) {
            return ApiResponse::error('RESTAURANT_INACTIVE', 'This restaurant is not currently active.', 403);
        }

        return $restaurant;
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::success(['message' => 'Logged out.']);
    }

    public function me(Request $request)
    {
        return ApiResponse::success($this->serialize($request->user()->loadMissing('addresses')));
    }

    private function resolveRestaurant(Request $request): ?Restaurant
    {
        return $this->restaurants->resolve($request);
    }

    private function serialize(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'has_phone' => filled($customer->phone),
            'signed_in_with' => array_values(array_filter([
                $customer->google_id ? 'google' : null,
                $customer->apple_id ? 'apple' : null,
                $customer->password ? 'password' : null,
            ])),
            'restaurant_id' => $customer->restaurant_id,
            'addresses' => $customer->relationLoaded('addresses') ? $customer->addresses : null,
        ];
    }
}
