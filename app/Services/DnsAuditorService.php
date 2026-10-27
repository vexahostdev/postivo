<?php

namespace App\Services;

use App\Models\DnsRecord;
use App\Models\Domain;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Audit DNS lewat DoH (Cloudflare, fallback Google). Error resolver ≠ record gagal:
 * hasil null berarti "tidak diketahui" dan status record sebelumnya dipertahankan.
 */
class DnsAuditorService
{
    public function audit(Domain $domain): Domain
    {
        $looked = config('postivo.dns.assume_ok') ? [] : $this->lookupMany($domain->records->map(fn ($r) => [$r->type, $r->name])->all());

        foreach ($domain->records as $rec) {
            $seen = config('postivo.dns.assume_ok') ? [$rec->value] : ($looked[$rec->type.'|'.$rec->name] ?? null);
            if ($seen === null) {
                $rec->update(['last_checked_at' => now()]);

                continue;
            }
            $rec->update([
                'is_verified' => $this->matches($rec, $seen),
                'last_seen_value' => implode(' | ', $seen) ?: null,
                'last_checked_at' => now(),
            ]);
        }

        $domain->load('records');
        $required = $domain->records->where('is_required', true);
        $allOk = $required->isNotEmpty() && $required->every->is_verified;

        $before = $domain->status;
        $domain->fill(['last_audit_at' => now()]);
        if ($allOk) {
            $domain->status = 'ACTIVE';
            $domain->verified_at ??= now();
        } elseif ($domain->status === 'ACTIVE') {
            $domain->status = 'FAILED';
        } elseif ($domain->audit_started_at && $domain->audit_started_at->lt(now()->subDays(7))) {
            $domain->status = 'FAILED';
        }
        $tracking = $domain->records->firstWhere('purpose', 'tracking');
        // Mode lokal (assume_ok) tidak punya DNS nyata → pakai host tracking bawaan aplikasi.
        $domain->tracking_host = ! config('postivo.dns.assume_ok') && $tracking?->is_verified ? $tracking->name : null;
        $domain->save();

        if ($before !== $domain->status) {
            $org = \App\Models\Organization::find($domain->organization_id);
            if ($domain->status === 'ACTIVE') {
                $org?->notifyAdmins(new \App\Notifications\DomainVerifiedNotification($domain));
            } elseif ($before === 'ACTIVE' && $domain->status === 'FAILED') {
                $org?->notifyAdmins(new \App\Notifications\DomainFailedNotification($domain));
            }
        }

        return $domain;
    }

    private function matches(DnsRecord $rec, array $seen): bool
    {
        $norm = fn (string $s) => strtolower(rtrim(trim($s, " \t\""), '.'));
        $want = $norm($rec->value);

        foreach ($seen as $s) {
            $s = $norm($s);
            $ok = match ($rec->purpose) {
                'dkim' => str_contains(str_replace([' ', '"'], '', $s), str_replace(' ', '', explode('p=', $want)[1] ?? '~')),
                'spf' => str_starts_with($s, 'v=spf1') && str_contains($s, 'include:'.config('postivo.dns.spf_include')),
                'dmarc' => str_starts_with($s, 'v=dmarc1'),
                default => $s === $want,
            };
            if ($ok) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cari semua record sekaligus (paralel) di resolver utama; yang gagal dicoba ulang di fallback.
     *
     * @param  array<int, array{0: string, 1: string}>  $queries  [type, name]
     * @return array<string, string[]|null> kunci "TYPE|name"
     */
    public function lookupMany(array $queries): array
    {
        $result = [];
        $pending = $queries;
        foreach ([config('postivo.dns.doh_primary'), config('postivo.dns.doh_fallback')] as $endpoint) {
            if (! $pending) {
                break;
            }
            try {
                $responses = Http::pool(function ($pool) use ($pending, $endpoint) {
                    foreach ($pending as $i => [$type, $name]) {
                        $pool->as((string) $i)->timeout(4)->withHeaders(['Accept' => 'application/dns-json'])->get($endpoint, ['name' => $name, 'type' => $type]);
                    }
                });
            } catch (Throwable) {
                continue;
            }
            $next = [];
            foreach ($pending as $i => [$type, $name]) {
                $res = $responses[(string) $i] ?? null;
                $parsed = $res instanceof \Illuminate\Http\Client\Response && $res->ok() ? $this->parse($res->json()) : null;
                if ($parsed === null) {
                    $next[$i] = [$type, $name];
                } else {
                    $result[$type.'|'.$name] = $parsed;
                }
            }
            $pending = $next;
        }
        foreach ($pending as [$type, $name]) {
            $result[$type.'|'.$name] = null; // resolver tak terjangkau → status record lama dipertahankan
        }

        return $result;
    }

    /** NXDOMAIN (3) = definitif kosong; selain 0/3 = error resolver (null). */
    private function parse(?array $json): ?array
    {
        $status = $json['Status'] ?? null;
        if ($status === 3) {
            return [];
        }
        if ($status !== 0) {
            return null;
        }

        return collect($json['Answer'] ?? [])->pluck('data')->map(fn ($d) => (string) $d)->values()->all();
    }

    /** @return string[]|null null = resolver tidak dapat dijangkau */
    public function lookup(string $type, string $name): ?array
    {
        foreach ([config('postivo.dns.doh_primary'), config('postivo.dns.doh_fallback')] as $endpoint) {
            try {
                $res = Http::timeout(4)->withHeaders(['Accept' => 'application/dns-json'])
                    ->get($endpoint, ['name' => $name, 'type' => $type]);
                if (! $res->ok()) {
                    continue;
                }
                $status = $res->json('Status');
                if ($status === 3) { // NXDOMAIN — definitif
                    return [];
                }
                if ($status !== 0) {
                    continue;
                }

                return collect($res->json('Answer') ?? [])->pluck('data')->map(fn ($d) => (string) $d)->values()->all();
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }
}
