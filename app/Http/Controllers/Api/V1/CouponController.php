<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Coupon;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Coupons are restaurant-wide, not per-branch (unlike DeliveryZoneController)
 * — a promo code is a marketing decision made once for the whole
 * restaurant, so this follows CategoryController's "no branch filtering
 * at all" shape rather than DeliveryZoneController's branch-scoped one.
 */
class CouponController extends Controller
{
    public function index(Request $request)
    {
        $coupons = Coupon::query()
            ->when($request->query('is_active') !== null, fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->latest()
            ->get();

        return ApiResponse::success($coupons);
    }

    public function store(Request $request)
    {
        $restaurant = $request->user()->restaurant;
        $data = $this->validated($request);

        $data['code'] = $this->normalizeCode($data['code']);
        $data['is_active'] = $data['is_active'] ?? true;

        if (Coupon::where('code', $data['code'])->exists()) {
            return ApiResponse::error('VALIDATION_ERROR', 'A coupon with this code already exists.', 422);
        }

        $coupon = Coupon::create($data + ['restaurant_id' => $restaurant->id]);

        AuditLog::create([
            'restaurant_id' => $restaurant->id,
            'user_id' => $request->user()->id,
            'action' => 'coupon.created',
            'subject_type' => Coupon::class,
            'subject_id' => $coupon->id,
            'changes' => ['new' => $data],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success($coupon, 201);
    }

    /**
     * Raw int id, not implicit route-model-binding — same reasoning as
     * every other tenant-scoped model in this app (SubstituteBindings
     * runs before the `tenant` middleware sets TenantContext).
     */
    public function show(Request $request, int $coupon)
    {
        $coupon = Coupon::findOrFail($coupon);

        return ApiResponse::success($coupon->load('redemptions'));
    }

    public function update(Request $request, int $coupon)
    {
        $coupon = Coupon::findOrFail($coupon);
        $data = $this->validated($request, updating: true);

        if (isset($data['code'])) {
            $data['code'] = $this->normalizeCode($data['code']);

            if (Coupon::where('code', $data['code'])->where('id', '!=', $coupon->id)->exists()) {
                return ApiResponse::error('VALIDATION_ERROR', 'A coupon with this code already exists.', 422);
            }
        }

        $before = $coupon->only(array_keys($data));
        $coupon->update($data);

        AuditLog::create([
            'restaurant_id' => $coupon->restaurant_id,
            'user_id' => $request->user()->id,
            'action' => 'coupon.updated',
            'subject_type' => Coupon::class,
            'subject_id' => $coupon->id,
            'changes' => ['old' => $before, 'new' => $data],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success($coupon->fresh());
    }

    public function destroy(Request $request, int $coupon)
    {
        $coupon = Coupon::findOrFail($coupon);
        $coupon->delete();

        AuditLog::create([
            'restaurant_id' => $coupon->restaurant_id,
            'user_id' => $request->user()->id,
            'action' => 'coupon.deleted',
            'subject_type' => Coupon::class,
            'subject_id' => $coupon->id,
            'changes' => [],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success(['deleted' => true]);
    }

    private function validated(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return $request->validate([
            'code' => [$required, 'string', 'max:50'],
            'type' => [$required, Rule::in(['PERCENTAGE', 'FIXED'])],
            'value' => [
                $required, 'numeric', 'min:0.01',
                function ($attribute, $value, $fail) use ($request) {
                    if ($request->filled('type') && $request->input('type') === 'PERCENTAGE' && $value > 100) {
                        $fail('A percentage coupon value cannot exceed 100.');
                    }
                },
            ],
            'min_order_amount' => ['nullable', 'numeric', 'min:0'],
            'max_discount_amount' => ['nullable', 'numeric', 'min:0'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'per_customer_limit' => ['nullable', 'integer', 'min:1'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function normalizeCode(string $code): string
    {
        return strtoupper(trim($code));
    }
}
