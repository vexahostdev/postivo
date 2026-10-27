<?php

namespace App\Services;

use App\Models\EmailDispatch;
use App\Models\Organization;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class QuotaService
{
    /** Kembalikan null bila boleh, atau pesan alasan bila kuota habis. */
    public function check(Organization $org, int $count): ?string
    {
        $period = now()->format('Y-m');
        $used = (int) DB::table('usage_counters')->where('organization_id', $org->id)->where('period', $period)->value('sent');
        if ($used + $count > $org->monthly_quota) {
            return 'Monthly quota of '.number_format($org->monthly_quota).' emails exceeded.';
        }
        if ($org->daily_limit !== null) {
            $today = EmailDispatch::withoutGlobalScopes()->where('organization_id', $org->id)
                ->where('status', '!=', 'SUPPRESSED')->where('created_at', '>=', now()->startOfDay())->count();
            if ($today + $count > $org->daily_limit) {
                return 'Daily limit of '.number_format($org->daily_limit).' emails exceeded.';
            }
        }

        return null;
    }

    public function bump(string $orgId, string $column, int $n = 1): void
    {
        $period = now()->format('Y-m');
        DB::table('usage_counters')->insertOrIgnore([['organization_id' => $orgId, 'period' => $period, 'updated_at' => now()]]);
        DB::table('usage_counters')->where('organization_id', $orgId)->where('period', $period)
            ->update([$column => DB::raw("$column + $n"), 'updated_at' => now()]);

        if ($column === 'sent') {
            $this->warnIfNeeded($orgId, $period);
        }
    }

    /** Peringatan sekali per bulan di 80% dan 100% kuota. */
    private function warnIfNeeded(string $orgId, string $period): void
    {
        $org = Organization::find($orgId);
        if (! $org || $org->monthly_quota <= 0) {
            return;
        }
        $used = (int) DB::table('usage_counters')->where('organization_id', $orgId)->where('period', $period)->value('sent');
        $pct = (int) floor($used / $org->monthly_quota * 100);
        foreach ([100, 80] as $threshold) {
            if ($pct >= $threshold && Cache::add("postivo:quota:{$orgId}:{$period}:{$threshold}", 1, now()->addDays(40))) {
                $org->notifyAdmins(new \App\Notifications\QuotaWarningNotification($org, $threshold));
                break;
            }
        }
    }
}
