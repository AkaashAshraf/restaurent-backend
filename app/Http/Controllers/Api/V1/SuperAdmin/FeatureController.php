<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Feature;
use App\Support\ApiResponse;

class FeatureController extends Controller
{
    public function index()
    {
        return ApiResponse::success(Feature::orderBy('key')->get());
    }
}
