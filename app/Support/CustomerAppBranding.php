<?php

namespace App\Support;

use App\Models\Restaurant;

/**
 * The customer app's look and wording for one restaurant. The super admin
 * edits the raw `restaurants.app_branding` JSON; the app receives the
 * resolved version from here — every field filled in (falling back to the
 * restaurant's own name/logo/theme, then to safe defaults) and every asset
 * as an absolute URL, so the app never has to guess.
 */
class CustomerAppBranding
{
    public const FONTS = ['Inter', 'Poppins', 'Roboto', 'Montserrat', 'Lato', 'Nunito', 'Open Sans', 'Playfair Display', 'Merriweather'];

    public const THEME_MODES = ['light', 'dark', 'system'];

    public const RADII = ['sharp' => 6, 'rounded' => 14, 'pill' => 24];

    public const COLOR_KEYS = ['primary', 'secondary', 'accent', 'background', 'surface', 'text'];

    public const ASSET_TYPES = ['logo', 'icon', 'splash', 'banner'];

    /** Validation rules for the editable branding fields. */
    public static function rules(): array
    {
        $hex = ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'];

        return [
            'app_name' => ['nullable', 'string', 'max:60'],
            'tagline' => ['nullable', 'string', 'max:120'],
            'colors' => ['nullable', 'array'],
            'colors.primary' => $hex,
            'colors.secondary' => $hex,
            'colors.accent' => $hex,
            'colors.background' => $hex,
            'colors.surface' => $hex,
            'colors.text' => $hex,
            'theme_mode' => ['nullable', 'in:' . implode(',', self::THEME_MODES)],
            'font' => ['nullable', 'in:' . implode(',', self::FONTS)],
            'corner_radius' => ['nullable', 'in:' . implode(',', array_keys(self::RADII))],
            'logo_url' => ['nullable', 'string', 'max:2048'],
            'icon_url' => ['nullable', 'string', 'max:2048'],
            'splash_url' => ['nullable', 'string', 'max:2048'],
            'banner_urls' => ['nullable', 'array', 'max:6'],
            'banner_urls.*' => ['string', 'max:2048'],
            'welcome_title' => ['nullable', 'string', 'max:80'],
            'welcome_subtitle' => ['nullable', 'string', 'max:200'],
            'about' => ['nullable', 'string', 'max:2000'],
            'contact' => ['nullable', 'array'],
            'contact.phone' => ['nullable', 'string', 'max:40'],
            'contact.whatsapp' => ['nullable', 'string', 'max:40'],
            'contact.email' => ['nullable', 'email', 'max:120'],
            'social' => ['nullable', 'array'],
            'social.instagram' => ['nullable', 'string', 'max:255'],
            'social.facebook' => ['nullable', 'string', 'max:255'],
            'social.website' => ['nullable', 'string', 'max:255'],
            'android_package_id' => ['nullable', 'regex:/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/'],
            'ios_bundle_id' => ['nullable', 'regex:/^[A-Za-z0-9][A-Za-z0-9\-.]*$/', 'max:120'],
            // "Continue with Google": the OAuth client ids of this restaurant's own app.
            // Web client id = the one the app asks Google to issue the token for (also
            // used on Android); iOS client id is only needed on iPhones. "Sign in with
            // Apple" needs no extra id — it uses ios_bundle_id above.
            'google_web_client_id' => ['nullable', 'regex:/^[0-9A-Za-z._\-]+\.apps\.googleusercontent\.com$/', 'max:200'],
            'google_ios_client_id' => ['nullable', 'regex:/^[0-9A-Za-z._\-]+\.apps\.googleusercontent\.com$/', 'max:200'],
            'min_app_version' => ['nullable', 'regex:/^\d+\.\d+\.\d+$/'],
            'force_update' => ['nullable', 'boolean'],
            'maintenance_mode' => ['nullable', 'boolean'],
            'maintenance_message' => ['nullable', 'string', 'max:200'],
        ];
    }

    /** The fully resolved branding the app consumes. */
    public static function resolve(Restaurant $restaurant): array
    {
        $b = $restaurant->app_branding ?: [];
        $theme = $restaurant->theme ?: [];
        $primary = $b['colors']['primary'] ?? $theme['primaryColor'] ?? '#E53935';
        $secondary = $b['colors']['secondary'] ?? $theme['secondaryColor'] ?? '#212121';

        return [
            'app_name' => $b['app_name'] ?? $restaurant->name,
            'tagline' => $b['tagline'] ?? null,
            'colors' => [
                'primary' => $primary,
                'secondary' => $secondary,
                'accent' => $b['colors']['accent'] ?? $secondary,
                'background' => $b['colors']['background'] ?? '#FFFFFF',
                'surface' => $b['colors']['surface'] ?? '#F6F6F8',
                'text' => $b['colors']['text'] ?? '#1A1A1A',
            ],
            'theme_mode' => $b['theme_mode'] ?? 'light',
            'font' => $b['font'] ?? 'Inter',
            'corner_radius' => $b['corner_radius'] ?? 'rounded',
            'corner_radius_px' => self::RADII[$b['corner_radius'] ?? 'rounded'] ?? 14,
            'logo_url' => self::url($b['logo_url'] ?? $restaurant->logo),
            'icon_url' => self::url($b['icon_url'] ?? null),
            'splash_url' => self::url($b['splash_url'] ?? null),
            'banner_urls' => array_values(array_map([self::class, 'url'], $b['banner_urls'] ?? [])),
            'welcome_title' => $b['welcome_title'] ?? 'Welcome to ' . ($b['app_name'] ?? $restaurant->name),
            'welcome_subtitle' => $b['welcome_subtitle'] ?? ($restaurant->description ?: 'Order your favourites in a few taps.'),
            'about' => $b['about'] ?? $restaurant->description,
            'contact' => [
                'phone' => $b['contact']['phone'] ?? $restaurant->phone,
                'whatsapp' => $b['contact']['whatsapp'] ?? null,
                'email' => $b['contact']['email'] ?? $restaurant->email,
            ],
            'social' => [
                'instagram' => $b['social']['instagram'] ?? null,
                'facebook' => $b['social']['facebook'] ?? null,
                'website' => $b['social']['website'] ?? $restaurant->website,
            ],
            'android_package_id' => $b['android_package_id'] ?? null,
            'ios_bundle_id' => $b['ios_bundle_id'] ?? null,
            'min_app_version' => $b['min_app_version'] ?? '1.0.0',
            'force_update' => (bool) ($b['force_update'] ?? false),
            'maintenance_mode' => (bool) ($b['maintenance_mode'] ?? false),
            'maintenance_message' => $b['maintenance_message'] ?? null,
        ];
    }

    /** Relative paths ("/storage/..." or "logos/x.png") become absolute URLs. */
    public static function url(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $value)) {
            return $value;
        }

        return rtrim((string) config('app.url'), '/') . '/' . ltrim($value, '/');
    }
}
