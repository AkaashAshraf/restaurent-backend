<?php

use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\BranchHoursController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\ConfigController;
use App\Http\Controllers\Api\V1\CouponController;
use App\Http\Controllers\Api\V1\CustomerAddressController;
use App\Http\Controllers\Api\V1\CustomerAuthController;
use App\Http\Controllers\Api\V1\DealController;
use App\Http\Controllers\Api\V1\ProductHighlightsController;
use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\SuperAdmin\PlatformSettingsController;
use App\Http\Controllers\Api\V1\CustomerOrderController;
use App\Http\Controllers\Api\V1\CustomerPaymentController;
use App\Http\Controllers\Api\V1\DeliveryZoneController;
use App\Http\Controllers\Api\V1\DeviceTokenController;
use App\Http\Controllers\Api\V1\MenuController;
use App\Http\Controllers\Api\V1\ModifierController;
use App\Http\Controllers\Api\V1\ModifierGroupController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\NotificationPreferenceController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\KitchenTicketController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\TableController;
use App\Http\Controllers\Api\V1\SuperAdmin\CustomerAppController;
use App\Http\Controllers\Api\V1\SuperAdmin\DashboardController;
use App\Http\Controllers\Api\V1\SuperAdmin\FeatureController;
use App\Http\Controllers\Api\V1\SuperAdmin\RestaurantController;
use App\Http\Controllers\Api\V1\SuperAdmin\RestaurantInsightsController;
use App\Http\Controllers\Api\V1\SuperAdmin\SubscriptionController;
use App\Http\Controllers\Api\V1\SuperAdmin\SubscriptionPlanController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // Public: generic apps configure a Base URL, then hit this before login.
    Route::get('app/config', [ConfigController::class, 'appConfig']);
    Route::get('app/menu', [MenuController::class, 'publicMenu']);
    Route::get('app/deals', [DealController::class, 'publicIndex']);
    Route::get('app/best-sellers', [ProductHighlightsController::class, 'bestSellers']);
    Route::get('app/tables', [ConfigController::class, 'appTables']);
    Route::get('app/delivery-quote', [ConfigController::class, 'appDeliveryQuote']);
    Route::get('app/delivery-branches', [ConfigController::class, 'appDeliveryBranches']);
    Route::get('app/hours', [ConfigController::class, 'appHours']);

    Route::post('auth/login', [AuthController::class, 'login']);
    // The platform's own name/logo/support contacts (for the sign-in page) and whether it is down.
    Route::get('platform', [PlatformSettingsController::class, 'publicInfo']);

    // ---- Customer app/website (Phase 6): its own auth, resolved by the
    // same Host/?restaurant=slug mechanism as the public app/config and
    // app/menu endpoints above (RestaurantResolver) — a Customer isn't
    // tenant-known ahead of login the way a User is.
    Route::post('app/auth/register', [CustomerAuthController::class, 'register']);
    Route::post('app/auth/login', [CustomerAuthController::class, 'login']);
    Route::post('app/auth/google', [CustomerAuthController::class, 'google']);
    Route::post('app/auth/apple', [CustomerAuthController::class, 'apple']);

    // A Customer authenticates via the same Sanctum guard as staff, but
    // `customer.guard` keeps a staff bearer token from ever reaching (or
    // a customer token from ever leaving) this pipeline — see
    // EnsureActorIsCustomer's docblock.
    Route::middleware(['auth:sanctum', 'customer.guard'])->prefix('customer')->group(function () {
        Route::post('auth/logout', [CustomerAuthController::class, 'logout']);
        Route::get('me', [CustomerAuthController::class, 'me']);
        Route::put('phone', [CustomerAuthController::class, 'updatePhone']);

        Route::middleware(['tenant', 'restaurant.active', 'subscription.active', 'feature:CUSTOMER_APP'])->group(function () {
            Route::get('addresses', [CustomerAddressController::class, 'index']);
            Route::post('addresses', [CustomerAddressController::class, 'store']);
            Route::patch('addresses/{address}', [CustomerAddressController::class, 'update']);
            Route::delete('addresses/{address}', [CustomerAddressController::class, 'destroy']);

            Route::get('favorites', [ProductHighlightsController::class, 'favorites']);
            Route::get('orders', [CustomerOrderController::class, 'index']);
            Route::get('orders/{order}', [CustomerOrderController::class, 'show']);
            Route::post('orders/{order}/review', [ReviewController::class, 'store']);
            // Browsing/loyalty could exist without accepting online
            // orders, so placing one needs its own, narrower feature gate
            // on top of the broader "customer app is enabled" one above.
            Route::middleware('feature:ONLINE_ORDERING')->post('orders', [CustomerOrderController::class, 'store']);

            // ---- Payments (Phase 7): a customer only ever pays online —
            // cash/card stay staff-only (PaymentController). Viewing an
            // order's payment history doesn't need the gate below (it's
            // just visibility into something already gated at creation
            // time); initiating/confirming a new one does.
            Route::get('orders/{order}/payments', [CustomerPaymentController::class, 'index']);
            Route::middleware('feature:ONLINE_PAYMENTS')->group(function () {
                Route::post('orders/{order}/payments', [CustomerPaymentController::class, 'store']);
                Route::patch('payments/{payment}/confirm', [CustomerPaymentController::class, 'confirm']);
            });

            // ---- Notifications & preferences (Phase 10): the exact
            // same controllers the staff pipeline uses just below —
            // NotificationController/NotificationPreferenceController/
            // DeviceTokenController don't hardcode `User` anywhere, so a
            // customer's own inbox/preferences/device tokens are the
            // same code path, just reached through this customer-scoped,
            // CUSTOMER_APP-gated group instead of the staff one.
            // NotificationPreferenceService itself narrows which events
            // a customer can even set a preference for (order.placed/
            // order.status_changed only — never rider_assigned/
            // payment.received, which a customer is never sent).
            Route::get('notifications', [NotificationController::class, 'index']);
            Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
            Route::patch('notifications/read-all', [NotificationController::class, 'markAllRead']);
            Route::patch('notifications/{notification}/read', [NotificationController::class, 'markRead']);
            Route::get('notification-preferences', [NotificationPreferenceController::class, 'index']);
            Route::put('notification-preferences', [NotificationPreferenceController::class, 'update']);
            Route::post('device-tokens', [DeviceTokenController::class, 'store']);
            Route::delete('device-tokens', [DeviceTokenController::class, 'destroy']);
        });
    });

    // Staff-facing routes below assume $request->user() is a
    // App\Models\User — enforced explicitly so a customer's bearer token
    // can never reach here and crash on a missing staff-only method
    // (hasPermission(), canAccessBranch(), ...) instead of a clean 403.
    Route::middleware(['auth:sanctum', 'staff.guard'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        // ---- Notifications (Phase 8): a staff member's own inbox — no
        // permission gate, no tenant/subscription check, same reasoning
        // as auth/me above. Scoped entirely by Laravel's own Notifiable
        // relations (notifiable_id = the authenticated user), not by
        // TenantContext, so it works identically regardless of
        // restaurant/subscription status.
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::patch('notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::patch('notifications/{notification}/read', [NotificationController::class, 'markRead']);

        // ---- Notification preferences & device tokens (Phase 9): same
        // "your own setup, no gate beyond auth" reasoning as the
        // notifications routes just above. Preferences control whether
        // mail/sms/push fire for a given event on top of the always-on
        // `database` channel; device tokens are what PushChannel fans
        // out to.
        Route::get('notification-preferences', [NotificationPreferenceController::class, 'index']);
        Route::put('notification-preferences', [NotificationPreferenceController::class, 'update']);
        Route::post('device-tokens', [DeviceTokenController::class, 'store']);
        Route::delete('device-tokens', [DeviceTokenController::class, 'destroy']);

        // ---- Super Admin: platform-level, deliberately outside tenant scoping ----
        Route::prefix('super-admin')->middleware('super_admin')->group(function () {
            Route::get('dashboard', [DashboardController::class, 'index']);

            Route::get('restaurants', [RestaurantController::class, 'index']);
            Route::post('restaurants', [RestaurantController::class, 'store']);
            Route::get('restaurants/{restaurant}', [RestaurantController::class, 'show']);
            Route::patch('restaurants/{restaurant}', [RestaurantController::class, 'update']);
            Route::patch('restaurants/{restaurant}/status', [RestaurantController::class, 'updateStatus']);
            Route::patch('restaurants/{restaurant}/features/{feature}', [RestaurantController::class, 'toggleFeatureOverride']);

            // Read-only look inside one restaurant: staff, customers, orders, sales, products.
            Route::get('restaurants/{restaurant}/insights', [RestaurantInsightsController::class, 'overview']);
            Route::get('restaurants/{restaurant}/staff', [RestaurantInsightsController::class, 'staff']);
            Route::get('restaurants/{restaurant}/customers', [RestaurantInsightsController::class, 'customers']);
            Route::get('restaurants/{restaurant}/orders', [RestaurantInsightsController::class, 'orders']);
            Route::get('restaurants/{restaurant}/sales', [RestaurantInsightsController::class, 'sales']);
            Route::get('restaurants/{restaurant}/products', [RestaurantInsightsController::class, 'products']);
            Route::get('restaurants/{restaurant}/products/{product}', [RestaurantInsightsController::class, 'product'])->whereNumber('product');

            // The restaurant's own white-label customer app: its key and branding.
            Route::get('restaurants/{restaurant}/customer-app', [CustomerAppController::class, 'show']);
            Route::put('restaurants/{restaurant}/customer-app/branding', [CustomerAppController::class, 'updateBranding']);
            Route::post('restaurants/{restaurant}/customer-app/key', [CustomerAppController::class, 'generateKey']);
            Route::delete('restaurants/{restaurant}/customer-app/key', [CustomerAppController::class, 'revokeKey']);
            Route::post('restaurants/{restaurant}/customer-app/assets', [CustomerAppController::class, 'uploadAsset']);

            Route::get('subscription-plans', [SubscriptionPlanController::class, 'index']);
            Route::post('subscription-plans', [SubscriptionPlanController::class, 'store']);
            Route::patch('subscription-plans/{subscriptionPlan}', [SubscriptionPlanController::class, 'update']);

            Route::post('restaurants/{restaurant}/subscription', [SubscriptionController::class, 'assign']);
            Route::patch('subscriptions/{subscription}', [SubscriptionController::class, 'update']);
            Route::patch('subscriptions/{subscription}/features/{feature}', [SubscriptionController::class, 'toggleFeature']);

            Route::get('features', [FeatureController::class, 'index']);

            // Super Admin settings: platform branding, security switches, my account.
            Route::get('settings', [PlatformSettingsController::class, 'show']);
            Route::patch('settings', [PlatformSettingsController::class, 'update']);
            Route::post('settings/logo', [PlatformSettingsController::class, 'uploadLogo']);
            Route::delete('settings/logo', [PlatformSettingsController::class, 'removeLogo']);
            Route::patch('settings/account', [PlatformSettingsController::class, 'updateAccount']);
            Route::put('settings/account/password', [PlatformSettingsController::class, 'changePassword']);
            Route::post('settings/account/sign-out-others', [PlatformSettingsController::class, 'signOutOtherSessions']);
        });

        // ---- Restaurant-scoped: tenant context + restaurant status enforced first ----
        Route::middleware(['tenant', 'restaurant.active'])->group(function () {
            Route::get('config', [ConfigController::class, 'config']);

            Route::middleware('subscription.active')->group(function () {

                Route::middleware('permission:branches.view')->group(function () {
                    Route::get('branches', [BranchController::class, 'index']);
                    Route::get('branches/{branch}', [BranchController::class, 'show'])->middleware('branch.access:branch');
                    Route::get('branches/{branch}/hours', [BranchHoursController::class, 'show'])->middleware('branch.access:branch');
                });
                Route::middleware('permission:branches.create')->post('branches', [BranchController::class, 'store']);
                Route::middleware(['permission:branches.update', 'branch.access:branch'])->group(function () {
                    Route::patch('branches/{branch}', [BranchController::class, 'update']);
                    Route::patch('branches/{branch}/status', [BranchController::class, 'updateStatus']);
                    Route::put('branches/{branch}/hours', [BranchHoursController::class, 'update']);
                    Route::patch('branches/{branch}/settings', [SettingsController::class, 'updateBranchSettings']);
                });

                Route::middleware('permission:users.view')->group(function () {
                    Route::get('users', [UserController::class, 'index']);
                    Route::get('users/{user}', [UserController::class, 'show']);
                });
                Route::middleware('permission:users.create')->post('users', [UserController::class, 'store']);
                Route::middleware('permission:users.update')->patch('users/{user}', [UserController::class, 'update']);

                Route::middleware('permission:roles.view')->group(function () {
                    Route::get('roles', [RoleController::class, 'index']);
                    Route::get('permissions', [RoleController::class, 'permissions']);
                });
                Route::middleware('permission:roles.create')->post('roles', [RoleController::class, 'store']);
                Route::middleware('permission:roles.update')->patch('roles/{role}', [RoleController::class, 'update']);

                Route::middleware('permission:settings.view')->get('settings', [SettingsController::class, 'show']);
                Route::middleware('permission:settings.update')->group(function () {
                    Route::patch('settings', [SettingsController::class, 'update']);
                    Route::patch('settings/ordering', [SettingsController::class, 'updateOrdering']);
                });

                // ---- Reports (Phase 8): the REPORTS feature key has
                // existed since Phase 1 (seeded onto Standard/Premium) but
                // was never actually enforced anywhere — audit-logs was
                // reachable by `reports.view` alone. Closed here rather
                // than left as a known gap, since Phase 8 is the natural
                // place to make REPORTS mean something: every report
                // endpoint below, including the pre-existing audit-logs
                // one, now needs both the feature and the permission.
                Route::middleware(['feature:REPORTS', 'permission:reports.view'])->group(function () {
                    Route::get('audit-logs', [AuditLogController::class, 'index']);
                    Route::get('reports/sales', [ReportController::class, 'sales']);
                    Route::get('reports/payments', [ReportController::class, 'payments']);
                    Route::get('reports/top-products', [ReportController::class, 'topProducts']);
                    Route::get('reports/coupons', [ReportController::class, 'coupons']);
                });

                Route::middleware('permission:menu.view')->group(function () {
                    Route::get('menu', [MenuController::class, 'menu']);
                    Route::get('categories', [CategoryController::class, 'index']);
                    Route::get('categories/{category}', [CategoryController::class, 'show']);
                    Route::get('products', [ProductController::class, 'index']);
                    Route::get('deals', [DealController::class, 'index']);
                    Route::get('deals/{deal}', [DealController::class, 'show']);
                    Route::get('products/{product}', [ProductController::class, 'show']);
                    Route::get('modifier-groups', [ModifierGroupController::class, 'index']);
                    Route::get('modifier-groups/{modifierGroup}', [ModifierGroupController::class, 'show']);
                });
                Route::middleware('permission:menu.create')->group(function () {
                    Route::post('categories', [CategoryController::class, 'store']);
                    Route::post('products', [ProductController::class, 'store']);
                    Route::post('deals', [DealController::class, 'store']);
                    Route::post('deals/image', [DealController::class, 'uploadImage']);
                    Route::post('modifier-groups', [ModifierGroupController::class, 'store']);
                    Route::post('modifier-groups/{modifierGroup}/modifiers', [ModifierController::class, 'store']);
                });
                Route::middleware('permission:menu.update')->group(function () {
                    Route::patch('categories/{category}', [CategoryController::class, 'update']);
                    Route::patch('products/{product}', [ProductController::class, 'update']);
                    Route::patch('deals/{deal}', [DealController::class, 'update']);
                    Route::post('deals/{deal}/notify', [DealController::class, 'notify']);
                    Route::patch('products/{product}/modifier-groups', [ProductController::class, 'updateModifierGroups']);
                    Route::patch('modifier-groups/{modifierGroup}', [ModifierGroupController::class, 'update']);
                    Route::patch('modifiers/{modifier}', [ModifierController::class, 'update']);

                    Route::middleware('branch.access:branch')
                        ->patch('products/{product}/branches/{branch}', [ProductController::class, 'updateBranchOverride']);
                });
                Route::middleware('permission:menu.delete')->group(function () {
                    Route::delete('categories/{category}', [CategoryController::class, 'destroy']);
                    Route::delete('products/{product}', [ProductController::class, 'destroy']);
                    Route::delete('deals/{deal}', [DealController::class, 'destroy']);
                    Route::delete('modifier-groups/{modifierGroup}', [ModifierGroupController::class, 'destroy']);
                    Route::delete('modifiers/{modifier}', [ModifierController::class, 'destroy']);
                });

                // ---- Tables: gated behind the TABLE_MANAGEMENT feature — a
                // takeaway/delivery-only restaurant doesn't need this at all.
                Route::middleware('feature:TABLE_MANAGEMENT')->group(function () {
                    Route::middleware('permission:tables.view')->group(function () {
                        Route::get('tables', [TableController::class, 'index']);
                        Route::get('tables/{table}', [TableController::class, 'show']);
                    });
                    // No {branch} route segment — branch_id is a body field,
                    // so `branch.access` falls back to input('branch_id').
                    Route::middleware(['permission:tables.create', 'branch.access'])
                        ->post('tables', [TableController::class, 'store']);
                    Route::middleware('permission:tables.update')->patch('tables/{table}', [TableController::class, 'update']);
                    Route::middleware('permission:tables.delete')->delete('tables/{table}', [TableController::class, 'destroy']);
                });

                // ---- Orders: which order TYPE is allowed (feature flag +
                // branch-level order_types setting) is validated inside
                // OrderService per request, since it depends on the
                // submitted order_type — a static route middleware can't
                // know that ahead of time.
                Route::middleware('permission:orders.view')->group(function () {
                    Route::get('reviews', [ReviewController::class, 'index']);
                    Route::get('orders', [OrderController::class, 'index']);
                    Route::get('orders/{order}', [OrderController::class, 'show']);
                });
                Route::middleware(['permission:orders.create', 'branch.access'])
                    ->post('orders', [OrderController::class, 'store']);
                Route::middleware('permission:orders.update')
                    ->patch('orders/{order}/status', [OrderController::class, 'updateStatus']);
                // Kitchen "undo": back one step, only until pickup
                // (OrderService::undoStatus enforces that).
                Route::middleware('permission:orders.update')
                    ->patch('orders/{order}/status/undo', [OrderController::class, 'undoStatus']);
                // A waiter can keep adding to an order right up until
                // checkout (OrderService::addItems enforces that, not
                // the route) — same permission as status updates, since
                // both are "I'm still working this order" actions.
                Route::middleware('permission:orders.update')
                    ->post('orders/{order}/items', [OrderController::class, 'addItems']);
                // Returns are allowed even on a COMPLETED order (a
                // customer can find a problem after paying) but never on
                // a CANCELLED one — enforced in OrderService, not here.
                Route::middleware('permission:orders.update')
                    ->post('orders/{order}/items/{item}/returns', [OrderController::class, 'returnItem']);

                // ---- Kitchen tickets: one per round of items on an order
                // (the original items, then anything added later). The
                // kitchen screen moves them New -> Cooking -> Ready; the
                // waiter marks them picked up. See KitchenTicket.
                Route::middleware('permission:orders.view')
                    ->get('kitchen-tickets', [KitchenTicketController::class, 'index']);
                Route::middleware('permission:orders.update')
                    ->patch('kitchen-tickets/{ticket}/status', [KitchenTicketController::class, 'updateStatus']);
                Route::middleware('permission:orders.update')
                    ->patch('kitchen-tickets/{ticket}/undo', [KitchenTicketController::class, 'undo']);
                // Rider assignment is its own permission (not orders.update)
                // and additionally gated on the RIDER_APP feature — a
                // restaurant without delivery/riders shouldn't see this at
                // all, even for staff who could update order status.
                Route::middleware(['permission:orders.assign_rider', 'feature:RIDER_APP'])
                    ->patch('orders/{order}/rider', [OrderController::class, 'assignRider']);

                // ---- Delivery zones (Phase 5): gated behind the DELIVERY
                // feature — geofencing only matters for a restaurant that
                // actually delivers. A branch with no active zone rows is
                // still delivery-eligible everywhere (see OrderService);
                // zones are how a restaurant opts into restricting that.
                Route::middleware('feature:DELIVERY')->group(function () {
                    Route::middleware('permission:delivery-zones.view')->group(function () {
                        Route::get('delivery-zones', [DeliveryZoneController::class, 'index']);
                        Route::get('delivery-zones/{deliveryZone}', [DeliveryZoneController::class, 'show']);
                    });
                    Route::middleware(['permission:delivery-zones.create', 'branch.access'])
                        ->post('delivery-zones', [DeliveryZoneController::class, 'store']);
                    Route::middleware('permission:delivery-zones.update')
                        ->patch('delivery-zones/{deliveryZone}', [DeliveryZoneController::class, 'update']);
                    Route::middleware('permission:delivery-zones.delete')
                        ->delete('delivery-zones/{deliveryZone}', [DeliveryZoneController::class, 'destroy']);
                });

                // ---- Coupons (Phase 7): restaurant-wide (see
                // CouponController), gated behind the COUPONS feature —
                // same "feature flag wraps the permission checks" shape as
                // delivery zones/DELIVERY above.
                Route::middleware('feature:COUPONS')->group(function () {
                    Route::middleware('permission:coupons.view')->group(function () {
                        Route::get('coupons', [CouponController::class, 'index']);
                        Route::get('coupons/{coupon}', [CouponController::class, 'show']);
                    });
                    Route::middleware('permission:coupons.create')->post('coupons', [CouponController::class, 'store']);
                    Route::middleware('permission:coupons.update')->patch('coupons/{coupon}', [CouponController::class, 'update']);
                    Route::middleware('permission:coupons.delete')->delete('coupons/{coupon}', [CouponController::class, 'destroy']);
                });

                // ---- Payments (Phase 7): CASH/CARD are always available to
                // anyone with the permission, no feature flag — collecting
                // money at the till is core POS behavior, not a paid
                // add-on. ONLINE shares this same endpoint (method is a
                // body field) and is gated on ONLINE_PAYMENTS inside
                // PaymentService itself, not the route, since a static
                // route middleware can't see the submitted method ahead of
                // time (identical reasoning to order_type on /orders above).
                Route::middleware('permission:payments.view')->get('orders/{order}/payments', [PaymentController::class, 'index']);
                Route::middleware('permission:payments.create')->post('orders/{order}/payments', [PaymentController::class, 'store']);
                Route::middleware('permission:payments.confirm')->patch('payments/{payment}/confirm', [PaymentController::class, 'confirm']);
                Route::middleware('permission:payments.refund')->patch('payments/{payment}/refund', [PaymentController::class, 'refund']);
            });
        });
    });
});
