<?php

namespace App\Http\Controllers\Admin;

use App\Models\Order;
use App\Models\Organization;
use App\Models\WebhookEvent;
use App\Services\AdminAudit;
use App\Services\BillingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BillingController extends AdminController
{
    public function index(Request $request)
    {
        $q = Order::withoutGlobalScopes()->latest();
        if ($st = $request->query('status')) {
            $q->where('status', $st);
        }
        if ($s = trim((string) $request->query('q'))) {
            $like = '%'.addcslashes($s, '%_').'%';
            $q->where(fn ($w) => $w->where('external_id', 'like', $like)->orWhere('xendit_invoice_id', 'like', $like)
                ->orWhereIn('organization_id', Organization::where('name', 'like', $like)->pluck('id')));
        }
        $page = $q->paginate(25)->withQueryString();

        $paid = fn ($since) => Order::withoutGlobalScopes()->where('status', 'PAID')->where('paid_at', '>=', $since)->sum('amount');

        return view('admin.billing.index', [
            'orders' => $page,
            'orgs' => Organization::whereIn('id', $page->pluck('organization_id'))->get()->keyBy('id'),
            'rev30' => $paid(now()->subDays(30)), 'revMonth' => $paid(now()->startOfMonth()),
            'pending' => Order::withoutGlobalScopes()->where('status', 'PENDING')->count(),
            'events' => WebhookEvent::latest('id')->limit(15)->get(),
        ]);
    }

    public function show(string $id)
    {
        $order = Order::withoutGlobalScopes()->findOrFail($id);

        return view('admin.billing.show', ['order' => $order, 'org' => Organization::find($order->organization_id), 'events' => WebhookEvent::where('event_id', $order->xendit_invoice_id ?: 'none')->orWhere('payload', 'like', '%'.$order->external_id.'%')->latest('id')->get()]);
    }

    /** Tandai lunas manual (mis. transfer bank di luar Xendit). Aktivasi plan sama seperti pembayaran normal. */
    public function markPaid(Request $request, string $id, BillingService $billing): RedirectResponse
    {
        $this->need('billing.manage');
        $reason = $this->reason($request, 10);
        $order = Order::withoutGlobalScopes()->findOrFail($id);
        abort_unless($order->status !== 'PAID', 409, 'Order sudah lunas.');

        $billing->settle($order, 'MANUAL', ['manual' => true, 'by' => auth()->id(), 'reason' => $reason]);
        AdminAudit::log('billing.mark_paid', 'order', $id, $order->organization_id, $reason, ['amount' => $order->amount]);

        return back()->with('status', 'Order ditandai lunas dan plan diaktifkan.');
    }

    public function expire(Request $request, string $id, BillingService $billing): RedirectResponse
    {
        $this->need('billing.manage');
        $reason = $this->reason($request);
        $order = Order::withoutGlobalScopes()->findOrFail($id);
        abort_unless($order->status === 'PENDING', 409);
        $billing->markUnpaid($order, 'EXPIRED');
        AdminAudit::log('billing.expire', 'order', $id, $order->organization_id, $reason);

        return back()->with('status', 'Order dikedaluwarsakan.');
    }

    /** Proses ulang event webhook yang gagal (jumlah dicek ulang terhadap order). */
    public function reprocess(string $eventId, BillingService $billing): RedirectResponse
    {
        $this->need('billing.manage');
        $ev = WebhookEvent::findOrFail($eventId);
        $p = $ev->payload;
        $order = Order::withoutGlobalScopes()->where('external_id', $p['external_id'] ?? '')->first();
        abort_unless($order, 404, 'Order untuk event ini tidak ditemukan.');
        abort_unless(in_array(strtoupper($p['status'] ?? ''), ['PAID', 'SETTLED'], true), 422, 'Hanya event PAID/SETTLED yang bisa diproses ulang.');
        abort_unless((int) ($p['paid_amount'] ?? $p['amount'] ?? 0) === (int) $order->amount, 422, 'Jumlah tidak cocok dengan order.');

        $billing->settle($order, $p['payment_channel'] ?? null, $p);
        $ev->update(['processing_status' => 'processed', 'processing_error' => null, 'processed_at' => now()]);
        AdminAudit::log('billing.reprocess', 'webhook_event', (string) $ev->id, $order->organization_id);

        return back()->with('status', 'Event diproses ulang.');
    }
}
