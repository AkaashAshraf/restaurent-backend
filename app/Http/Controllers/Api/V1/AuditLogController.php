<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $restaurant = $request->user()->restaurant;

        $query = AuditLog::query()->where('restaurant_id', $restaurant->id)->latest('created_at');

        if ($branchId = $request->query('branch_id')) {
            $query->where('branch_id', $branchId);
        }

        if ($action = $request->query('action')) {
            $query->where('action', $action);
        }

        return ApiResponse::success($query->paginate(50));
    }
}
