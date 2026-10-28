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
use Throwable;

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

        foreach ($d['headers'] ?? [] as $name => $value) {
            if (! self::isValidHeaderName((string) $name)) {
                throw new IngestException('validation_failed', 'Invalid email header field name.', 422);
            }
        }

        [$fromAddr, $fromName] = Addr::parse($d['from']);
        $fromName = $fromName !== null ? mb_substr(trim(preg_replace('/[\r\n\t]+/', ' ', $fromName)), 0, 100) : null;
        $domain = Domain::withoutGlobalScope('organization')->where('organization_id', $org->id)
            ->where('domain_name', Addr::domainOf($fromAddr))->first();
        $sandbox = $key?->environment === 'test';

        if (! $domain || (! $sandbox && ! $domain->isActive())) {
            throw new IngestException('domain_not_verified', 'The From domain is not verified for this organization.', 422);
        }
        if ($domain->status === 'SUSPENDED') {
            throw new IngestException('forbidden', 'Domain is suspended.', 403);
        }
        if ($key && ! $key->allowsDomain($domain->id)) {
            throw new IngestException('forbidden', 'This API key is not allowed to send from that domain.', 403);
        }

        $hash = hash('sha256', json_encode($this->canonical($d)));
        $recipients = $this->recipients($d);
        if ($recipients === []) {
            throw new IngestException('validation_failed', 'At least one recipient is required.', 422);
        }
        if (count($recipients) > config('postivo.limits.max_recipients')) {
            throw new IngestException('validation_failed', 'Too many recipients (max '.config('postivo.limits.max_recipients').').', 422);
        }

        $attachments = $this->attachments($d['attachments'] ?? []);

        $paths = [];
        $committed = false;
        $cleanup = static function () use (&$paths) {
            foreach ($paths as $path) {
                Storage::disk('local')->delete($path);
            }
            $paths = [];
        };
        // Committed savepoints do not propagate rollback callbacks to their ancestors.
        foreach (app('db.transactions')->getPendingTransactions() as $transaction) {
            if ($transaction->connection === DB::connection()->getName()) {
                $transaction->addCallbackForRollback($cleanup);
            }
        }
        try {
            [$email, $replay] = DB::transaction(function () use ($org, $key, $d, $source, $idemKey, $hash, $domain, $sandbox, $fromAddr, $fromName, $recipients, $attachments, $cleanup, &$paths, &$committed) {
                $cleanup();
                DB::afterRollBack($cleanup);
                // Serialize admissions per organization; SQLite does not enforce this lock.
                $org = Organization::query()->lockForUpdate()->findOrFail($org->id);
                if ($org->isSuspended()) {
                    throw new IngestException('forbidden', 'Organization is suspended.', 403);
                }
                if ($idemKey) {
                    // A locking/current read also works inside an existing REPEATABLE READ snapshot.
                    $existing = Email::withoutGlobalScopes()->where('organization_id', $org->id)
                        ->where('idempotency_key', $idemKey)->lockForUpdate()->first();
                    if ($existing) {
                        if ($existing->request_hash !== $hash) {
                            throw new IngestException('conflict', 'Idempotency-Key was already used with a different request body.', 409);
                        }
                        $existing->setRelation('dispatches', $existing->dispatches()
                            ->withoutGlobalScope('organization')->lockForUpdate()->get());

                        return [$existing, true];
                    }
                }

                $suppressed = collect($recipients)->filter(fn ($r) => $this->suppressions->isSuppressed($org->id, $r['email']));
                if ($suppressed->count() === count($recipients)) {
                    throw new IngestException('recipient_suppressed', 'All recipients are on the suppression list.', 422);
                }
                $billable = count($recipients) - $suppressed->count();
                if (! $sandbox && ($why = $this->quota->check($org, $billable))) {
                    throw new IngestException('quota_exceeded', $why, 429);
                }

                // Disarm rollback cleanup even if Laravel retains a failed commit-attempt record.
                DB::afterCommit(static function () use (&$committed, &$paths) {
                    $committed = true;
                    $paths = [];
                });
                if (! $sandbox) {
                    $this->quota->bump($org->id, 'sent', $billable);
                }

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

                foreach ($attachments as $a) {
                    $path = 'mail/attachments/'.$email->id.'/'.Str::uuid7();
                    $paths[] = $path;
                    Storage::disk('local')->put($path, $a['bin']);
                    DB::table('email_attachments')->insert([
                        'id' => (string) Str::uuid7(), 'email_id' => $email->id, 'filename' => $a['filename'],
                        'content_type' => $a['content_type'], 'size_bytes' => strlen($a['bin']),
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

                return [$email, false];
            }, 3);
        } catch (Throwable $e) {
            // Never delete durable attachments if an after-commit notification fails.
            if (! $committed) {
                $cleanup();
            }

            throw $e;
        }

        if ($replay) {
            return [$email, true];
        }

        $email->load('dispatches');
        // Publication runs outside admission locks and attachment rollback handling.
        DB::afterCommit(function () use ($email) {
            foreach ($email->dispatches as $disp) {
                $suppressed = $disp->status === DispatchStatus::SUPPRESSED;
                try {
                    $this->machine->record($disp, $suppressed ? 'suppressed' : 'queued', $suppressed ? ['reason' => 'recipient on suppression list'] : []);
                } catch (Throwable $e) {
                    report($e);
                }
                if ($suppressed) {
                    continue;
                }
                try {
                    $job = DispatchOutboundEmailJob::dispatch($disp->id)->onQueue('postivo_high');
                    if ($email->scheduled_at && $email->scheduled_at->isFuture()) {
                        $job->delay($email->scheduled_at);
                    }
                    unset($job);
                } catch (Throwable $e) {
                    // The durable QUEUED row remains recoverable by the stuck-dispatch scheduler.
                    report($e);
                }
            }
        });

        return [$email, false];
    }

    /** RFC 5322 ftext: printable ASCII excluding colon (also excludes whitespace). */
    public static function isValidHeaderName(string $name): bool
    {
        return preg_match('/\A[\x21-\x39\x3B-\x7E]+\z/', $name) === 1;
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

    /**
     * Decode & batasi lampiran sebelum transaksi agar payload rusak ditolak 422/413.
     *
     * @return array<int, array{bin: string, filename: string, content_type: string}>
     */
    private function attachments(array $items): array
    {
        $out = [];
        $bytes = 0;
        $max = (int) config('postivo.limits.max_payload_bytes');

        foreach ($items as $a) {
            $bin = is_string($a['content'] ?? null) ? base64_decode($a['content'], true) : false;
            if ($bin === false) {
                throw new IngestException('validation_failed', 'Attachment content must be valid base64.', 422);
            }
            $bytes += strlen($bin);
            if ($max > 0 && $bytes > $max) {
                throw new IngestException('payload_too_large', 'Attachments exceed the maximum message size.', 413);
            }
            $out[] = [
                'bin' => $bin,
                'filename' => mb_substr((string) ($a['filename'] ?? 'attachment'), 0, 255),
                'content_type' => mb_substr((string) ($a['content_type'] ?? 'application/octet-stream'), 0, 127),
            ];
        }

        return $out;
    }

    /** Urutkan kunci asosiatif agar hash idempoten tidak bergantung pada urutan JSON. */
    private function canonical(mixed $v): mixed
    {
        if (! is_array($v)) {
            return $v;
        }
        if (! array_is_list($v)) {
            ksort($v);
        }

        return array_map(fn ($item) => $this->canonical($item), $v);
    }
}
