<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CustomerAddress;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A customer's own saved addresses. Deliberately scoped by `customer_id`
 * on every query, not just the tenant (`restaurant_id`) scope
 * `BelongsToTenant` already gives `CustomerAddress` — two customers of
 * the same restaurant must never be able to read/edit/delete each
 * other's addresses.
 */
class CustomerAddressController extends Controller
{
    public function index(Request $request)
    {
        return ApiResponse::success(
            $request->user()->addresses()->orderByDesc('is_default')->latest()->get()
        );
    }

    public function store(Request $request)
    {
        $customer = $request->user();
        $data = $this->validated($request);

        $address = DB::transaction(function () use ($customer, $data) {
            if (! empty($data['is_default'])) {
                $customer->addresses()->update(['is_default' => false]);
            }

            return $customer->addresses()->create($data);
        });

        return ApiResponse::success($address, 201);
    }

    public function update(Request $request, int $address)
    {
        $customer = $request->user();
        $addr = $this->findOwn($customer, $address);
        $data = $this->validated($request, updating: true);

        DB::transaction(function () use ($customer, $addr, $data) {
            if (! empty($data['is_default'])) {
                $customer->addresses()->where('id', '!=', $addr->id)->update(['is_default' => false]);
            }

            $addr->update($data);
        });

        return ApiResponse::success($addr->fresh());
    }

    public function destroy(Request $request, int $address)
    {
        $addr = $this->findOwn($request->user(), $address);
        $addr->delete();

        return ApiResponse::success(['deleted' => true]);
    }

    private function findOwn($customer, int $addressId): CustomerAddress
    {
        return $customer->addresses()->findOrFail($addressId);
    }

    private function validated(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return $request->validate([
            'label' => ['nullable', 'string', 'max:50'],
            'full_address' => [$required, 'string'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'building' => ['nullable', 'string', 'max:100'],
            'floor' => ['nullable', 'string', 'max:50'],
            'delivery_instructions' => ['nullable', 'string'],
            'is_default' => ['nullable', 'boolean'],
        ]);
    }
}
