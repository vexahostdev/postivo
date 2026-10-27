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

Artisan::command('postivo:heartbeat', function () {
    Illuminate\Support\Facades\Cache::forever('postivo:heartbeat', time());
})->purpose('Penanda scheduler hidup (dibaca admin /system)');

Artisan::command('postivo:abuse-scan', function (App\Services\ReputationService $rep) {
    $auto = (bool) App\Services\PlatformSettings::get('abuse_auto_suspend');
    $n = 0;
    foreach ($rep->scan(7) as $r) {
        if ($r['level'] !== 'DANGER' || $r['org']->isSuspended()) {
            continue;
        }
        // Satu peringatan per org per hari.
        if (! Illuminate\Support\Facades\Cache::add("postivo:abuse:{$r['org']->id}:".now()->format('Ymd'), 1, now()->addDay())) {
            continue;
        }
        if ($auto) {
            $rep->suspend($r['org'], "Penangguhan otomatis: bounce {$r['bounce_rate']}%, komplain {$r['complaint_rate']}% (7 hari).");
        }
        App\Services\AdminAudit::system($auto ? 'abuse.auto_suspend' : 'abuse.alert', 'organization', $r['org']->id, $r['org']->id, null, Illuminate\Support\Arr::except($r, ['org']));
        Illuminate\Support\Facades\Notification::route('mail', config('postivo.support_email'))->notify(new App\Notifications\AbuseAlertNotification($r['org'], $r, $auto));
        $n++;
    }
    $this->info("Abuse alerts: {$n}");
})->purpose('Pindai reputasi org; peringatan/auto-suspend level DANGER');

Schedule::command('postivo:heartbeat')->everyMinute();
Schedule::command('postivo:abuse-scan')->hourly()->withoutOverlapping();

Artisan::command('postivo:admin:create {--email=} {--name=} {--password=}', function () {
    $cfg = config('postivo.admin.bootstrap');
    $email = strtolower(trim((string) ($this->option('email') ?: $cfg['email'])));
    $name = (string) ($this->option('name') ?: $cfg['name']);
    $password = (string) ($this->option('password') ?: $cfg['password']);

    if (! filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        $this->error('Isi ADMIN_EMAIL dan ADMIN_PASSWORD di .env (atau pakai --email / --password).');

        return 1;
    }

    $existed = App\Models\User::where('email', $email)->exists();
    $user = App\Models\User::firstOrNew(['email' => $email]);
    $user->name = $user->exists ? $user->name : $name;
    $user->password = $password; // di-hash oleh cast 'hashed'
    $user->forceFill(['platform_role' => 'superadmin', 'email_verified_at' => $user->email_verified_at ?? now(), 'disabled_at' => null])->save();

    App\Services\StaffOrganization::ensure($user); // admin juga bisa memakai console pelanggan
    App\Services\AdminAudit::system($existed ? 'staff.bootstrap_update' : 'staff.bootstrap_create', 'user', $user->id, null, 'postivo:admin:create');
    $this->info(($existed ? 'Akun diperbarui' : 'Akun dibuat')." — {$email} (superadmin). 2FA ".($user->hasTwoFactor() ? 'aktif' : 'belum diatur').'.');

    return 0;
})->purpose('Buat/perbarui akun superadmin dari ADMIN_EMAIL & ADMIN_PASSWORD');
