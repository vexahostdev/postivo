<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\EmailDispatch;
use App\Models\EmailEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $org = app('org.current');
        $period = now()->format('Y-m');
        $usage = DB::table('usage_counters')->where('organization_id', $org->id)->where('period', $period)->first();
        $stats = EmailDispatch::query()->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        return view('console.dashboard', [
            'usage' => $usage,
            'sent' => (int) ($usage->sent ?? 0),
            'quota' => $org->monthly_quota,
            'stats' => $stats,
            'domains' => Domain::query()->with('records')->latest()->get(),
            'recent' => EmailDispatch::query()->with('email')->latest()->limit(8)->get(),
        ]);
    }

    /** Data grafik 14 hari terakhir (Chart.js, dimuat lazy). */
    public function chart(): JsonResponse
    {
        $from = now()->subDays(13)->startOfDay();
        $rows = EmailEvent::query()->where('occurred_at', '>=', $from)->whereIn('type', ['sent', 'delivered', 'bounced'])
            ->get(['type', 'occurred_at'])->groupBy(fn ($e) => $e->occurred_at->format('Y-m-d'));

        $labels = $sent = $delivered = $bounced = [];
        for ($i = 13; $i >= 0; $i--) {
            $day = now()->subDays($i)->format('Y-m-d');
            $g = $rows->get($day, collect());
            $labels[] = now()->subDays($i)->format('d M');
            $sent[] = $g->where('type', 'sent')->count();
            $delivered[] = $g->where('type', 'delivered')->count();
            $bounced[] = $g->where('type', 'bounced')->count();
        }

        return response()->json(compact('labels', 'sent', 'delivered', 'bounced'));
    }
}
