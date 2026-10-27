<?php

namespace App\Support;

use Illuminate\Support\Str;

class Addr
{
    public static function normalize(string $email): string
    {
        return strtolower(trim($email));
    }

    public static function hash(string $email): string
    {
        return hash('sha256', self::normalize($email));
    }

    /** Parse "Nama <a@b.com>" atau "a@b.com" → [address, name|null]. */
    public static function parse(string $from): array
    {
        if (preg_match('/^\s*(?:"?([^"<]*?)"?\s*)?<([^>]+)>\s*$/', $from, $m)) {
            return [self::normalize($m[2]), trim($m[1]) !== '' ? trim($m[1]) : null];
        }

        return [self::normalize($from), null];
    }

    public static function domainOf(string $email): string
    {
        return Str::after(self::normalize($email), '@');
    }

    /** Token URL-safe 22 karakter (128 bit). */
    public static function token(): string
    {
        return substr(strtr(rtrim(base64_encode(random_bytes(17)), '='), '+/', 'AB'), 0, 22);
    }
}
