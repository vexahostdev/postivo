<?php

namespace App\Support;

use App\Services\PlatformSettings;
use Illuminate\Support\Facades\Storage;

/**
 * Identitas visual platform yang dapat diubah dari admin (Admin → Branding).
 * Nilai kosong/tidak valid selalu jatuh ke bawaan, sehingga halaman tidak pernah rusak.
 */
class Brand
{
    public const DEFAULT_ACCENT = '#FE0000';

    public static function name(): string
    {
        return self::text('brand_name') ?: 'Postivo';
    }

    public static function tagline(): string
    {
        return self::text('brand_tagline') ?: 'Email transaksional untuk developer Indonesia';
    }

    public static function logo(): string
    {
        return self::upload('brand_logo') ?: asset('img/logo.png');
    }

    public static function favicon(): string
    {
        return self::upload('brand_favicon') ?: asset('img/favicon.png');
    }

    public static function footer(): string
    {
        return self::text('brand_footer') ?: '© '.date('Y').' postivo. All rights reserved. Created by postivo';
    }

    public static function accent(): string
    {
        $c = (string) PlatformSettings::get('brand_accent', '');

        return self::validHex($c) ? strtoupper($c) : self::DEFAULT_ACCENT;
    }

    /** Hanya aksen merah yang dapat diganti; hover/tint diturunkan dari warna aksen. */
    public static function headStyle(): string
    {
        $a = self::accent();
        if ($a === self::DEFAULT_ACCENT) {
            return '';
        }

        return '<style>:root{--color-red-500:'.$a.';--color-red-600:color-mix(in srgb,'.$a.' 85%,#000);--color-red-50:color-mix(in srgb,'.$a.' 6%,#fff)}</style>';
    }

    public static function validHex(string $c): bool
    {
        return (bool) preg_match('/^#[0-9A-Fa-f]{6}$/', $c);
    }

    private static function text(string $key): string
    {
        return trim((string) PlatformSettings::get($key, ''));
    }

    private static function upload(string $key): ?string
    {
        $path = self::text($key);
        if ($path === '' || str_contains($path, '..')) {
            return null;
        }

        return Storage::disk('public')->exists($path) ? Storage::disk('public')->url($path).'?v='.Storage::disk('public')->lastModified($path) : null;
    }
}
