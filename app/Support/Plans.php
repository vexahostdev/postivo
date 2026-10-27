<?php

namespace App\Support;

use App\Models\Plan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Katalog plan: default dari config/postivo.php, ditimpa baris tabel `plans` (diedit admin tanpa deploy).
 * Plan dengan is_active=false disembunyikan dari checkout/landing tetapi tetap dikenali untuk org lama.
 */
class Plans
{
    /** @return array<string, array> semua plan (termasuk nonaktif), urut sort lalu harga */
    public static function all(): array
    {
        return Cache::remember('postivo:plans:v1', 300, function () {
            $plans = collect(config('postivo.plans'))->map(fn ($p, $k) => $p + ['key' => $k, 'is_active' => true, 'sort' => 0]);
            try {
                if (Schema::hasTable('plans')) {
                    foreach (Plan::all() as $row) {
                        $base = $plans[$row->key] ?? [];
                        $plans[$row->key] = array_merge($base, [
                            'key' => $row->key, 'name' => $row->name, 'price' => (int) $row->price,
                            'monthly_quota' => (int) $row->monthly_quota, 'daily_limit' => $row->daily_limit,
                            'features' => $row->features ?? ($base['features'] ?? []), 'is_active' => $row->is_active, 'sort' => $row->sort,
                        ]);
                    }
                }
            } catch (Throwable) {
                // DB belum siap (mis. saat migrasi): pakai default config.
            }

            return $plans->sortBy([['sort', 'asc'], ['price', 'asc']])->all();
        });
    }

    /** Hanya yang aktif (untuk tampilan publik/checkout). */
    public static function active(): array
    {
        return array_filter(self::all(), fn ($p) => $p['is_active']);
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    public static function flush(): void
    {
        Cache::forget('postivo:plans:v1');
    }
}
