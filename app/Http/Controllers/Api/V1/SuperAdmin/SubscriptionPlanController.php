<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Feature;
use App\Models\SubscriptionPlan;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SubscriptionPlanController extends Controller
{
    public function index()
    {
        return ApiResponse::success(SubscriptionPlan::with('features')->orderBy('price')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'branch_limit' => ['nullable', 'integer', 'min:1'],
            'user_limit' => ['nullable', 'integer', 'min:1'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'billing_cycle' => ['sometimes', 'in:MONTHLY,YEARLY'],
            'feature_keys' => ['required', 'array', 'min:1'],
            'feature_keys.*' => ['string', 'exists:features,key'],
        ]);

        $plan = SubscriptionPlan::create([
            'name' => $data['name'],
            'slug' => Str::slug($data['name']),
            'description' => $data['description'] ?? null,
            'branch_limit' => $data['branch_limit'] ?? null,
            'user_limit' => $data['user_limit'] ?? null,
            'price' => $data['price'] ?? 0,
            'billing_cycle' => $data['billing_cycle'] ?? 'MONTHLY',
        ]);

        $featureIds = Feature::whereIn('key', $data['feature_keys'])->pluck('id');
        $plan->features()->sync($featureIds);

        return ApiResponse::success($plan->load('features'), 201);
    }

    public function update(Request $request, SubscriptionPlan $subscriptionPlan)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'branch_limit' => ['nullable', 'integer', 'min:1'],
            'user_limit' => ['nullable', 'integer', 'min:1'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'feature_keys' => ['sometimes', 'array'],
            'feature_keys.*' => ['string', 'exists:features,key'],
        ]);

        $subscriptionPlan->update(array_diff_key($data, ['feature_keys' => null]));

        if (array_key_exists('feature_keys', $data)) {
            $featureIds = Feature::whereIn('key', $data['feature_keys'])->pluck('id');
            $subscriptionPlan->features()->sync($featureIds);
        }

        return ApiResponse::success($subscriptionPlan->fresh('features'));
    }
}
