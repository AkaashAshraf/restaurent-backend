<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Restaurant;
use App\Models\Subscription;
use App\Models\SubscriptionFeature;
use App\Models\SubscriptionPlan;
use App\Services\FeatureService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function assign(Request $request, Restaurant $restaurant)
    {
        $data = $request->validate([
            'subscription_plan_id' => ['required', 'exists:subscription_plans,id'],
            'start_date' => ['required', 'date'],
            'expiry_date' => ['nullable', 'date', 'after:start_date'],
            'branch_limit_override' => ['nullable', 'integer', 'min:1'],
            'user_limit_override' => ['nullable', 'integer', 'min:1'],
        ]);

        // Only one ACTIVE subscription per restaurant at a time.
        Subscription::where('restaurant_id', $restaurant->id)
            ->where('status', 'ACTIVE')
            ->update(['status' => 'CANCELLED']);

        $subscription = Subscription::create($data + [
            'restaurant_id' => $restaurant->id,
            'status' => 'ACTIVE',
        ]);

        // Seed subscription_features from the plan's defaults so Super Admin
        // can override individual features afterwards without touching the
        // plan itself (spec #10/#11).
        $plan = SubscriptionPlan::with('features')->find($data['subscription_plan_id']);
        foreach ($plan->features as $feature) {
            SubscriptionFeature::create([
                'subscription_id' => $subscription->id,
                'feature_id' => $feature->id,
                'enabled' => true,
            ]);
        }

        app(FeatureService::class)->forget($restaurant);

        AuditLog::create([
            'restaurant_id' => $restaurant->id,
            'user_id' => $request->user()->id,
            'action' => 'subscription.assigned',
            'subject_type' => Subscription::class,
            'subject_id' => $subscription->id,
            'changes' => ['new' => $data],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return ApiResponse::success($subscription->load('featureOverrides.feature'), 201);
    }

    public function update(Request $request, Subscription $subscription)
    {
        $data = $request->validate([
            'status' => ['sometimes', 'in:ACTIVE,EXPIRED,SUSPENDED,CANCELLED'],
            'expiry_date' => ['nullable', 'date'],
            'branch_limit_override' => ['nullable', 'integer', 'min:1'],
            'user_limit_override' => ['nullable', 'integer', 'min:1'],
        ]);

        $subscription->update($data);

        app(FeatureService::class)->forget($subscription->restaurant);

        return ApiResponse::success($subscription->fresh());
    }

    public function toggleFeature(Request $request, Subscription $subscription, int $feature)
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        $override = SubscriptionFeature::updateOrCreate(
            ['subscription_id' => $subscription->id, 'feature_id' => $feature],
            ['enabled' => $data['enabled']]
        );

        app(FeatureService::class)->forget($subscription->restaurant);

        return ApiResponse::success($override);
    }
}
