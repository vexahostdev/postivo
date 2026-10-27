<?php

namespace App\Http\Controllers\Internal;

use App\Enums\DispatchStatus;
use App\Http\Controllers\Controller;
use App\Models\EmailDispatch;
use App\Services\DispatchStateMachine;
use App\Services\QuotaService;
use App\Services\SuppressionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Feedback dari relay (Stalwart/Brevo). Bentuk payload Stalwart dikonfirmasi di M0;
 * adapter ke format kanonik ada di normalize() sehingga hanya satu tempat yang berubah.
 */
class RelayEventController extends Controller
{
    public function __invoke(Request $request, string $relay, DispatchStateMachine $machine, SuppressionService $supp, QuotaService $quota): JsonResponse
    {
        abort_unless(in_array($relay, ['stalwart', 'brevo'], true), 404);

        $ev = $this->normalize($request->json()->all());
        $d = $ev['dispatch_id'] ? EmailDispatch::withoutGlobalScopes()->find($ev['dispatch_id']) : null;

        if (! $d) {
            DB::table('unmatched_relay_events')->insert([
                'relay' => strtoupper($relay), 'payload' => json_encode($request->json()->all()), 'reason' => 'dispatch_not_found', 'created_at' => now(),
            ]);

            return response()->json(['matched' => false], 202);
        }

        match ($ev['type']) {
            'delivered' => $this->delivered($d, $machine, $quota, $ev),
            'bounced' => $this->bounced($d, $machine, $supp, $quota, $ev),
            'complained' => $this->complained($d, $machine, $supp, $quota),
            'deferred' => $machine->record($d, 'deferred', ['response' => $ev['response']]),
            default => null,
        };

        return response()->json(['matched' => true]);
    }

    private function delivered(EmailDispatch $d, DispatchStateMachine $m, QuotaService $q, array $ev): void
    {
        if ($m->transition($d, DispatchStatus::DELIVERED, 'delivered', ['delivered_at' => now(), 'smtp_response' => $ev['response']])) {
            $q->bump($d->organization_id, 'delivered');
        }
    }

    private function bounced(EmailDispatch $d, DispatchStateMachine $m, SuppressionService $s, QuotaService $q, array $ev): void
    {
        $type = $this->classify($ev);
        if ($type === 'SOFT') {
            $m->record($d, 'deferred', ['response' => $ev['response']]);

            return;
        }
        if ($m->transition($d, DispatchStatus::BOUNCED, 'bounced', ['bounce_type' => $type, 'smtp_response' => $ev['response']], ['bounce_type' => $type])) {
            $q->bump($d->organization_id, 'bounced');
        }
        // BLOCK = masalah reputasi pengirim, bukan alamat buruk → jangan suppress.
        if ($type === 'HARD') {
            $s->add($d->organization_id, $d->recipient, 'HARD_BOUNCE', $d->id);
        }
    }

    private function complained(EmailDispatch $d, DispatchStateMachine $m, SuppressionService $s, QuotaService $q): void
    {
        if ($m->transition($d, DispatchStatus::COMPLAINED, 'complained')) {
            $q->bump($d->organization_id, 'complained');
        }
        $s->add($d->organization_id, $d->recipient, 'COMPLAINT', $d->id);
    }

    /** HARD / SOFT / BLOCK dari kode SMTP & teks respons. */
    private function classify(array $ev): string
    {
        $code = (int) ($ev['code'] ?? 0);
        $text = strtolower((string) $ev['response']);
        if (preg_match('/blocked|blacklist|reputation|spamhaus|policy/', $text)) {
            return 'BLOCK';
        }
        if ($code >= 400 && $code < 500) {
            return 'SOFT';
        }

        return 'HARD';
    }

    /** @return array{dispatch_id: ?string, type: string, code: ?int, response: ?string} */
    private function normalize(array $p): array
    {
        $id = $p['dispatch_id'] ?? null;
        if (! $id && ! empty($p['message_id'])) {
            $id = explode('@', trim($p['message_id'], '<>'))[0];
        }
        if (! $id && ! empty($p['return_path']) && preg_match('/^b\+([0-9a-f-]{36})@/i', $p['return_path'], $m)) {
            $id = $m[1];
        }

        return [
            'dispatch_id' => $id,
            'type' => $p['type'] ?? $p['event'] ?? 'unknown',
            'code' => isset($p['code']) ? (int) $p['code'] : null,
            'response' => $p['response'] ?? $p['reason'] ?? null,
        ];
    }
}
