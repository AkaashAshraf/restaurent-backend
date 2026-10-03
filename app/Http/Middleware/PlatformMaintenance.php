<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use App\Support\PlatformSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While the Super Admin has put the platform in maintenance mode, every API
 * call is refused with 503 — except the Super Admin's own, sign-in (which
 * refuses non-super-admins itself), the public platform info, and the
 * customer app's config (which tells the app to show its "be right back" screen).
 */
class PlatformMaintenance
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! PlatformSettings::inMaintenance()) {
            return $next($request);
        }

        $path = trim($request->path(), '/');
        foreach (['api/v1/platform', 'api/v1/auth/login', 'api/v1/app/config'] as $open) {
            if ($path === $open) {
                return $next($request);
            }
        }

        $user = $request->user('sanctum');
        if ($user && $user->is_super_admin) {
            return $next($request);
        }

        return ApiResponse::error('MAINTENANCE', PlatformSettings::maintenanceMessage(), 503);
    }
}
