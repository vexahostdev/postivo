<?php

namespace App\Jobs;

use App\Enums\DispatchStatus;
use App\Exceptions\RelayException;
use App\Models\ApiKey;
use App\Models\Domain;
use App\Models\Email;
use App\Models\EmailDispatch;
use App\Models\Organization;
use App\Services\DispatchStateMachine;
use App\Services\DkimService;
use App\Services\EmailIngestService;
use App\Services\OutboundRelayService;
use App\Services\QuotaService;
use App\Services\SuppressionService;
use App\Services\TrackingService;
use App\Support\Addr;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email as MimeEmail;

class DispatchOutboundEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    private const RESERVED = ['from', 'to', 'cc', 'bcc', 'subject', 'message-id', 'dkim-signature', 'return-path', 'date', 'mime-version', 'content-type'];

    public function __construct(public string $dispatchId) {}

    public function handle(DispatchStateMachine $machine, OutboundRelayService $relay, DkimService $dkim, TrackingService $tracking, SuppressionService $supp, QuotaService $quota): void
    {
        // Also respect an outer transaction when invoked synchronously by internal tooling.
        DB::afterCommit(fn () => $this->sendAfterCommit($machine, $relay, $dkim, $tracking, $supp, $quota));
    }

    private function sendAfterCommit(DispatchStateMachine $machine, OutboundRelayService $relay, DkimService $dkim, TrackingService $tracking, SuppressionService $supp, QuotaService $quota): void
    {
        $candidate = EmailDispatch::withoutGlobalScopes()->find($this->dispatchId);
        if (! $candidate) {
            return;
        }

        $claimed = DB::transaction(function () use ($candidate, $machine, $supp) {
            // Same org-first order as admission; authority reads are current InnoDB locking reads.
            $org = Organization::query()->lockForUpdate()->find($candidate->organization_id);
            // Cancel and claim serialize on the parent before either locks dispatch state.
            $email = Email::withoutGlobalScopes()->lockForUpdate()->find($candidate->email_id);
            $d = EmailDispatch::withoutGlobalScopes()->where('email_id', $candidate->email_id)
                ->lockForUpdate()->find($this->dispatchId);
            if (! $d || ! in_array($d->status, [DispatchStatus::QUEUED, DispatchStatus::DEFERRED], true)) {
                return null;
            }
            if (! $email || ! $org || $email->organization_id !== $org->id || $d->organization_id !== $org->id) {
                $this->rejectClaim($d, $machine, 'source_missing');

                return null;
            }
            if ($email->canceled_at) {
                $this->rejectClaim($d, $machine, 'canceled');

                return null;
            }
            if ($org->isSuspended()) {
                $this->rejectClaim($d, $machine, 'organization_suspended');

                return null;
            }

            $domain = Domain::withoutGlobalScope('organization')->where('organization_id', $org->id)
                ->lockForUpdate()->find($email->domain_id);
            if (! $domain || $d->domain_id !== $domain->id) {
                $this->rejectClaim($d, $machine, 'source_missing');

                return null;
            }
            if ($domain->status === 'SUSPENDED') {
                $this->rejectClaim($d, $machine, 'domain_suspended');

                return null;
            }

            $key = $email->api_key_id !== null
                ? ApiKey::withoutGlobalScope('organization')->where('organization_id', $org->id)
                    ->lockForUpdate()->find($email->api_key_id)
                : null;
            if ($email->api_key_id !== null && (! $key || $key->isRevoked() || ! $key->can('emails:send') || ! $key->allowsDomain($domain->id))) {
                $this->rejectClaim($d, $machine, 'api_key_unauthorized');

                return null;
            }
            $sandbox = $key?->environment === 'test';
            if (! $sandbox && ! $domain->isActive()) {
                $this->rejectClaim($d, $machine, 'domain_not_verified');

                return null;
            }
            if ($supp->isSuppressed($d->organization_id, $d->recipient)) {
                $this->rejectClaim($d, $machine, 'recipient on suppression list', DispatchStatus::SUPPRESSED);

                return null;
            }

            $d->update(['status' => DispatchStatus::SENDING, 'status_rank' => 1, 'attempts' => $d->attempts + 1]);

            return [$d, $email, $domain, $sandbox];
        });
        if ($claimed === null) {
            return;
        }
        [$d, $email, $domain, $sandbox] = $claimed;

        // All locks have been released before simulation, signing, webhooks or relay I/O.
        if ($sandbox) {
            $machine->transition($d, DispatchStatus::SENT, 'sent', ['relay' => 'SANDBOX', 'sent_at' => now(), 'smtp_response' => '250 sandbox']);
            $machine->transition($d, DispatchStatus::DELIVERED, 'delivered', ['delivered_at' => now()]);

            return;
        }

        try {
            $mime = $this->build($d, $email, $domain, $tracking);
            $signed = $dkim->sign($mime, $domain);
            $result = $relay->send($signed, "b+{$d->id}@{$domain->return_path_host}", $d->recipient, $d->id);
            Storage::disk('local')->put("mail/raw/{$d->id}.eml", $signed->toString());

            $machine->transition($d, DispatchStatus::SENT, 'sent', [
                'relay' => $result['relay'], 'sent_at' => now(), 'smtp_response' => $result['response'],
                'message_id' => $d->id.'@'.$domain->domain_name, 'raw_mime_path' => "mail/raw/{$d->id}.eml",
            ]);

            // Relay log tidak punya feedback asli → simulasikan DELIVERED agar alur lokal lengkap.
            if ($result['relay'] === 'LOG') {
                $machine->transition($d, DispatchStatus::DELIVERED, 'delivered', ['delivered_at' => now()]);
                $quota->bump($d->organization_id, 'delivered');
            }
        } catch (RelayException $e) {
            $this->handleFailure($d, $e, $machine, $supp, $quota);
        } catch (Throwable $e) {
            // Bug internal ≠ kegagalan relay: jangan retry buta dan jangan biarkan dispatch menggantung di SENDING.
            report($e);
            $machine->transition($d->refresh(), DispatchStatus::FAILED, 'failed', ['smtp_response' => 'internal_error'], ['reason' => 'internal_error']);
        }
    }

    private function rejectClaim(EmailDispatch $d, DispatchStateMachine $machine, string $reason, DispatchStatus $status = DispatchStatus::FAILED): void
    {
        if ($machine->transition($d, $status, null, ['smtp_response' => $reason])) {
            // record() also publishes webhooks, which must not run under the claim locks.
            DB::afterCommit(fn () => $machine->record($d, strtolower($status->value), ['reason' => $reason]));
        }
    }

    private function handleFailure(EmailDispatch $d, RelayException $e, DispatchStateMachine $machine, SuppressionService $supp, QuotaService $quota): void
    {
        if (! $e->transient) {
            $machine->transition($d, DispatchStatus::BOUNCED, 'bounced', ['bounce_type' => 'HARD', 'smtp_response' => mb_substr($e->getMessage(), 0, 500)], ['smtp_code' => $e->smtpCode]);
            $supp->add($d->organization_id, $d->recipient, 'HARD_BOUNCE', $d->id);
            $quota->bump($d->organization_id, 'bounced');

            return;
        }

        $backoff = config('postivo.retry_backoff');
        if ($d->attempts > count($backoff)) {
            $machine->transition($d, DispatchStatus::FAILED, 'failed', ['smtp_response' => mb_substr($e->getMessage(), 0, 500)], ['reason' => 'retries_exhausted']);

            return;
        }

        $delay = $backoff[$d->attempts - 1];
        // SENDING → DEFERRED (rank sama) lalu jadwalkan ulang.
        EmailDispatch::withoutGlobalScopes()->whereKey($d->id)->update([
            'status' => DispatchStatus::DEFERRED->value, 'status_rank' => 1,
            'next_attempt_at' => now()->addSeconds($delay), 'smtp_response' => mb_substr($e->getMessage(), 0, 500),
        ]);
        $machine->record($d->refresh(), 'deferred', ['attempt' => $d->attempts, 'retry_in' => $delay]);
        self::dispatch($d->id)->onQueue('postivo_default')->delay($delay);
    }

    private function build(EmailDispatch $d, $email, Domain $domain, TrackingService $tracking): MimeEmail
    {
        $m = new MimeEmail;
        $m->from(new Address($email->from_address, (string) $email->from_name));
        $m->to($d->recipient);
        if ($email->reply_to) {
            $m->replyTo($email->reply_to);
        }
        $m->subject($email->subject);
        $m->getHeaders()->addIdHeader('Message-ID', $d->id.'@'.$domain->domain_name);

        foreach ($email->headers ?? [] as $name => $value) {
            // Last trusted boundary also covers queued payloads written before validation existed.
            if (! EmailIngestService::isValidHeaderName((string) $name)) {
                throw new \InvalidArgumentException('Invalid email header field name.');
            }
            if (! in_array(strtolower((string) $name), self::RESERVED, true)) {
                $m->getHeaders()->addTextHeader((string) $name, (string) $value);
            }
        }

        if ($email->text_body) {
            $m->text($email->text_body);
        }
        if ($email->html_body) {
            $m->html($tracking->apply($email->html_body, $email, $d, $domain));
        }
        foreach (DB::table('email_attachments')->where('email_id', $email->id)->get() as $a) {
            $m->attach(Storage::disk('local')->get($a->storage_path), $a->filename, $a->content_type);
        }

        return $m;
    }
}
