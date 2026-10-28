<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\WebhookEvent;
use App\Services\BillingService;
use App\Support\XenditPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Callback Xendit untuk invoice ber-prefix external_id "PSV-".
 * Alur: Xendit → webhook sentral VexaHost → (forward + X-Internal-Token) → endpoint ini.
 * Callback langsung dari Xendit (x-callback-token) juga diterima bila token cocok.
 */
class XenditWebhookController extends Controller
{
    public function __invoke(Request $request, BillingService $billing): JsonResponse
    {
        if ($request->isMethod('get')) {
            return response()->json(['status' => 'ok', 'message' => 'Postivo Xendit webhook receiver is active.']);
        }

        if (! $this->authorized($request)) {
            Log::warning('postivo.xendit_webhook_unauthorized', ['ip' => $request->ip()]);

            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 403);
        }

        $validator = Validator::make($request->all(), XenditPayload::RULES);
        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => 'Invalid callback payload.', 'errors' => $validator->errors()], 422);
        }
        $p = $validator->validated();
        $externalId = (string) ($p['external_id'] ?? '');
        $invoiceId = (string) ($p['id'] ?? '');
        $status = strtoupper((string) ($p['status'] ?? ''));

        if (in_array(strtolower($status), ['test', 'ping'], true) || $externalId === '') {
            return response()->json(['status' => 'success', 'message' => 'Ping acknowledged.']);
        }
        if (! str_starts_with($externalId, config('postivo.billing.external_prefix'))) {
            return response()->json(['status' => 'ignored', 'message' => 'Not a Postivo invoice.']);
        }

        // Idempoten: (provider, invoice, status) yang sama diproses sekali.
        $event = WebhookEvent::firstOrCreate(
            ['provider' => 'xendit', 'event_id' => $invoiceId ?: $externalId, 'event_type' => strtolower($status)],
            ['payload' => $p, 'ip' => $request->ip()],
        );
        if (! $event->wasRecentlyCreated && $event->processing_status === 'processed') {
            return response()->json(['status' => 'duplicate']);
        }

        $order = Order::withoutGlobalScopes()->where('external_id', $externalId)->first();
        if (! $order) {
            $event->update(['processing_status' => 'ignored', 'processing_error' => 'order not found', 'processed_at' => now()]);

            return response()->json(['status' => 'not_found']); // 200 agar gateway tidak retry tanpa henti
        }

        try {
            if (in_array($status, ['PAID', 'SETTLED'], true)) {
                $paid = (int) ($p['paid_amount'] ?? $p['amount'] ?? 0);
                if ($paid !== (int) $order->amount) {
                    Log::critical('postivo.xendit_amount_mismatch', ['order' => $order->id, 'expected' => $order->amount, 'got' => $paid]);
                    $event->update(['processing_status' => 'failed', 'processing_error' => 'amount mismatch', 'processed_at' => now()]);

                    return response()->json(['status' => 'error', 'message' => 'Amount mismatch.'], 422);
                }
                $billing->settle($order, $p['payment_channel'] ?? $p['payment_method'] ?? null, $p);
            } elseif ($status === 'EXPIRED') {
                $billing->markUnpaid($order, 'EXPIRED');
            } elseif ($status === 'FAILED') {
                $billing->markUnpaid($order, 'FAILED');
            }
            $event->update(['processing_status' => 'processed', 'processed_at' => now()]);

            return response()->json(['status' => 'success']);
        } catch (Throwable $e) {
            report($e);
            $event->update(['processing_status' => 'failed', 'processing_error' => mb_substr($e->getMessage(), 0, 500)]);

            return response()->json(['status' => 'error', 'message' => 'Processing error.'], 500); // Xendit akan retry
        }
    }

    private function authorized(Request $request): bool
    {
        $internal = $request->header('X-Internal-Token');
        $callback = $request->header('x-callback-token') ?? $request->input('callback_token');
        $expInternal = (string) config('postivo.billing.internal_secret');
        $expCallback = (string) config('postivo.billing.xendit_webhook_token');

        return ($expInternal !== '' && is_string($internal) && hash_equals($expInternal, $internal))
            || ($expCallback !== '' && is_string($callback) && hash_equals($expCallback, $callback));
    }
}
