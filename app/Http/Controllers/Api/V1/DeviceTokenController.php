<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\DeviceToken;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/**
 * Registers/unregisters the device token PushChannel sends to. `token`
 * is globally unique: re-registering the same token (e.g. the same
 * phone logging in as a different staff user after a logout, or a
 * device handed from staff to a customer or vice versa) reassigns it
 * rather than erroring, so a shared or reset device never keeps
 * push-notifying whoever used it last — `store()` explicitly clears
 * whichever owner column the token used to belong to. No permission
 * gate — same "your own notification setup" reasoning as
 * NotificationPreferenceController.
 *
 * Reused as-is for both staff (`/device-tokens`) and customers
 * (`/customer/device-tokens`, Phase 10) — `destroy()` needed no change
 * at all, since `$request->user()->deviceTokens()` already resolves to
 * the right relation (and therefore the right FK column) for either
 * model.
 */
class DeviceTokenController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'platform' => ['required', 'in:ANDROID,IOS,WEB'],
        ]);

        $isCustomer = $request->user() instanceof Customer;

        $deviceToken = DeviceToken::updateOrCreate(
            ['token' => $data['token']],
            [
                'user_id' => $isCustomer ? null : $request->user()->id,
                'customer_id' => $isCustomer ? $request->user()->id : null,
                'platform' => $data['platform'],
            ],
        );

        return ApiResponse::success($deviceToken, 201);
    }

    public function destroy(Request $request)
    {
        $data = $request->validate(['token' => ['required', 'string']]);

        $request->user()->deviceTokens()->where('token', $data['token'])->delete();

        return ApiResponse::success(['deleted' => true]);
    }
}
