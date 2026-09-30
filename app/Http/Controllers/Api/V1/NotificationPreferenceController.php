<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\NotificationPreferenceService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A recipient's own opt-in matrix for mail/sms/push, per event — no
 * permission gate, no tenant/subscription check, same reasoning as
 * NotificationController: everyone should be able to manage what's sent
 * to them regardless of what else they're allowed to do. `database`
 * isn't represented here since it's always on (see
 * NotificationPreferenceService's docblock).
 *
 * Reused as-is for both staff (`/notification-preferences`) and
 * customers (`/customer/notification-preferences`, Phase 10) — nothing
 * here is `User`-specific, `$request->user()` resolves to whichever
 * guard authenticated the request, and NotificationPreferenceService
 * itself branches on the recipient type where it actually matters
 * (which owner column, which event list).
 */
class NotificationPreferenceController extends Controller
{
    public function __construct(private NotificationPreferenceService $preferences)
    {
    }

    public function index(Request $request)
    {
        return ApiResponse::success($this->preferences->effectivePreferences($request->user()));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'preferences' => ['required', 'array', 'min:1'],
            'preferences.*.event_key' => ['required', Rule::in(NotificationPreferenceService::eventsFor($request->user()))],
            'preferences.*.channel' => ['required', Rule::in(NotificationPreferenceService::CHANNELS)],
            'preferences.*.enabled' => ['required', 'boolean'],
        ]);

        $this->preferences->setMany($request->user(), $data['preferences']);

        return ApiResponse::success($this->preferences->effectivePreferences($request->user()));
    }
}
