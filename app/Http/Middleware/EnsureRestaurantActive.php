<?php

namespace App\Http\Middleware;

use App\Enums\RestaurantStatus;
use App\Exceptions\RestaurantInactiveException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRestaurantActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $restaurant = $user?->restaurant;

        if ($restaurant === null) {
            return $next($request);
        }

        if ($restaurant->status === RestaurantStatus::SUSPENDED) {
            throw new RestaurantInactiveException('This restaurant has been suspended.');
        }

        if ($restaurant->status === RestaurantStatus::EXPIRED) {
            throw new RestaurantInactiveException('This restaurant\'s account has expired.');
        }

        if ($restaurant->status === RestaurantStatus::INACTIVE) {
            throw new RestaurantInactiveException('This restaurant is inactive.');
        }

        return $next($request);
    }
}
