<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\ApiResponse;
use App\Support\CustomerAppBranding;
use App\Support\PlatformSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Super admin: "Settings" — their own account, how the platform presents
 * itself, and the platform-wide security switches.
 */
class PlatformSettingsController extends Controller
{
    /** Public: the platform's name/logo/support contacts, and whether it is down. */
    public function publicInfo()
    {
        return ApiResponse::success(PlatformSettings::publicView());
    }

    public function show(Request $request)
    {
        return ApiResponse::success($this->payload($request));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'platform_name' => ['sometimes', 'required', 'string', 'max:80'],
            'support_email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'support_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'maintenance_mode' => ['sometimes', 'boolean'],
            'maintenance_message' => ['sometimes', 'nullable', 'string', 'max:300'],
            'session_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
        ]);

        // A blank string means "go back to the default".
        foreach (['support_email', 'support_phone', 'maintenance_message'] as $key) {
            if (array_key_exists($key, $data) && trim((string) $data[$key]) === '') {
                $data[$key] = null;
            }
        }
        if (array_key_exists('session_days', $data) && (int) $data['session_days'] === 0) {
            $data['session_days'] = null;
        }

        $before = PlatformSettings::all();
        PlatformSettings::set($data);
        $this->audit($request, 'platform.settings_updated', ['old' => $before, 'new' => $data]);

        return ApiResponse::success($this->payload($request));
    }

    public function uploadLogo(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:png,jpg,jpeg,webp,svg', 'max:2048']]);

        $file = $request->file('file');
        $path = $file->storeAs('platform', 'logo-' . Str::lower(Str::random(10)) . '.' . $file->extension(), 'public');
        $url = CustomerAppBranding::url('/storage/' . $path);

        PlatformSettings::set(['logo_url' => $url]);
        $this->audit($request, 'platform.logo_uploaded', ['url' => $url]);

        return ApiResponse::success($this->payload($request), 201);
    }

    public function removeLogo(Request $request)
    {
        PlatformSettings::set(['logo_url' => null]);
        $this->audit($request, 'platform.logo_removed', []);

        return ApiResponse::success($this->payload($request));
    }

    // ------------------------------------------------------------ my account

    public function updateAccount(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
        ]);

        $user->forceFill($data)->save();
        $this->audit($request, 'platform.account_updated', $data);

        return ApiResponse::success($this->payload($request));
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'different:current_password'],
        ]);

        $user = $request->user();
        if (! Hash::check($data['current_password'], $user->password)) {
            return ApiResponse::error('VALIDATION_ERROR', 'The given data was invalid.', 422, [
                'errors' => ['current_password' => ['Your current password is not correct.']],
            ]);
        }

        $user->forceFill(['password' => $data['password']])->save();
        // Every other device signed in as this account is signed out.
        $user->tokens()->where('id', '!=', $request->user()->currentAccessToken()->id)->delete();
        $this->audit($request, 'platform.password_changed', []);

        return ApiResponse::success(['message' => 'Password changed. Other sessions were signed out.']);
    }

    public function signOutOtherSessions(Request $request)
    {
        $count = $request->user()->tokens()->where('id', '!=', $request->user()->currentAccessToken()->id)->delete();
        $this->audit($request, 'platform.sessions_revoked', ['count' => $count]);

        return ApiResponse::success(['signed_out' => $count]);
    }

    // --------------------------------------------------------------- helpers

    private function payload(Request $request): array
    {
        $user = $request->user();

        return [
            'account' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'platform' => PlatformSettings::all(),
            'sessions' => $user->tokens()->count(),
        ];
    }

    private function audit(Request $request, string $action, array $changes): void
    {
        AuditLog::create([
            'restaurant_id' => null,
            'user_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => 'platform',
            'subject_id' => null,
            'changes' => $changes,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);
    }
}
