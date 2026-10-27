<?php

namespace App\Http\Controllers\Admin;

use App\Services\AdminAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class SystemController extends AdminController
{
    public function index()
    {
        $hb = (int) Cache::get('postivo:heartbeat', 0);
        $spool = config('postivo.smtp.spool');

        return view('admin.system.index', [
            'heartbeatAge' => $hb ? time() - $hb : null,
            'breakers' => collect(['STALWART', 'BREVO'])->mapWithKeys(fn ($r) => [$r => [
                'open' => Cache::has("postivo:breaker:$r:open"),
                'fails' => (int) Cache::get("postivo:breaker:$r:fails", 0),
            ]]),
            'relayDriver' => config('postivo.relay.driver'),
            'queue' => config('queue.default'),
            'pendingJobs' => DB::table('jobs')->count(),
            'failedJobs' => DB::table('failed_jobs')->latest('id')->limit(25)->get(),
            'unmatched' => DB::table('unmatched_relay_events')->latest('id')->limit(15)->get(),
            'badWebhooks' => DB::table('webhook_endpoints')->where('consecutive_failures', '>', 0)->whereNull('deleted_at')->orderByDesc('consecutive_failures')->limit(10)->get(),
            'spoolFiles' => is_dir($spool) ? count(File::files($spool)) : 0,
            'queuedStale' => DB::table('email_dispatches')->whereIn('status', ['QUEUED', 'DEFERRED'])->where('created_at', '<', now()->subHour())->count(),
            'env' => ['app' => app()->environment(), 'php' => PHP_VERSION, 'laravel' => app()->version(), 'db' => config('database.default'), 'cache' => config('cache.default'), 'mail' => config('mail.default')],
        ]);
    }

    public function retryJob(string $uuid): RedirectResponse
    {
        $this->need('system.manage');
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        AdminAudit::log('system.retry_job', 'failed_job', $uuid);

        return back()->with('status', 'Job dikembalikan ke antrean.');
    }

    public function deleteJob(string $uuid): RedirectResponse
    {
        $this->need('system.manage');
        DB::table('failed_jobs')->where('uuid', $uuid)->delete();
        AdminAudit::log('system.delete_job', 'failed_job', $uuid);

        return back()->with('status', 'Failed job dihapus.');
    }

    public function resetBreaker(string $relay): RedirectResponse
    {
        $this->need('system.manage');
        abort_unless(in_array($relay, ['STALWART', 'BREVO'], true), 404);
        Cache::forget("postivo:breaker:$relay:open");
        Cache::forget("postivo:breaker:$relay:fails");
        AdminAudit::log('system.reset_breaker', 'relay', $relay);

        return back()->with('status', "Circuit breaker $relay direset.");
    }
}
