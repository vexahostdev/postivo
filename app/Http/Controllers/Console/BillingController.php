<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\BillingService;
use App\Services\XenditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BillingController extends Controller
{
    public function __construct(private BillingService $billing, private XenditService $xendit) {}

    public function index()
    {
        $org = app('org.current');
        $used = (int) DB::table('usage_counters')->where('organization_id', $org->id)->where('period', now()->format('Y-m'))->value('sent');

        return view('console.billing.index', [
            'plans' => \App\Support\Plans::active(),
            'used' => $used,
            'orders' => Order::query()->latest()->limit(20)->get(),
        ]);
    }

    /** Ringkasan sebelum bayar. */
    public function checkout(Request $request, string $plan)
    {
        $p = $this->billing->plan($plan);
        $months = max(1, min(12, (int) $request->query('months', 1)));

        return view('console.billing.checkout', ['planKey' => $plan, 'plan' => $p, 'months' => $months, 'configured' => $this->xendit->configured()]);
    }

    /** Buat order + invoice Xendit, lalu arahkan ke halaman bayar Xendit. */
    public function store(Request $request, string $plan): RedirectResponse
    {
        $data = $request->validate(['months' => ['required', 'integer', 'between:1,12']]);
        $order = $this->billing->checkout(app('org.current'), $request->user(), $plan, $data['months']);

        if ($order->invoice_url) {
            return redirect()->away($order->invoice_url);
        }

        return redirect()->route('console.billing.orders.show', $order->id)
            ->with('error', $order->raw_payload['create_error'] ?? 'Invoice belum dapat dibuat.');
    }

    public function show(string $id)
    {
        $order = Order::query()->findOrFail($id);
        $this->sync($order);

        return view('console.billing.order', ['order' => $order->refresh(), 'simulator' => $this->simulatorOn()]);
    }

    /** Lanjutkan pembayaran: buat ulang invoice bila gagal sebelumnya, atau buka invoice yang masih berlaku. */
    public function pay(Request $request, string $id): RedirectResponse
    {
        $order = Order::query()->findOrFail($id);
        abort_unless($order->status === 'PENDING', 409, 'Order sudah selesai.');

        if (! $order->invoice_url) {
            $res = $this->xendit->createInvoice($order, $request->user());
            if (! $res['success']) {
                return back()->with('error', $res['error']);
            }
            $order->update(['xendit_invoice_id' => $res['invoice_id'], 'invoice_url' => $res['invoice_url']]);
        }

        return redirect()->away($order->invoice_url);
    }

    /** Hanya dev/staging tanpa key Xendit: menyelesaikan order seolah webhook PAID diterima. */
    public function simulate(string $id): RedirectResponse
    {
        abort_unless($this->simulatorOn(), 404);
        $order = Order::query()->findOrFail($id);
        $this->billing->settle($order, 'SIMULATOR', ['simulated' => true]);

        return redirect()->route('console.billing.orders.show', $order->id)->with('status', 'Pembayaran disimulasikan. Paket aktif.');
    }

    /** Pengguna kembali dari Xendit sebelum webhook tiba: tanyakan status langsung ke Xendit. */
    private function sync(Order $order): void
    {
        if ($order->status !== 'PENDING' || ! $order->xendit_invoice_id) {
            return;
        }
        $inv = $this->xendit->getInvoice($order->xendit_invoice_id);
        $status = strtoupper((string) ($inv['status'] ?? ''));
        if (in_array($status, ['PAID', 'SETTLED'], true) && (int) ($inv['amount'] ?? 0) === (int) $order->amount) {
            $this->billing->settle($order, $inv['payment_channel'] ?? null, $inv);
        } elseif ($status === 'EXPIRED') {
            $this->billing->markUnpaid($order, 'EXPIRED');
        }
    }

    private function simulatorOn(): bool
    {
        return config('postivo.billing.allow_simulator') && ! $this->xendit->configured() && ! app()->isProduction();
    }
}
