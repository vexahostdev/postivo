<?php

namespace App\Support;

use App\Models\Domain;
use App\Models\Email;

class Present
{
    public static function email(Email $e, bool $withEvents = false): array
    {
        $e->loadMissing('dispatches', 'domain');

        $out = [
            'id' => $e->id,
            'from' => $e->from_name ? "{$e->from_name} <{$e->from_address}>" : $e->from_address,
            'subject' => $e->subject,
            'domain' => $e->domain?->domain_name,
            'source' => $e->source,
            'tags' => $e->tags ?? [],
            'metadata' => $e->metadata ?? (object) [],
            'track_opens' => $e->track_opens,
            'track_clicks' => $e->track_clicks,
            'scheduled_at' => $e->scheduled_at?->utc()->toIso8601ZuluString(),
            'created_at' => $e->created_at->utc()->toIso8601ZuluString(),
            'recipients' => $e->dispatches->map(fn ($d) => [
                'email' => $d->recipient,
                'dispatch_id' => $d->id,
                'status' => $d->status->value,
            ])->values(),
        ];
        if ($withEvents) {
            $out['events'] = $e->events()->orderBy('occurred_at')->get()->map(fn ($ev) => [
                'id' => 'evt_'.$ev->public_id, 'type' => $ev->type, 'dispatch_id' => $ev->dispatch_id,
                'occurred_at' => $ev->occurred_at->utc()->toIso8601ZuluString(), 'data' => $ev->data,
            ]);
        }

        return $out;
    }

    public static function domain(Domain $d): array
    {
        $d->loadMissing('records');

        return [
            'id' => $d->id,
            'domain' => $d->domain_name,
            'status' => $d->status,
            'track_opens_default' => (bool) $d->track_opens_default,
            'track_clicks_default' => (bool) $d->track_clicks_default,
            'verified_at' => $d->verified_at?->utc()->toIso8601ZuluString(),
            'last_audit_at' => $d->last_audit_at?->utc()->toIso8601ZuluString(),
            'dns_records' => $d->records->map(fn ($r) => [
                'purpose' => $r->purpose, 'type' => $r->type, 'name' => $r->name, 'value' => $r->value,
                'required' => $r->is_required, 'verified' => $r->is_verified,
            ])->values(),
        ];
    }
}
