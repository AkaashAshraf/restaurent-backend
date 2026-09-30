<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Restaurant;
use App\Services\RestaurantResolver;
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
    public function __construct(private RestaurantResolver $restaurants)
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
            'restaurant_id' => $customer->restaurant_id,
            'addresses' => $customer->relationLoaded('addresses') ? $customer->addresses : null,
        ];
    }
}
