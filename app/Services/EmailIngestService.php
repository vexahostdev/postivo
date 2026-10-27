<?php

namespace App\Services;

use App\Enums\DispatchStatus;
use App\Exceptions\IngestException;
use App\Jobs\DispatchOutboundEmailJob;
use App\Models\ApiKey;
use App\Models\Domain;
use App\Models\Email;
use App\Models\EmailDispatch;
use App\Models\Organization;
use App\Support\Addr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Satu-satunya pintu masuk email (REST dan SMTP). */
class EmailIngestService
{
    public function __construct(
        private SuppressionService $suppressions,
        private QuotaService $quota,
        private DispatchStateMachine $machine,
    ) {}

    /** @return array{0: Email, 1: bool} [email, isReplay] */
    public function accept(Organization $org, ?ApiKey $key, array $d, string $source = 'API', ?string $idemKey = null): array
    {
        if ($org->isSuspended()) {
            throw new IngestException('forbidden', 'Organization is suspended.', 403);
        }

        [$fromAddr, $fromName] = Addr::parse($d['from']);
        $domain = Domain::withoutGlobalScopes()->where('organization_id', $org->id)
            ->where('domain_name', Addr::domainOf($fromAddr))->first();
        $sandbox = $key?->environment === 'test';

        if (! $domain || (! $sandbox && ! $domain->isActive())) {
            throw new IngestException('domain_not_verified', 'The From domain is not verified for this organization.', 422);
        }
        if ($key && ! $key->allowsDomain($domain->id)) {
            throw new IngestException('forbidden', 'This API key is not allowed to send from that domain.', 403);
        }

        $hash = hash('sha256', json_encode($d));
        if ($idemKey) {
            $existing = Email::withoutGlobalScopes()->where('organization_id', $org->id)->where('idempotency_key', $idemKey)->first();
            if ($existing) {
                if ($existing->request_hash !== $hash) {
                    throw new IngestException('conflict', 'Idempotency-Key was already used with a different request body.', 409);
                }

                return [$existing->load('dispatches'), true];
            }
        }

        $recipients = $this->recipients($d);
        if (count($recipients) > config('postivo.limits.max_recipients')) {
            throw new IngestException('validation_failed', 'Too many recipients (max '.config('postivo.limits.max_recipients').').', 422);
        }

        $suppressed = collect($recipients)->filter(fn ($r) => $this->suppressions->isSuppressed($org->id, $r['email']));
        if ($suppressed->count() === count($recipients)) {
            throw new IngestException('recipient_suppressed', 'All recipients are on the suppression list.', 422);
        }

        $billable = count($recipients) - $suppressed->count();
        if (! $sandbox && ($why = $this->quota->check($org, $billable))) {
            throw new IngestException('quota_exceeded', $why, 429);
        }

        $email = DB::transaction(function () use ($org, $key, $d, $source, $idemKey, $hash, $domain, $fromAddr, $fromName, $recipients, $suppressed) {
            $email = Email::withoutGlobalScopes()->create([
                'organization_id' => $org->id,
                'domain_id' => $domain->id,
                'api_key_id' => $key?->id,
                'source' => $source,
                'from_address' => $fromAddr,
                'from_name' => $fromName,
                'reply_to' => $d['reply_to'] ?? null,
                'subject' => $d['subject'],
                'html_body' => $d['html'] ?? null,
                'text_body' => $d['text'] ?? null,
                'headers' => $d['headers'] ?? null,
                'tags' => $d['tags'] ?? null,
                'metadata' => $d['metadata'] ?? null,
                'track_opens' => $d['track_opens'] ?? $domain->track_opens_default,
                'track_clicks' => $d['track_clicks'] ?? $domain->track_clicks_default,
                'idempotency_key' => $idemKey,
                'request_hash' => $hash,
                'scheduled_at' => $d['scheduled_at'] ?? null,
            ]);

            foreach ($d['attachments'] ?? [] as $a) {
                $bin = base64_decode($a['content'], true);
                $path = 'mail/attachments/'.$email->id.'/'.Str::uuid7();
                Storage::disk('local')->put($path, $bin);
                DB::table('email_attachments')->insert([
                    'id' => (string) Str::uuid7(), 'email_id' => $email->id, 'filename' => $a['filename'],
                    'content_type' => $a['content_type'] ?? 'application/octet-stream', 'size_bytes' => strlen($bin),
                    'disposition' => 'attachment', 'storage_path' => $path, 'created_at' => now(),
                ]);
            }

            foreach ($recipients as $r) {
                $isSupp = $suppressed->contains('email', $r['email']);
                EmailDispatch::withoutGlobalScopes()->create([
                    'email_id' => $email->id,
                    'organization_id' => $org->id,
                    'domain_id' => $domain->id,
                    'recipient' => $r['email'],
                    'recipient_hash' => Addr::hash($r['email']),
                    'recipient_type' => $r['type'],
                    'status' => $isSupp ? DispatchStatus::SUPPRESSED->value : DispatchStatus::QUEUED->value,
                    'status_rank' => $isSupp ? DispatchStatus::SUPPRESSED->rank() : 0,
                    'open_token' => Addr::token(),
                    'unsubscribe_token' => Addr::token(),
                    'queued_at' => now(),
                ]);
            }

            return $email;
        });

        $email->load('dispatches');
        foreach ($email->dispatches as $disp) {
            if ($disp->status === DispatchStatus::SUPPRESSED) {
                $this->machine->record($disp, 'suppressed', ['reason' => 'recipient on suppression list']);

                continue;
            }
            $this->machine->record($disp, 'queued');
            $job = DispatchOutboundEmailJob::dispatch($disp->id)->onQueue('postivo_high');
            if ($email->scheduled_at && $email->scheduled_at->isFuture()) {
                $job->delay($email->scheduled_at);
            }
        }
        if (! $sandbox) {
            $this->quota->bump($org->id, 'sent', $billable);
        }

        return [$email, false];
    }

    /** @return array<int, array{email: string, type: string}> unik per alamat */
    private function recipients(array $d): array
    {
        $out = [];
        foreach (['to', 'cc', 'bcc'] as $type) {
            foreach ((array) ($d[$type] ?? []) as $addr) {
                $e = Addr::normalize($addr);
                if (! isset($out[$e])) {
                    $out[$e] = ['email' => $e, 'type' => $type];
                }
            }
        }

        return array_values($out);
    }
}
