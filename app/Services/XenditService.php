<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class XenditService
{
    private const BASE = 'https://api.xendit.co';

    public function configured(): bool
    {
        return filled(config('postivo.billing.xendit_secret_key'));
    }

    /** @return array{success: bool, invoice_id?: string, invoice_url?: string, error?: string} */
    public function createInvoice(Order $order, User $payer): array
    {
        if (! $this->configured()) {
            return ['success' => false, 'error' => 'Pembayaran online belum dikonfigurasi (XENDIT_SECRET_KEY kosong).'];
        }

        $plan = \App\Support\Plans::get($order->plan);
        $payload = [
            'external_id' => $order->external_id,
            'amount' => $order->amount,
            'currency' => 'IDR',
            'description' => "Postivo {$plan['name']} — {$order->months} bulan",
            'invoice_duration' => config('postivo.billing.invoice_duration'),
            'payer_email' => $payer->email,
            'customer' => ['given_names' => $payer->name, 'email' => $payer->email],
            'success_redirect_url' => route('console.billing.orders.show', $order->id),
            'failure_redirect_url' => route('console.billing.orders.show', $order->id),
            'items' => [['name' => "Postivo {$plan['name']}", 'quantity' => $order->months, 'price' => (int) ($order->amount / $order->months), 'category' => 'Email API']],
            'metadata' => ['order_id' => $order->id, 'organization_id' => $order->organization_id, 'plan' => $order->plan, 'app' => 'postivo'],
        ];

        try {
            $res = Http::withBasicAuth(config('postivo.billing.xendit_secret_key'), '')->timeout(15)->post(self::BASE.'/v2/invoices', $payload);
            if ($res->successful()) {
                return ['success' => true, 'invoice_id' => $res->json('id'), 'invoice_url' => $res->json('invoice_url')];
            }
            Log::error('xendit.invoice_failed', ['order' => $order->id, 'status' => $res->status(), 'body' => $res->json()]);

            return ['success' => false, 'error' => $res->json('message') ?? 'Gagal membuat invoice di Xendit.'];
        } catch (Throwable $e) {
            Log::error('xendit.invoice_exception', ['order' => $order->id, 'error' => $e->getMessage()]);

            return ['success' => false, 'error' => 'Tidak dapat terhubung ke Xendit. Coba lagi sebentar.'];
        }
    }

    public function getInvoice(string $invoiceId): ?array
    {
        if (! $this->configured()) {
            return null;
        }
        try {
            $res = Http::withBasicAuth(config('postivo.billing.xendit_secret_key'), '')->timeout(10)->get(self::BASE.'/v2/invoices/'.$invoiceId);

            return $res->successful() ? $res->json() : null;
        } catch (Throwable $e) {
            Log::warning('xendit.get_invoice_failed', ['invoice' => $invoiceId, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
