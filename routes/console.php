<?php

use App\Models\Domain;
use App\Models\Email;
use App\Services\DnsAuditorService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('postivo:audit-domains', function (DnsAuditorService $auditor) {
    $n = 0;
    Domain::withoutGlobalScopes()->whereIn('status', ['PENDING', 'ACTIVE', 'FAILED'])->with('records')->each(function (Domain $d) use ($auditor, &$n) {
        $auditor->audit($d);
        $n++;
    });
    $this->info("Audited {$n} domain(s).");
})->purpose('Audit ulang DNS semua domain');

Artisan::command('postivo:prune', function () {
    $body = now()->subDays(config('postivo.retention_days.body'));
    $events = now()->subDays(config('postivo.retention_days.events'));
    $a = Email::withoutGlobalScopes()->where('created_at', '<', $body)->whereNotNull('html_body')->update(['html_body' => null, 'text_body' => null]);
    $b = DB::table('email_events')->where('occurred_at', '<', $events)->delete();
    $c = DB::table('unmatched_relay_events')->where('created_at', '<', now()->subDays(7))->delete();
    $this->info("Pruned bodies={$a}, events={$b}, unmatched={$c}.");
})->purpose('Hapus body email & event sesuai retensi');

Schedule::command('postivo:audit-domains')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('postivo:prune')->dailyAt('03:00');

Artisan::command('postivo:billing-sweep', function (App\Services\BillingService $billing) {
    $r = $billing->sweepExpirations();
    $e = $billing->expirePending();
    $this->info("Warned={$r['warned']} expired-plans={$r['expired']} expired-orders={$e}");
})->purpose('Peringatan & penurunan paket kedaluwarsa, kedaluwarsakan order pending');

Schedule::command('postivo:billing-sweep')->dailyAt('02:00');
