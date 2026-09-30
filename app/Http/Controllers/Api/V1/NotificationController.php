<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/**
 * A staff member's own notification inbox — no permission gate beyond
 * being authenticated (same as `GET /auth/me`), since every role should
 * be able to see what's been sent to them regardless of what else they
 * can do. Laravel's own `Notifiable`/`HasDatabaseNotifications` traits
 * (already on `User` from the framework's default scaffolding) scope
 * `notifications()`/`unreadNotifications()` to `notifiable_id = $user->id`
 * automatically — the same "scoped to the acting record, not just the
 * tenant" pattern `CustomerAddressController` uses for a customer's own
 * addresses.
 */
class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $query = $request->user()->notifications();

        if ($request->boolean('unread_only')) {
            $query->whereNull('read_at');
        }

        return ApiResponse::success($query->paginate(20));
    }

    public function unreadCount(Request $request)
    {
        return ApiResponse::success(['unread_count' => $request->user()->unreadNotifications()->count()]);
    }

    /**
     * Raw string (uuid) id, looked up through the user's own
     * `notifications()` relation rather than a global lookup — that's
     * what stops one user from marking (or even seeing) another's
     * notification as read, no separate tenant/ownership check needed.
     */
    public function markRead(Request $request, string $notification)
    {
        $notification = $request->user()->notifications()->findOrFail($notification);
        $notification->markAsRead();

        return ApiResponse::success($notification->fresh());
    }

    public function markAllRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return ApiResponse::success(['updated' => true]);
    }
}
