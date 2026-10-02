<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Restaurant;
use App\Support\ApiResponse;
use App\Support\CustomerAppBranding;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Super admin: everything about one restaurant's white-label customer app —
 * the key that points a build of the app at that restaurant, and its
 * branding (name, colours, logo, icon, banners, fonts, wording).
 */
class CustomerAppController extends Controller
{
    public function show(Restaurant $restaurant)
    {
        return ApiResponse::success($this->payload($restaurant));
    }

    public function updateBranding(Request $request, Restaurant $restaurant)
    {
        $data = $request->validate(CustomerAppBranding::rules());

        // Blank strings mean "use the default" — drop them so they don't
        // shadow the fallbacks in CustomerAppBranding::resolve().
        $clean = $this->prune($data);

        $before = $restaurant->app_branding;
        $restaurant->app_branding = $clean ?: null;
        $restaurant->save();

        $this->audit($request, $restaurant, 'customer_app.branding_updated', ['old' => $before, 'new' => $clean]);

        return ApiResponse::success($this->payload($restaurant->fresh()));
    }

    /** Creates the key, or replaces it — the old one stops working at once. */
    public function generateKey(Request $request, Restaurant $restaurant)
    {
        $had = $restaurant->app_key !== null;

        do {
            $key = 'rk_' . Str::lower(Str::random(24));
        } while (Restaurant::where('app_key', $key)->exists());

        $restaurant->forceFill(['app_key' => $key])->save();

        $this->audit($request, $restaurant, $had ? 'customer_app.key_rotated' : 'customer_app.key_created', []);

        return ApiResponse::success($this->payload($restaurant->fresh()), $had ? 200 : 201);
    }

    /** Turns the customer app off for this restaurant. */
    public function revokeKey(Request $request, Restaurant $restaurant)
    {
        $restaurant->forceFill(['app_key' => null])->save();

        $this->audit($request, $restaurant, 'customer_app.key_revoked', []);

        return ApiResponse::success($this->payload($restaurant->fresh()));
    }

    /**
     * Uploads an image for the app and returns its public URL. `logo`, `icon`
     * and `splash` are also saved into the branding; `banner` is appended to
     * the banner list.
     */
    public function uploadAsset(Request $request, Restaurant $restaurant)
    {
        $data = $request->validate([
            'type' => ['required', 'in:' . implode(',', CustomerAppBranding::ASSET_TYPES)],
            'file' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:4096'],
        ]);

        // The launcher icon has to be a big square or the stores reject it.
        if ($data['type'] === 'icon') {
            $request->validate(['file' => ['dimensions:min_width=512,min_height=512,ratio=1/1']]);
        }

        $file = $request->file('file');
        $path = $file->storeAs(
            "restaurants/{$restaurant->id}/app",
            $data['type'] . '-' . Str::lower(Str::random(10)) . '.' . $file->extension(),
            'public'
        );
        $url = CustomerAppBranding::url('/storage/' . $path);

        $branding = $restaurant->app_branding ?: [];
        if ($data['type'] === 'banner') {
            $branding['banner_urls'] = array_slice(array_merge($branding['banner_urls'] ?? [], [$url]), 0, 6);
        } else {
            $branding[$data['type'] . '_url'] = $url;
        }
        $restaurant->app_branding = $branding;
        $restaurant->save();

        $this->audit($request, $restaurant, 'customer_app.asset_uploaded', ['type' => $data['type'], 'url' => $url]);

        return ApiResponse::success(['url' => $url, 'type' => $data['type']] + $this->payload($restaurant->fresh()), 201);
    }

    private function payload(Restaurant $restaurant): array
    {
        return [
            'restaurant' => ['id' => $restaurant->id, 'name' => $restaurant->name, 'slug' => $restaurant->slug, 'status' => $restaurant->status],
            'app_key' => $restaurant->app_key,
            'branding' => $restaurant->app_branding ? CustomerAppBranding::withAbsoluteUrls($restaurant->app_branding) : (object) [],
            'resolved' => CustomerAppBranding::resolve($restaurant),
            'options' => [
                'fonts' => CustomerAppBranding::FONTS,
                'theme_modes' => CustomerAppBranding::THEME_MODES,
                'corner_radius' => array_keys(CustomerAppBranding::RADII),
            ],
        ];
    }

    /** Removes null / empty-string leaves so defaults apply. */
    private function prune(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = array_is_list($value) ? array_values(array_filter($value, fn ($v) => $v !== null && $v !== '')) : $this->prune($value);
                if ($value === []) {
                    continue;
                }
            } elseif ($value === null || $value === '') {
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    private function audit(Request $request, Restaurant $restaurant, string $action, array $changes): void
    {
        AuditLog::create([
            'restaurant_id' => $restaurant->id,
            'user_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => Restaurant::class,
            'subject_id' => $restaurant->id,
            'changes' => $changes,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);
    }
}
