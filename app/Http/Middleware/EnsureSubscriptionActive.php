<?php

namespace App\Http\Middleware;

use App\Exceptions\SubscriptionExpiredException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSubscriptionActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $restaurant = $user?->restaurant;

        if ($restaurant === null) {
            return $next($request);
        }

        $subscription = $restaurant->activeSubscription()->first();

        if (! $subscription || ! $subscription->isCurrentlyActive()) {
            throw new SubscriptionExpiredException;
        }

        return $next($request);
    }
}
