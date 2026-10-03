<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-wide settings the Super Admin controls: how the platform presents
 * itself (name, logo, support contacts) and the security switches
 * (maintenance mode, staff session length). Stored as key/value rows.
 */
class PlatformSettings
{
    public const DEFAULTS = [
        'platform_name' => 'Restaurant Platform',
        'logo_url' => null,
        'support_email' => null,
        'support_phone' => null,
        'maintenance_mode' => false,
        'maintenance_message' => null,
        'session_days' => 0, // 0 = staff stay signed in until they sign out
    ];

    private const BIND = 'platform.settings.cache';

    public static function all(): array
    {
        // Cached on the app container, so each request (or test) starts fresh.
        if (app()->bound(self::BIND)) {
            return app(self::BIND);
        }

        $stored = [];
        try {
            if (Schema::hasTable('platform_settings')) {
                foreach (DB::table('platform_settings')->get() as $row) {
                    $stored[$row->key] = json_decode($row->value, true);
                }
            }
        } catch (\Throwable) {
            // Before the migration has run the defaults apply.
        }

        $merged = array_merge(self::DEFAULTS, array_intersect_key($stored, self::DEFAULTS));
        $merged['logo_url'] = CustomerAppBranding::url($merged['logo_url']);

        app()->instance(self::BIND, $merged);

        return $merged;
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key] ?? null;
    }

    /** Saves only known keys; `null` resets a key to its default. */
    public static function set(array $values): void
    {
        foreach (array_intersect_key($values, self::DEFAULTS) as $key => $value) {
            if ($value === null) {
                DB::table('platform_settings')->where('key', $key)->delete();
                continue;
            }
            DB::table('platform_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => json_encode($value), 'updated_at' => now(), 'created_at' => now()]
            );
        }
        self::flush();
    }

    public static function flush(): void
    {
        app()->forgetInstance(self::BIND);
    }

    public static function inMaintenance(): bool
    {
        return (bool) self::get('maintenance_mode');
    }

    public static function maintenanceMessage(): string
    {
        return (string) (self::get('maintenance_message') ?: 'The platform is down for maintenance. Please try again shortly.');
    }

    /** What anyone (even signed out) may see: branding and whether we're down. */
    public static function publicView(): array
    {
        $a = self::all();

        return [
            'platform_name' => $a['platform_name'],
            'logo_url' => $a['logo_url'],
            'support_email' => $a['support_email'],
            'support_phone' => $a['support_phone'],
            'maintenance_mode' => (bool) $a['maintenance_mode'],
            'maintenance_message' => $a['maintenance_mode'] ? self::maintenanceMessage() : null,
        ];
    }
}
