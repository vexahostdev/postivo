<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Pengaturan platform (tabel platform_settings), di-cache 60 detik. */
class PlatformSettings
{
    public const DEFAULTS = [
        'signups_open' => true,
        'announcement' => '',
        'abuse_auto_suspend' => false,
        'abuse_min_volume' => 50,
        'abuse_bounce_warn' => 3.0,
        'abuse_bounce_danger' => 5.0,
        'abuse_complaint_warn' => 0.1,
        'abuse_complaint_danger' => 0.3,
    ];

    public static function all(): array
    {
        return Cache::remember('postivo:settings:v1', 60, function () {
            try {
                $rows = DB::table('platform_settings')->pluck('value', 'key')->map(fn ($v) => json_decode($v, true))->all();
            } catch (Throwable) {
                $rows = [];
            }

            return array_merge(self::DEFAULTS, $rows);
        });
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default ?? (self::DEFAULTS[$key] ?? null);
    }

    public static function set(string $key, mixed $value): void
    {
        DB::table('platform_settings')->updateOrInsert(['key' => $key], ['value' => json_encode($value), 'updated_at' => now()]);
        Cache::forget('postivo:settings:v1');
    }
}
