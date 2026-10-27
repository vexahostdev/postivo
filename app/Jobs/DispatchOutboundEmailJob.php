<?php

namespace App\Jobs;

use App\Enums\DispatchStatus;
use App\Exceptions\RelayException;
use App\Models\ApiKey;
use App\Models\Domain;
use App\Models\EmailDispatch;
use App\Services\DispatchStateMachine;
use App\Services\DkimService;
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
        $d = EmailDispatch::withoutGlobalScopes()->find($this->dispatchId);
        if (! $d || ! in_array($d->status, [DispatchStatus::QUEUED, DispatchStatus::DEFERRED], true)) {
            return; // idempoten: sudah diproses / final
        }
        $email = $d->email()->withoutGlobalScopes()->first();
        if ($email->canceled_at) {
            $machine->transition($d, DispatchStatus::FAILED, 'failed', ['smtp_response' => 'canceled'], ['reason' => 'canceled']);

            return;
        }
        if ($supp->isSuppressed($d->organization_id, $d->recipient)) {
            $machine->transition($d, DispatchStatus::SUPPRESSED, 'suppressed', [], ['reason' => 'recipient on suppression list']);

            return;
        }

        $key = $email->api_key_id ? ApiKey::withoutGlobalScopes()->find($email->api_key_id) : null;
        $domain = Domain::withoutGlobalScopes()->withTrashed()->find($email->domain_id);

        // Klaim atomik: hanya satu worker yang menang.
        $claimed = EmailDispatch::withoutGlobalScopes()->whereKey($d->id)->whereIn('status', ['QUEUED', 'DEFERRED'])
            ->update(['status' => 'SENDING', 'status_rank' => 1, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
        if (! $claimed) {
            return;
        }
        $d->refresh();

        // Sandbox (API key test): simulasi tanpa relay.
        if ($key?->environment === 'test') {
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
            if (! in_array(strtolower($name), self::RESERVED, true)) {
                $m->getHeaders()->addTextHeader($name, (string) $value);
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
