<?php

namespace App\Services;

use App\Models\Organization;
use App\Notifications\OrganizationSuspendedNotification;
use Illuminate\Support\Facades\DB;

/** Reputasi pengiriman per organisasi (ADMIN_PANEL_SPEC §6). */
class ReputationService
{
    /** @return array{volume:int, delivered:int, bounced:int, complained:int, bounce_rate:float, complaint_rate:float, level:string} */
    public function forOrg(string $orgId, int $days = 7): array
    {
        $rows = DB::table('email_dispatches')->where('organization_id', $orgId)
            ->where('created_at', '>=', now()->subDays($days))
            ->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');

        return $this->evaluate($rows->all());
    }

    /** @param array<string,int> $byStatus */
    public function evaluate(array $byStatus): array
    {
        $bounced = (int) ($byStatus['BOUNCED'] ?? 0);
        $complained = (int) ($byStatus['COMPLAINED'] ?? 0);
        $delivered = (int) ($byStatus['DELIVERED'] ?? 0);
        // Volume = email yang benar-benar keluar (bukan queued/suppressed/failed internal).
        $volume = $delivered + $bounced + $complained + (int) ($byStatus['SENT'] ?? 0);
        $bounceRate = $volume ? round($bounced / $volume * 100, 2) : 0.0;
        $complaintRate = $volume ? round($complained / $volume * 100, 2) : 0.0;

        $s = PlatformSettings::all();
        $level = 'OK';
        if ($volume >= (int) $s['abuse_min_volume']) {
            if ($bounceRate > $s['abuse_bounce_danger'] || $complaintRate > $s['abuse_complaint_danger']) {
                $level = 'DANGER';
            } elseif ($bounceRate >= $s['abuse_bounce_warn'] || $complaintRate >= $s['abuse_complaint_warn']) {
                $level = 'WARN';
            }
        }

        return ['volume' => $volume, 'delivered' => $delivered, 'bounced' => $bounced, 'complained' => $complained,
            'bounce_rate' => $bounceRate, 'complaint_rate' => $complaintRate, 'level' => $level];
    }

    /** Daftar semua org aktif dengan reputasi, terburuk dulu. */
    public function scan(int $days = 7): array
    {
        $by = DB::table('email_dispatches')->where('created_at', '>=', now()->subDays($days))
            ->selectRaw('organization_id, status, count(*) c')->groupBy('organization_id', 'status')->get()->groupBy('organization_id');

        $orgs = Organization::whereIn('id', $by->keys())->get()->keyBy('id');
        $order = ['DANGER' => 0, 'WARN' => 1, 'OK' => 2];

        return $by->map(fn ($g, $id) => ['org' => $orgs[$id] ?? null] + $this->evaluate($g->pluck('c', 'status')->all()))
            ->filter(fn ($r) => $r['org'])->sortBy([fn ($a, $b) => $order[$a['level']] <=> $order[$b['level']], fn ($a, $b) => $b['complaint_rate'] <=> $a['complaint_rate']])->values()->all();
    }

    public function suspend(Organization $org, string $reason, ?string $adminId = null): void
    {
        if ($org->isSuspended()) {
            return;
        }
        $org->update(['status' => 'SUSPENDED', 'suspended_reason' => $reason, 'suspended_at' => now()]);
        $org->notifyAdmins(new OrganizationSuspendedNotification($org, $reason));
    }

    public function unsuspend(Organization $org): void
    {
        $org->update(['status' => 'ACTIVE', 'suspended_reason' => null, 'suspended_at' => null]);
    }
}
