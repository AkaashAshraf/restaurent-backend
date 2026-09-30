<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\EnsureActorIsCustomer;
use App\Http\Middleware\EnsureActorIsStaff;
use App\Http\Middleware\EnsureBranchAccess;
use App\Http\Middleware\EnsureFeatureEnabled;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureRestaurantActive;
use App\Http\Middleware\EnsureSubscriptionActive;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\IdentifyTenant;
use App\Support\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Pure Bearer-token API (generic apps use a configurable Base URL,
        // not first-party SPA cookies), so Sanctum's stateful/session
        // middleware is intentionally not enabled here.
        $middleware->alias([
            'tenant' => IdentifyTenant::class,
            'restaurant.active' => EnsureRestaurantActive::class,
            'subscription.active' => EnsureSubscriptionActive::class,
            'feature' => EnsureFeatureEnabled::class,
            'permission' => EnsurePermission::class,
            'branch.access' => EnsureBranchAccess::class,
            'super_admin' => EnsureSuperAdmin::class,
            'staff.guard' => EnsureActorIsStaff::class,
            'customer.guard' => EnsureActorIsCustomer::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(fn () => true);

        $exceptions->render(function (ApiException $e) {
            return $e->render();
        });

        $exceptions->render(function (AuthenticationException $e) {
            return ApiResponse::error('UNAUTHORIZED', 'Authentication required.', 401);
        });

        $exceptions->render(function (AuthorizationException $e) {
            return ApiResponse::error('FORBIDDEN', $e->getMessage() ?: 'This action is unauthorized.', 403);
        });

        $exceptions->render(function (ValidationException $e) {
            return ApiResponse::error('VALIDATION_ERROR', 'The given data was invalid.', 422, [
                'errors' => $e->errors(),
            ]);
        });

        $exceptions->render(function (ModelNotFoundException $e) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', 404);
        });

        $exceptions->render(function (NotFoundHttpException $e) {
            return ApiResponse::error('NOT_FOUND', 'The requested endpoint was not found.', 404);
        });
    })->create();
