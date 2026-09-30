<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Restaurant;
use App\Models\Subscription;
use App\Support\ApiResponse;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function index()
    {
        return ApiResponse::success([
            'total_restaurants' => Restaurant::count(),
            'active_restaurants' => Restaurant::where('status', 'ACTIVE')->count(),
            'inactive_restaurants' => Restaurant::whereIn('status', ['INACTIVE', 'SUSPENDED'])->count(),
            'expired_restaurants' => Restaurant::where('status', 'EXPIRED')->count(),
            'total_branches' => Branch::withoutTenantScope()->count(),
            'active_subscriptions' => Subscription::where('status', 'ACTIVE')
                ->where(fn ($q) => $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', Carbon::today()))
                ->count(),
            'expired_subscriptions' => Subscription::where('status', 'EXPIRED')
                ->orWhere(fn ($q) => $q->where('status', 'ACTIVE')->where('expiry_date', '<', Carbon::today()))
                ->count(),
        ]);
    }
}
