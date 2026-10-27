<?php

namespace App\Http\Controllers\Admin;

use App\Models\AdminAuditLog;
use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use App\Services\ReputationService;
use App\Support\Plans;
use Illuminate\Support\Facades\DB;

class DashboardController extends AdminController
{
    public function __invoke(ReputationService $rep)
    {
        $d = fn ($since) => DB::table('email_dispatches')->where('created_at', '>=', $since)->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');
        $s24 = $d(now()->subDay());
        $s30 = $d(now()->subDays(30));
        $volume30 = ($s30['DELIVERED'] ?? 0) + ($s30['BOUNCED'] ?? 0) + ($s30['COMPLAINED'] ?? 0) + ($s30['SENT'] ?? 0);

        $plans = Plans::all();
        $mrr = Organization::where('plan', '!=', 'free')->where('plan_expires_at', '>', now())->get()
            ->sum(fn ($o) => $plans[$o->plan]['price'] ?? 0);

        $risk = collect($rep->scan())->groupBy('level')->map->count();

        return view('admin.dashboard', [
            'orgs' => Organization::count(),
            'paidOrgs' => Organization::where('plan', '!=', 'free')->where('plan_expires_at', '>', now())->count(),
            'suspended' => Organization::where('status', 'SUSPENDED')->count(),
            'users' => User::count(),
            's24' => $s24, 's30' => $s30,
            'bounceRate' => $volume30 ? round(($s30['BOUNCED'] ?? 0) / $volume30 * 100, 2) : 0,
            'complaintRate' => $volume30 ? round(($s30['COMPLAINED'] ?? 0) / $volume30 * 100, 3) : 0,
            'mrr' => $mrr,
            'pendingOrders' => Order::withoutGlobalScopes()->where('status', 'PENDING')->count(),
            'failedWebhookEvents' => DB::table('webhook_events')->where('processing_status', 'failed')->count(),
            'failedJobs' => DB::table('failed_jobs')->count(),
            'unmatched' => DB::table('unmatched_relay_events')->where('created_at', '>=', now()->subDay())->count(),
            'failingEndpoints' => DB::table('webhook_endpoints')->where('consecutive_failures', '>', 0)->whereNull('deleted_at')->count(),
            'risk' => $risk,
            'audit' => AdminAuditLog::with('admin')->latest('id')->limit(8)->get(),
        ]);
    }
}
