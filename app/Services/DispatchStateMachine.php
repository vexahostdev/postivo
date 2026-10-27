<?php

namespace App\Services;

use App\Enums\DispatchStatus;
use App\Models\EmailDispatch;
use App\Models\EmailEvent;
use Illuminate\Support\Str;

class DispatchStateMachine
{
    public function __construct(private WebhookDispatcher $webhooks) {}

    /**
     * Pindah status secara atomik. Status hanya maju (rank naik/sama); event telat tidak
     * memundurkan status. Selalu mencatat event bila $event diberikan.
     */
    public function transition(EmailDispatch $d, DispatchStatus $to, ?string $event = null, array $attrs = [], array $eventData = []): bool
    {
        $applied = EmailDispatch::withoutGlobalScopes()
            ->whereKey($d->id)
            ->where('status_rank', '<=', $to->rank())
            ->where(fn ($q) => $q->whereNotIn('status', ['BOUNCED', 'FAILED', 'SUPPRESSED'])->orWhere('status', $to->value))
            ->update($attrs + ['status' => $to->value, 'status_rank' => $to->rank(), 'updated_at' => now()]) > 0;

        if ($applied) {
            $d->refresh();
        }
        if ($event) {
            $this->record($d, $event, $eventData);
        }

        return $applied;
    }

    public function record(EmailDispatch $d, string $type, array $data = [], array $extra = []): EmailEvent
    {
        $event = EmailEvent::withoutGlobalScopes()->create([
            'public_id' => (string) Str::uuid7(),
            'organization_id' => $d->organization_id,
            'email_id' => $d->email_id,
            'dispatch_id' => $d->id,
            'type' => $type,
            'occurred_at' => now(),
            'data' => $data ?: null,
            'created_at' => now(),
        ] + $extra);

        $this->webhooks->fanOut($event);

        return $event;
    }
}
