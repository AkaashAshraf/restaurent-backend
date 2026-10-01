<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function show(Request $request)
    {
        $restaurant = $request->user()->restaurant()->with('settings')->first();

        return ApiResponse::success([
            'restaurant' => $restaurant->only([
                'id', 'name', 'legal_name', 'logo', 'description', 'phone', 'email',
                'website', 'address', 'city', 'country', 'currency', 'timezone', 'theme',
            ]),
            'settings' => $restaurant->settings,
        ]);
    }

    public function update(Request $request)
    {
        $restaurant = $request->user()->restaurant;

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'logo' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string'],
            'country' => ['nullable', 'string'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'timezone' => ['sometimes', 'string'],
            'theme' => ['nullable', 'array'],
            'theme.primaryColor' => ['nullable', 'string'],
            'theme.secondaryColor' => ['nullable', 'string'],
        ]);

        $before = $restaurant->only(array_keys($data));
        $restaurant->update($data);

        AuditLog::create([
            'restaurant_id' => $restaurant->id,
            'user_id' => $request->user()->id,
            'action' => 'settings.updated',
            'subject_type' => $restaurant::class,
            'subject_id' => $restaurant->id,
            'changes' => ['old' => $before, 'new' => $data],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success($restaurant->fresh());
    }

    public function updateOrdering(Request $request)
    {
        $restaurant = $request->user()->restaurant;

        $data = $request->validate([
            'order_types' => ['sometimes', 'array'],
            'order_types.*' => ['in:DINE_IN,TAKEAWAY,DELIVERY'],
            'min_order_amount' => ['sometimes', 'numeric', 'min:0'],
            'default_prep_time_minutes' => ['sometimes', 'integer', 'min:0'],
            'tax_enabled' => ['sometimes', 'boolean'],
            'tax_percentage' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'tax_inclusive' => ['sometimes', 'boolean'],
            // Tax by payment method — what the restaurant charges on cash and on card.
            'cash_tax_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'card_tax_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // Which kinds of orders the kitchen screen receives.
            'kitchen_order_types' => ['sometimes', 'array', 'min:1'],
            'kitchen_order_types.*' => ['in:DINE_IN,TAKEAWAY,DELIVERY'],
            // Printed on every bill.
            'fbr_number' => ['nullable', 'string', 'max:60'],
            'delivery_enabled' => ['sometimes', 'boolean'],
            'delivery_fee' => ['sometimes', 'numeric', 'min:0'],
            'free_delivery_threshold' => ['nullable', 'numeric', 'min:0'],
        ]);

        $settings = $restaurant->settings()->firstOrCreate([]);

        // The single "tax_percentage" stays as the rate used before the customer
        // picks how to pay — keep it in step with the cash rate.
        if (array_key_exists('cash_tax_percentage', $data) && $data['cash_tax_percentage'] !== null) {
            $data['tax_percentage'] = $data['cash_tax_percentage'];
        } elseif (array_key_exists('tax_percentage', $data) && ! array_key_exists('cash_tax_percentage', $data)) {
            $data['cash_tax_percentage'] = $data['tax_percentage'];
        }
        if (array_key_exists('kitchen_order_types', $data)) {
            $data['kitchen_order_types'] = array_values(array_unique($data['kitchen_order_types']));
        }

        $settings->update($data);

        return ApiResponse::success($settings->fresh());
    }

    // Raw int id + manual lookup: see BranchController for why implicit
    // route-model-binding is unsafe for tenant-scoped models here.
    public function updateBranchSettings(Request $request, int $branch)
    {
        $branch = Branch::findOrFail($branch);

        $data = $request->validate([
            'order_types' => ['nullable', 'array'],
            'order_types.*' => ['in:DINE_IN,TAKEAWAY,DELIVERY'],
            'delivery_enabled' => ['nullable', 'boolean'],
            'min_order_amount' => ['nullable', 'numeric', 'min:0'],
            'free_delivery_threshold' => ['nullable', 'numeric', 'min:0'],
            'prep_time_minutes' => ['nullable', 'integer', 'min:0'],
            'max_delivery_distance_km' => ['nullable', 'integer', 'min:0'],
        ]);

        $settings = $branch->settings()->firstOrCreate(['restaurant_id' => $branch->restaurant_id]);
        $settings->update($data);

        return ApiResponse::success($settings->fresh());
    }
}
