<?php

namespace App\Services;

/** TOTP RFC 6238 (SHA-1, 6 digit, 30 detik) — kompatibel Google Authenticator/Authy. */
class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 20): string
    {
        $bin = random_bytes($bytes);
        $out = '';
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $out;
    }

    public static function code(string $secret, ?int $time = null): string
    {
        $counter = intdiv($time ?? time(), 30);
        $hash = hash_hmac('sha1', pack('J', $counter), self::decode($secret), true);
        $o = ord($hash[19]) & 0xF;
        $n = ((ord($hash[$o]) & 0x7F) << 24) | (ord($hash[$o + 1]) << 16) | (ord($hash[$o + 2]) << 8) | ord($hash[$o + 3]);

        return str_pad((string) ($n % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    /** Toleransi ±1 langkah (30 dtk) untuk selisih jam. */
    public static function verify(string $secret, string $code, ?int $time = null): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $t = $time ?? time();
        foreach ([-30, 0, 30] as $drift) {
            if (hash_equals(self::code($secret, $t + $drift), $code)) {
                return true;
            }
        }

        return false;
    }

    public static function uri(string $secret, string $account, string $issuer = 'Postivo'): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer).':'.rawurlencode($account).'?secret='.$secret.'&issuer='.rawurlencode($issuer).'&digits=6&period=30';
    }

    private static function decode(string $b32): string
    {
        $bits = '';
        foreach (str_split(strtoupper($b32)) as $c) {
            $p = strpos(self::ALPHABET, $c);
            if ($p !== false) {
                $bits .= str_pad(decbin($p), 5, '0', STR_PAD_LEFT);
            }
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
