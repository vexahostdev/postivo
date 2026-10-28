<?php

namespace Tests\Feature;

use App\Enums\DispatchStatus;
use App\Exceptions\IngestException;
use App\Http\Controllers\Api\V1\EmailController;
use App\Jobs\DeliverWebhookJob;
use App\Jobs\DispatchOutboundEmailJob;
use App\Models\ApiKey;
use App\Models\Domain;
use App\Models\Email;
use App\Models\EmailDispatch;
use App\Models\EmailEvent;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Suppression;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Notifications\QuotaWarningNotification;
use App\Services\ApiKeyService;
use App\Services\DispatchStateMachine;
use App\Services\DkimService;
use App\Services\EmailIngestService;
use App\Services\OutboundRelayService;
use App\Services\QuotaService;
use App\Services\WebhookDispatcher;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Mime\Email as MimeEmail;
use Tests\TestCase;

// Real commits are required; SQLite still cannot prove concurrency safety.
class MailSecurityTest extends TestCase
{
    use DatabaseMigrations;

    private Organization $org;

    private Domain $domain;

    private ApiKey $key;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        Notification::fake();
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-15 12:00:00'));
        config(['cache.default' => 'array']);
        $this->org = Organization::create(['name' => 'Mail security', 'slug' => 'mail-security']);
        $this->domain = Domain::withoutGlobalScope('organization')->create([
            'organization_id' => $this->org->id, 'domain_name' => 'mail.security.test', 'status' => 'ACTIVE',
            'dkim_private_key' => 'dummy-not-a-signing-key', 'dkim_public_key' => 'dummy',
            'return_path_host' => 'b.mail.security.test',
        ]);
        [$this->key, $this->token] = app(ApiKeyService::class)->create($this->org, 'sender');
        // Every worker test must explicitly opt into a fake relay/signing expectation.
        $this->mock(OutboundRelayService::class)->shouldNotReceive('send');
        $this->mock(DkimService::class)->shouldNotReceive('sign');
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'from' => 'Sender <sender@mail.security.test>', 'to' => ['recipient@example.test'],
            'subject' => 'Security regression', 'text' => 'Dummy message',
        ];
    }

    private function accept(array $overrides = [], ?string $idem = null): Email
    {
        return app(EmailIngestService::class)->accept($this->org, $this->key, $this->payload($overrides), 'API', $idem)[0];
    }

    private function work(EmailDispatch $dispatch): void
    {
        $this->app->call([new DispatchOutboundEmailJob($dispatch->id), 'handle']);
    }

    private function sent(): int
    {
        return (int) DB::table('usage_counters')->where('organization_id', $this->org->id)
            ->where('period', now()->format('Y-m'))->value('sent');
    }

    private function enableFakeRelay(?\Closure $atRelay = null): void
    {
        $this->mock(DkimService::class)->shouldReceive('sign')->once()->andReturnUsing(
            function (MimeEmail $message, Domain $domain) {
                $this->assertSame(0, DB::transactionLevel(), 'Signing must run outside all transactions.');
                $this->assertSame($this->domain->id, $domain->id);

                return $message;
            }
        );
        $this->mock(OutboundRelayService::class)->shouldReceive('send')->once()->andReturnUsing(
            function ($message, $returnPath, $recipient, $id) use ($atRelay) {
                $this->assertSame(0, DB::transactionLevel(), 'Relay must run outside all transactions.');
                if ($atRelay) {
                    $atRelay($message, $id);
                }

                return ['relay' => 'STALWART', 'response' => '250 dummy'];
            }
        );
    }

    public function test_deleted_domain_is_not_accepted_even_for_sandbox(): void
    {
        $this->domain->delete();
        foreach (['live', 'test'] as $environment) {
            $this->key->update(['environment' => $environment]);
            try {
                $this->accept();
                $this->fail('Soft-deleted domain was accepted.');
            } catch (IngestException $e) {
                $this->assertSame('domain_not_verified', $e->errorCode);
            }
        }
        $this->assertDatabaseCount('emails', 0);
        Queue::assertNothingPushed();
    }

    public static function authorityChanges(): iterable
    {
        foreach (['QUEUED', 'DEFERRED'] as $status) {
            foreach (['deleted_domain', 'pending_domain', 'failed_domain', 'suspended_domain', 'suspended_org',
                'missing_key', 'revoked_key', 'scope_removed', 'domain_disallowed', 'foreign_key', 'foreign_domain',
                'missing_test_key', 'revoked_test_key', 'suspended_test_domain', 'suspended_test_org'] as $change) {
                yield $status.' '.$change => [$status, $change];
            }
        }
    }

    #[DataProvider('authorityChanges')]
    public function test_worker_revalidates_authority_for_queued_and_deferred_mail(string $status, string $change): void
    {
        if (str_contains($change, 'test')) {
            $this->key->update(['environment' => 'test']);
        }
        $email = $this->accept();
        $dispatch = $email->dispatches->sole();
        $dispatch->update(['status' => $status, 'status_rank' => $status === 'DEFERRED' ? 1 : 0]);

        switch ($change) {
            case 'deleted_domain':
                $this->domain->delete();
                break;
            case 'pending_domain':
                $this->domain->update(['status' => 'PENDING']);
                break;
            case 'failed_domain':
                $this->domain->update(['status' => 'FAILED']);
                break;
            case 'suspended_domain':
            case 'suspended_test_domain':
                $this->domain->update(['status' => 'SUSPENDED']);
                break;
            case 'suspended_org':
            case 'suspended_test_org':
                $this->org->update(['status' => 'SUSPENDED']);
                break;
            case 'missing_key':
            case 'missing_test_key':
                $this->key->delete();
                break;
            case 'revoked_key':
            case 'revoked_test_key':
                $this->key->update(['revoked_at' => now()]);
                break;
            case 'scope_removed':
                $this->key->update(['scopes' => ['emails:read']]);
                break;
            case 'domain_disallowed':
                $this->key->update(['domain_ids' => ['00000000-0000-4000-8000-000000000000']]);
                break;
            case 'foreign_key':
            case 'foreign_domain':
                $other = Organization::create(['name' => 'Other', 'slug' => 'other']);
                ($change === 'foreign_key' ? $this->key : $this->domain)->update(['organization_id' => $other->id]);
                break;
        }

        $this->work($dispatch);
        $this->assertSame(DispatchStatus::FAILED, $dispatch->refresh()->status);
        $this->assertSame(0, (int) $dispatch->attempts);
        $this->assertNull($dispatch->relay);
        $this->assertSame([], Storage::disk('local')->allFiles('mail/raw'));
    }

    public function test_valid_sender_relays_once_and_ordinary_headers_survive_but_reserved_names_do_not(): void
    {
        $this->key->update(['domain_ids' => [$this->domain->id]]);
        $email = $this->accept(['headers' => ['X-Dummy' => 'ordinary', 'fRoM' => 'attacker@example.test', 'Subject' => 'wrong']]);
        $this->enableFakeRelay(function (MimeEmail $message) {
            $this->assertSame('ordinary', $message->getHeaders()->get('X-Dummy')->getBodyAsString());
            $this->assertSame('sender@mail.security.test', $message->getFrom()[0]->getAddress());
            $this->assertSame('Security regression', $message->getSubject());
            $this->assertSame(1, $this->sent(), 'Quota must be reserved before relay.');
        });
        $dispatch = $email->dispatches->sole();
        $this->work($dispatch);
        $this->work($dispatch);
        $this->assertSame(DispatchStatus::SENT, $dispatch->refresh()->status);
        $this->assertSame(1, (int) $dispatch->attempts);
        $this->assertSame(1, $this->sent());
    }

    public function test_null_api_key_still_allows_internal_tooling_to_send(): void
    {
        [$email] = app(EmailIngestService::class)->accept($this->org, null, $this->payload(), 'TOOL');
        $this->assertNull($email->api_key_id);
        $this->enableFakeRelay();
        $this->work($email->dispatches->sole());
        $this->assertSame(DispatchStatus::SENT, $email->dispatches->sole()->refresh()->status);
    }

    public function test_pending_sandbox_domain_bypasses_quota_but_never_relays(): void
    {
        $this->domain->update(['status' => 'PENDING']);
        $this->key->update(['environment' => 'test']);
        $this->org->update(['monthly_quota' => 0, 'daily_limit' => 0]);
        $email = $this->accept();
        $dispatch = $email->dispatches->sole();
        $this->work($dispatch);
        $this->assertSame(DispatchStatus::DELIVERED, $dispatch->refresh()->status);
        $this->assertSame('SANDBOX', $dispatch->relay);
        $this->assertSame(0, $this->sent());
        Notification::assertNothingSent();
    }

    public function test_suspended_sandbox_domain_is_rejected_at_ingest(): void
    {
        $this->key->update(['environment' => 'test']);
        $this->domain->update(['status' => 'SUSPENDED']);
        $this->expectException(IngestException::class);
        $this->accept();
    }

    public static function malformedHeaderNames(): iterable
    {
        foreach (['', 'X:Dummy', 'X Dummy', "X\tDummy", "X\r\nBcc", "X\0Dummy", "X\x1fDummy", "X\x7fDummy", "X-\xc3\xa9"] as $name) {
            yield (bin2hex($name) ?: 'empty') => [$name];
        }
    }

    #[DataProvider('malformedHeaderNames')]
    public function test_api_and_smtp_service_reject_malformed_header_names(string $name): void
    {
        $payload = $this->payload(['headers' => [$name => 'dummy']]);
        $this->withToken($this->token)->postJson('/v1/emails', $payload)->assertUnprocessable();
        try {
            app(EmailIngestService::class)->accept($this->org, $this->key, $payload, 'SMTP');
            $this->fail('Service boundary accepted an invalid field name.');
        } catch (IngestException $e) {
            $this->assertSame('validation_failed', $e->errorCode);
            $this->assertSame(422, $e->httpStatus);
        }
        $this->assertDatabaseCount('emails', 0);
        Queue::assertNothingPushed();
    }

    #[DataProvider('malformedHeaderNames')]
    public function test_worker_rejects_legacy_malformed_headers_before_signing_or_relay(string $name): void
    {
        $email = $this->accept();
        $email->update(['headers' => [$name => 'dummy']]);
        $dispatch = $email->dispatches->sole();
        $this->work($dispatch);
        $this->assertSame(DispatchStatus::FAILED, $dispatch->refresh()->status);
        $this->assertSame('internal_error', $dispatch->smtp_response);
    }

    public function test_header_predicate_matches_exact_rfc_ascii_range_and_prefixed_rules(): void
    {
        for ($byte = 0; $byte <= 255; $byte++) {
            $expected = ($byte >= 33 && $byte <= 57) || ($byte >= 59 && $byte <= 126);
            $this->assertSame($expected, EmailIngestService::isValidHeaderName(chr($byte)), 'Byte '.$byte);
        }
        $this->assertFalse(EmailIngestService::isValidHeaderName(''));
        $this->assertTrue(Validator::make($this->payload(['headers' => ['X-Dummy' => 'ok']]), EmailController::rules())->passes());
        $validator = Validator::make(['message' => $this->payload(['headers' => ['X:Bad' => 'no']])], EmailController::rules('message.'));
        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('message.headers'));
    }

    public function test_cancellation_before_claim_prevents_all_relay(): void
    {
        $email = $this->accept(['to' => ['one@example.test', 'two@example.test']]);
        $this->withToken($this->token)->postJson('/v1/emails/'.$email->id.'/cancel')->assertOk();
        foreach ($email->dispatches as $dispatch) {
            $this->work($dispatch);
            $this->assertSame(DispatchStatus::FAILED, $dispatch->refresh()->status);
            $this->assertSame(0, (int) $dispatch->attempts);
            $this->assertSame('canceled', $dispatch->smtp_response);
        }
        $this->assertNotNull($email->refresh()->canceled_at);
        $this->withToken($this->token)->postJson('/v1/emails/'.$email->id.'/cancel')->assertConflict();
    }

    public function test_claim_before_cancel_conflicts_even_when_another_recipient_is_queued(): void
    {
        $email = $this->accept(['to' => ['one@example.test', 'two@example.test']]);
        $this->enableFakeRelay(function () use ($email) {
            // Runs after the real claim commits, but before the fake relay completes.
            $this->withToken($this->token)->postJson('/v1/emails/'.$email->id.'/cancel')->assertConflict();
            $this->assertNull($email->refresh()->canceled_at);
        });
        $this->work($email->dispatches->first());
        $this->assertSame(1, $email->dispatches()->where('status', 'QUEUED')->count());
        $this->assertSame(1, $email->dispatches()->where('status', 'SENT')->count());
    }

    public function test_no_queued_dispatch_is_not_cancelable(): void
    {
        $email = $this->accept();
        $email->dispatches->sole()->update(['status' => 'DEFERRED', 'status_rank' => 1]);
        $this->withToken($this->token)->postJson('/v1/emails/'.$email->id.'/cancel')->assertConflict();
        $this->assertNull($email->refresh()->canceled_at);
    }

    public function test_fresh_canceled_parent_prevents_deferred_retry_after_other_recipient_is_canceled(): void
    {
        $email = $this->accept(['to' => ['one@example.test', 'two@example.test']]);
        $deferred = $email->dispatches->first();
        $deferred->update(['status' => 'DEFERRED', 'status_rank' => 1]);
        $this->withToken($this->token)->postJson('/v1/emails/'.$email->id.'/cancel')->assertOk();
        $this->work($deferred);
        $this->assertSame(DispatchStatus::FAILED, $deferred->refresh()->status);
        $this->assertSame('canceled', $deferred->smtp_response);
    }

    public static function quotaBoundaries(): iterable
    {
        yield 'monthly' => [1, null];
        yield 'daily' => [100, 1];
    }

    #[DataProvider('quotaBoundaries')]
    public function test_two_request_quota_boundary_and_idempotent_replay(int $monthly, ?int $daily): void
    {
        $this->org->update(['monthly_quota' => $monthly, 'daily_limit' => $daily]);
        $first = $this->withToken($this->token)->withHeader('Idempotency-Key', 'first')
            ->postJson('/v1/emails', $this->payload())->assertAccepted();
        $this->withHeader('Idempotency-Key', 'second')->postJson('/v1/emails', $this->payload())
            ->assertStatus(429)->assertJsonPath('error.code', 'quota_exceeded');
        $this->withHeader('Idempotency-Key', 'first')->postJson('/v1/emails', $this->payload())
            ->assertOk()->assertJsonPath('id', $first->json('id'));
        $this->postJson('/v1/emails', $this->payload(['subject' => 'Different']))->assertConflict();
        $this->assertSame(1, $this->sent());
        $this->assertDatabaseCount('emails', 1);
        $this->assertDatabaseCount('email_dispatches', 1);
        Queue::assertPushed(DispatchOutboundEmailJob::class, 1);
    }

    public function test_quota_uses_fresh_locked_organization_instead_of_stale_caller_limits(): void
    {
        $stale = $this->org;
        Organization::whereKey($stale->id)->update(['monthly_quota' => 0]);
        try {
            app(EmailIngestService::class)->accept($stale, $this->key, $this->payload());
            $this->fail('Stale quota admitted a message.');
        } catch (IngestException $e) {
            $this->assertSame('quota_exceeded', $e->errorCode);
        }
        $this->assertDatabaseCount('emails', 0);
        $this->assertSame(0, $this->sent());
    }

    public function test_admission_locks_org_rechecks_idempotency_and_reserves_before_dispatch_writes(): void
    {
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries) {
            $sql = strtolower($query->sql);
            if (str_starts_with($sql, 'select') && str_contains($sql, 'organizations')) {
                $queries[] = ['organization', DB::transactionLevel()];
            }
            if (str_starts_with($sql, 'select') && str_contains($sql, 'idempotency_key')) {
                $queries[] = ['idempotency', DB::transactionLevel()];
            }
        });
        $quota = Mockery::mock(QuotaService::class)->makePartial();
        $quota->shouldReceive('check')->once()->andReturnUsing(function (Organization $org, int $count) use (&$queries) {
            $this->assertGreaterThan(0, DB::transactionLevel());
            $this->assertSame([['organization', 1], ['idempotency', 1]], $queries);
            $this->assertSame(1, $count);
            $this->assertDatabaseCount('emails', 0);

            return null;
        });
        $this->app->instance(QuotaService::class, $quota);
        Event::listen('eloquent.creating: '.EmailDispatch::class, function () {
            $this->assertGreaterThan(0, DB::transactionLevel());
            $this->assertSame(1, $this->sent());
        });
        $first = $this->accept([], 'locked');
        $this->assertSame($first->id, $this->accept([], 'locked')->id);
        $this->assertSame(1, $this->sent());
    }

    public function test_mysql_grammar_emits_row_locks_without_claiming_sqlite_proves_contention(): void
    {
        $query = Organization::whereKey($this->org->id)->lockForUpdate()->toBase();
        $sql = (new MySqlGrammar(DB::connection()))->compileSelect($query);
        $this->assertStringEndsWith('for update', $sql);
    }

    public function test_quota_check_initializes_the_month_before_reading_without_spending_quota(): void
    {
        $this->org->update(['monthly_quota' => 2]);
        $this->assertDatabaseCount('usage_counters', 0);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries) {
            if (str_contains(strtolower($query->sql), 'usage_counters')) {
                $queries[] = [strtolower($query->sql), DB::transactionLevel()];
            }
        });

        DB::transaction(function () use (&$queries) {
            $org = Organization::lockForUpdate()->findOrFail($this->org->id);
            $quota = app(QuotaService::class);
            $this->assertNull($quota->check($org, 1));
            $this->assertStringStartsWith('insert', $queries[0][0]);
            $this->assertStringStartsWith('select', $queries[1][0]);
            $this->assertSame([1, 1], [$queries[0][1], $queries[1][1]]);
            if (DB::connection()->getDriverName() === 'mysql') {
                $this->assertStringEndsWith('for update', $queries[1][0]);
            }
            $this->assertDatabaseCount('usage_counters', 1);
            $this->assertSame(0, $this->sent());
            $quota->bump($org->id, 'sent');
            $this->assertNull($quota->check($org, 1));
            $this->assertNotNull($quota->check($org, 2));
            $this->assertSame(1, $this->sent());
            $this->assertDatabaseCount('usage_counters', 1);
        });
    }

    public function test_replay_dispatch_query_uses_a_current_lock_and_removes_only_the_org_scope(): void
    {
        $payload = ['to' => ['one@example.test', 'two@example.test']];
        $original = $this->accept($payload, 'replay-lock');
        $other = Organization::create(['name' => 'Other scope', 'slug' => 'other-scope']);
        $scopes = EmailDispatch::getAllGlobalScopes();
        $queries = [];
        EmailDispatch::addGlobalScope('replay-lock-probe', function (Builder $query) use (&$queries) {
            $queries[] = [clone $query->getQuery(), $query->removedScopes(), DB::transactionLevel()];
        });
        $this->app->instance('org.current', $other);
        try {
            [$replay, $isReplay] = app(EmailIngestService::class)->accept(
                $this->org, $this->key, $this->payload($payload), 'API', 'replay-lock'
            );
        } finally {
            EmailDispatch::setAllGlobalScopes($scopes);
            $this->app->forgetInstance('org.current');
        }

        $this->assertTrue($isReplay);
        $this->assertSame($original->id, $replay->id);
        $this->assertSame($original->dispatches->modelKeys(), $replay->dispatches->modelKeys());
        $this->assertCount(1, $queries, 'Replay must preserve unrelated global scopes.');
        [$query, $removedScopes, $level] = $queries[0];
        $this->assertSame(['organization'], $removedScopes);
        $this->assertSame(1, $level);
        $this->assertTrue($query->lock, 'Inspect the actual replay relation, not a synthetic locked query.');
        $this->assertStringEndsWith('for update', (new MySqlGrammar(DB::connection()))->compileSelect($query));
        $this->assertSame(2, $this->sent());
        Queue::assertPushed(DispatchOutboundEmailJob::class, 2);
    }

    public function test_mysql_repeatable_read_replay_sees_dispatches_committed_after_its_snapshot(): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires an isolated MySQL test database; SQLite cannot validate current reads.');
        }
        foreach (['organizations', 'emails', 'email_dispatches', 'usage_counters'] as $table) {
            $engine = $connection->table('information_schema.tables')
                ->where('table_schema', $connection->getDatabaseName())->where('table_name', $table)->value('engine');
            if (strtolower((string) $engine) !== 'innodb') {
                $this->markTestSkipped('The REPEATABLE READ fixture requires InnoDB tables.');
            }
        }

        $this->org->update(['monthly_quota' => 2, 'daily_limit' => 2]);
        $default = DB::getDefaultConnection();
        $peerName = 'mail_security_peer_'.bin2hex(random_bytes(4));
        config(['database.connections.'.$peerName => ['name' => $peerName] + $connection->getConfig()]);
        $peer = DB::connection($peerName);
        // Separate request callback state, without a worker process or another database.
        $peer->setTransactionManager(new DatabaseTransactionsManager);
        $payload = ['to' => ['one@example.test', 'two@example.test']];
        try {
            $this->assertNotSame($connection->getPdo(), $peer->getPdo());
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $connection->beginTransaction();
            foreach (['emails', 'email_dispatches', 'usage_counters'] as $table) {
                $this->assertSame(0, $connection->table($table)->where('organization_id', $this->org->id)->count());
            }

            DB::setDefaultConnection($peerName);
            $original = $this->accept($payload, 'snapshot-replay');
            $originalIds = $original->dispatches->modelKeys();
            $this->assertSame(0, $peer->transactionLevel());
            $this->assertSame(2, $this->sent());
            Queue::assertPushed(DispatchOutboundEmailJob::class, 2);

            DB::setDefaultConnection($default);
            $this->assertSame(0, $connection->table('emails')->where('organization_id', $this->org->id)->count());
            $this->assertSame(0, $connection->table('email_dispatches')->where('organization_id', $this->org->id)->count());
            [$replay, $isReplay] = app(EmailIngestService::class)->accept(
                $this->org, $this->key, $this->payload($payload), 'API', 'snapshot-replay'
            );
            $this->assertTrue($isReplay);
            $this->assertSame($original->id, $replay->id);
            $this->assertEqualsCanonicalizing($originalIds, $replay->dispatches->modelKeys());
            $this->assertSame(2, (int) $connection->table('usage_counters')
                ->where('organization_id', $this->org->id)->where('period', now()->format('Y-m'))
                ->lockForUpdate()->value('sent'));
            $connection->commit();
        } finally {
            DB::setDefaultConnection($default);
            if ($peer->transactionLevel() > 0) {
                $peer->rollBack(0);
            }
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack(0);
            }
            DB::purge($peerName);
            config()->offsetUnset('database.connections.'.$peerName);
        }
        $this->assertSame(2, $this->sent());
        $this->assertDatabaseCount('usage_counters', 1);
        $this->assertDatabaseCount('emails', 1);
        $this->assertDatabaseCount('email_dispatches', 2);
        Queue::assertPushed(DispatchOutboundEmailJob::class, 2);
    }

    public function test_suppressed_recipients_do_not_spend_quota(): void
    {
        $this->org->update(['monthly_quota' => 1, 'daily_limit' => 1]);
        Suppression::withoutGlobalScopes()->create([
            'organization_id' => $this->org->id, 'email' => 'blocked@example.test',
            'email_hash' => hash('sha256', 'blocked@example.test'), 'reason' => 'MANUAL', 'created_at' => now(),
        ]);
        $email = $this->accept(['to' => ['blocked@example.test', 'ok@example.test']]);
        $this->assertSame(1, $this->sent());
        $this->assertSame(1, $email->dispatches->where('status', DispatchStatus::SUPPRESSED)->count());
        Queue::assertPushed(DispatchOutboundEmailJob::class, 1);
    }

    private function addObservers(): User
    {
        $user = User::create(['name' => 'Owner', 'email' => 'owner@security.test', 'password' => 'dummy-password']);
        Member::create(['organization_id' => $this->org->id, 'user_id' => $user->id, 'role' => 'owner']);
        WebhookEndpoint::withoutGlobalScopes()->create([
            'organization_id' => $this->org->id, 'url' => 'https://hooks.example.test/unused',
            'secret' => 'dummy-webhook-secret', 'events' => ['email.queued', 'email.failed'], 'is_active' => true,
        ]);

        return $user;
    }

    private function assertRetainedAttachment(Email $email): void
    {
        $this->assertDatabaseCount('email_attachments', 1);
        $attachment = DB::table('email_attachments')->where('email_id', $email->id)->sole();
        $this->assertSame('dummy.txt', $attachment->filename);
        $this->assertSame([$attachment->storage_path], Storage::disk('local')->allFiles('mail/attachments'));
        $this->assertSame('dummy', Storage::disk('local')->get($attachment->storage_path));
    }

    public static function warningFailures(): iterable
    {
        foreach (['cache', 'notification'] as $failure) {
            yield $failure.' top-level' => [$failure, false];
            yield $failure.' nested' => [$failure, true];
        }
    }

    #[DataProvider('warningFailures')]
    public function test_warning_failure_cannot_block_committed_mail_or_delete_attachments(string $stage, bool $nested): void
    {
        $this->org->update(['monthly_quota' => 1]);
        $user = $this->addObservers();
        Exceptions::fake();
        $notifications = Notification::getFacadeRoot();
        $failure = new RuntimeException('Dummy quota '.$stage.' failure');
        $calls = 0;
        $throw = function () use (&$calls, $failure) {
            $calls++;
            $this->assertSame(0, DB::transactionLevel());
            throw $failure;
        };
        if ($stage === 'cache') {
            Cache::shouldReceive('add')->once()
                ->with('postivo:quota:'.$this->org->id.':'.now()->format('Y-m').':100', 1, Mockery::type(\DateTimeInterface::class))
                ->andReturnUsing($throw);
        } else {
            Notification::shouldReceive('send')->once()->withArgs(
                fn ($users, $notification) => $users->modelKeys() === [$user->id] && $notification instanceof QuotaWarningNotification
            )->andReturnUsing($throw);
        }
        $payload = ['attachments' => [['filename' => 'dummy.txt', 'content' => base64_encode('dummy')]]];
        if ($nested) {
            DB::beginTransaction();
        }
        try {
            $email = $this->accept($payload, 'warning-failure');
            if ($nested) {
                $this->assertSame(0, $calls);
                Queue::assertNothingPushed();
                $notifications->assertNothingSent();
                Exceptions::assertNothingReported();
                $this->assertRetainedAttachment($email);
                DB::commit();
            }
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack(0);
            }
        }

        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame($email->id, $this->accept($payload, 'warning-failure')->id);
        $this->assertSame(1, $calls);
        $this->assertSame(1, $this->sent());
        $this->assertDatabaseCount('usage_counters', 1);
        $this->assertDatabaseCount('emails', 1);
        $this->assertDatabaseCount('email_dispatches', 1);
        $this->assertDatabaseCount('email_events', 1);
        $this->assertSame(DispatchStatus::QUEUED, $email->dispatches->sole()->refresh()->status);
        $this->assertRetainedAttachment($email);
        Queue::assertPushed(DispatchOutboundEmailJob::class, 1);
        Queue::assertPushed(DispatchOutboundEmailJob::class, fn ($job) => $job->dispatchId === $email->dispatches->sole()->id);
        Queue::assertPushed(DeliverWebhookJob::class, 1);
        Exceptions::assertReported(fn (RuntimeException $e) => $e === $failure);
        Exceptions::assertReportedCount(1);
    }

    public static function publicationRecordFailures(): iterable
    {
        yield 'event insert' => ['event'];
        yield 'webhook fanOut' => ['webhook'];
    }

    #[DataProvider('publicationRecordFailures')]
    public function test_first_recipient_record_failure_does_not_block_any_mail_job(string $stage): void
    {
        $this->org->update(['monthly_quota' => 10]);
        $this->addObservers();
        Exceptions::fake();
        $failure = new RuntimeException('Dummy '.$stage.' failure');
        $attempted = [];
        $beforeRecord = function (EmailEvent $event) use (&$attempted, $failure) {
            $this->assertSame(0, DB::transactionLevel());
            $attempted[] = $event->dispatch_id;
            if (count($attempted) === 1) {
                throw $failure;
            }
        };
        if ($stage === 'event') {
            Event::listen('eloquent.creating: '.EmailEvent::class, $beforeRecord);
        } else {
            $webhooks = app(WebhookDispatcher::class);
            $this->mock(WebhookDispatcher::class)->shouldReceive('fanOut')->times(3)
                ->andReturnUsing(function (EmailEvent $event) use ($beforeRecord, $webhooks) {
                    $beforeRecord($event);
                    $webhooks->fanOut($event);
                });
        }

        $email = $this->accept(['to' => ['one@example.test', 'two@example.test', 'three@example.test']]);
        $this->assertSame($email->dispatches->modelKeys(), $attempted);
        $this->assertSame(3, $this->sent());
        $this->assertDatabaseCount('emails', 1);
        $this->assertDatabaseCount('email_dispatches', 3);
        $this->assertDatabaseCount('email_events', $stage === 'event' ? 2 : 3);
        Queue::assertPushed(DispatchOutboundEmailJob::class, 3);
        foreach ($email->dispatches as $dispatch) {
            $this->assertSame(DispatchStatus::QUEUED, $dispatch->refresh()->status);
            Queue::assertPushed(DispatchOutboundEmailJob::class, fn ($job) => $job->dispatchId === $dispatch->id && $job->queue === 'postivo_high');
        }
        Queue::assertPushed(DeliverWebhookJob::class, 2);
        Exceptions::assertReported(fn (RuntimeException $e) => $e === $failure);
        Exceptions::assertReportedCount(1);
    }

    public function test_queue_failure_leaves_a_recoverable_row_and_later_recipients_still_publish(): void
    {
        $this->org->update(['monthly_quota' => 10]);
        Exceptions::fake();
        $bus = app(BusDispatcher::class);
        $failure = new RuntimeException('Dummy mail queue publication failure');
        $attempted = [];
        $this->mock(BusDispatcher::class)->shouldReceive('dispatch')->times(3)
            ->andReturnUsing(function (DispatchOutboundEmailJob $job) use (&$attempted, $failure, $bus) {
                $this->assertSame(0, DB::transactionLevel());
                $attempted[] = $job->dispatchId;
                if (count($attempted) === 1) {
                    throw $failure;
                }

                return $bus->dispatch($job);
            });
        try {
            $email = $this->accept([
                'to' => ['one@example.test', 'two@example.test', 'three@example.test'],
                'attachments' => [['filename' => 'dummy.txt', 'content' => base64_encode('dummy')]],
            ]);
        } finally {
            $this->app->instance(BusDispatcher::class, $bus);
        }
        $this->assertSame($email->dispatches->modelKeys(), $attempted);
        Queue::assertPushed(DispatchOutboundEmailJob::class, 2);
        Queue::assertNotPushed(DispatchOutboundEmailJob::class, fn ($job) => $job->dispatchId === $attempted[0]);
        foreach ($email->dispatches->slice(1) as $dispatch) {
            Queue::assertPushed(DispatchOutboundEmailJob::class, fn ($job) => $job->dispatchId === $dispatch->id);
        }
        $this->assertSame(3, $this->sent());
        $this->assertDatabaseCount('emails', 1);
        $this->assertDatabaseCount('email_dispatches', 3);
        $this->assertDatabaseCount('email_events', 3);
        foreach ($email->dispatches as $dispatch) {
            $this->assertSame(DispatchStatus::QUEUED, $dispatch->refresh()->status);
            $this->assertSame(0, (int) $dispatch->attempts);
        }
        $this->assertRetainedAttachment($email);
        Exceptions::assertReported(fn (RuntimeException $e) => $e === $failure);
        Exceptions::assertReportedCount(1);

        $this->travel(16)->minutes();
        Queue::fake();
        $this->artisan('postivo:requeue-stuck')->assertSuccessful();
        Queue::assertPushed(DispatchOutboundEmailJob::class, 3);
        Queue::assertPushed(DispatchOutboundEmailJob::class, fn ($job) => $job->dispatchId === $attempted[0] && $job->queue === 'postivo_default');
        $this->assertSame(3, $this->sent());
        $this->assertRetainedAttachment($email);
    }

    public function test_notifications_and_mail_and_webhook_publication_wait_for_outer_commit(): void
    {
        $this->org->update(['monthly_quota' => 1]);
        $user = $this->addObservers();
        DB::beginTransaction();
        try {
            $email = $this->accept([], 'outer');
            $this->assertSame($email->id, $this->accept([], 'outer')->id);
            $this->assertSame(1, $this->sent());
            Queue::assertNothingPushed();
            Notification::assertNothingSent();
            $this->assertDatabaseCount('email_events', 0);
            $this->assertFalse(Cache::has('postivo:quota:'.$this->org->id.':'.now()->format('Y-m').':100'));
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        Notification::assertSentTo($user, QuotaWarningNotification::class);
        Queue::assertPushed(DispatchOutboundEmailJob::class, 1);
        Queue::assertPushed(DeliverWebhookJob::class, 1);
    }

    public function test_outer_rollback_releases_reservation_discards_publication_and_cleans_attachments(): void
    {
        $this->org->update(['monthly_quota' => 1]);
        $this->addObservers();
        DB::beginTransaction();
        try {
            // Include a committed caller savepoint: its rollback callbacks must reach the root.
            DB::transaction(fn () => $this->accept(['attachments' => [
                ['filename' => 'dummy.txt', 'content' => base64_encode('dummy')],
            ]], 'rolled-back'));
            $this->assertSame(1, $this->sent());
            $this->assertCount(1, Storage::disk('local')->allFiles('mail/attachments'));
        } finally {
            DB::rollBack();
        }
        $this->assertSame(0, $this->sent());
        $this->assertDatabaseCount('emails', 0);
        $this->assertDatabaseCount('email_dispatches', 0);
        $this->assertDatabaseCount('email_attachments', 0);
        $this->assertDatabaseCount('email_events', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('mail/attachments'));
        Queue::assertNothingPushed();
        Notification::assertNothingSent();
        $this->accept([], 'rolled-back');
        $this->assertSame(1, $this->sent());
        Queue::assertPushed(DispatchOutboundEmailJob::class, 1);
    }

    public static function admissionDeadlocks(): iterable
    {
        yield 'top-level second attempt succeeds' => [1, false];
        yield 'top-level third attempt succeeds' => [2, false];
        yield 'top-level retry exhaustion' => [3, false];
        yield 'nested deadlock retried by outer owner' => [1, true];
        yield 'nested owner retry exhaustion' => [3, true];
    }

    #[DataProvider('admissionDeadlocks')]
    public function test_simulated_admission_deadlocks_clean_each_attempt_and_discard_its_callbacks(int $failures, bool $nested): void
    {
        $this->org->update(['monthly_quota' => 1]);
        $user = $this->addObservers();
        Exceptions::fake();
        $disk = Storage::disk('local');
        $deletedPaths = [];
        $filesystem = Mockery::mock($disk);
        $filesystem->shouldReceive('delete')->times($failures)->andReturnUsing(function ($path) use ($disk, &$deletedPaths) {
            $disk->assertExists($path);
            $deletedPaths[] = $path;

            return $disk->delete($path);
        });
        Storage::set('local', $filesystem);
        $paths = [];
        $committedAttempts = [];
        $rolledBackAttempts = [];
        $attempts = $outerAttempts = $bubbled = 0;
        $message = 'Deadlock found when trying to get lock (simulated after attachment creation)';
        Event::listen('eloquent.creating: '.EmailDispatch::class, function (EmailDispatch $dispatch) use (
            $failures, $nested, $disk, $message, &$attempts, &$paths, &$committedAttempts, &$rolledBackAttempts
        ) {
            $attempt = ++$attempts;
            $this->assertSame($nested ? 2 : 1, DB::transactionLevel());
            foreach ($paths as $oldPath) {
                $disk->assertMissing($oldPath);
            }
            $attachment = DB::table('email_attachments')->where('email_id', $dispatch->email_id)->sole();
            $path = $attachment->storage_path;
            $paths[] = $path;
            $this->assertSame([$path], $disk->allFiles('mail/attachments'));
            $this->assertSame(1, $this->sent());
            $this->assertDatabaseCount('emails', 1);
            $this->assertDatabaseCount('email_attachments', 1);
            Queue::assertNothingPushed();
            Notification::assertNothingSent();
            DB::afterCommit(function () use ($attempt, &$committedAttempts) {
                $committedAttempts[] = $attempt;
            });
            DB::afterRollBack(function () use ($attempt, $path, $disk, &$rolledBackAttempts) {
                $disk->assertMissing($path);
                $rolledBackAttempts[] = $attempt;
            });
            if ($attempt <= $failures) {
                // Laravel's concurrency detector recognizes this message; no real contention is simulated.
                throw new RuntimeException($message);
            }
        });
        $payload = ['attachments' => [['filename' => 'dummy.txt', 'content' => base64_encode('dummy')]]];
        $caught = null;
        try {
            if ($nested) {
                $email = DB::transaction(function () use ($payload, &$outerAttempts, &$bubbled) {
                    $outerAttempts++;
                    try {
                        return $this->accept($payload, 'deadlock');
                    } catch (DeadlockException $e) {
                        $bubbled++;
                        $this->assertSame(1, DB::transactionLevel());
                        throw $e;
                    }
                }, 3);
            } else {
                $email = $this->accept($payload, 'deadlock');
            }
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $expectedAttempts = min($failures + 1, 3);
        $this->assertSame($expectedAttempts, $attempts);
        $this->assertSame($nested ? $expectedAttempts : 0, $outerAttempts);
        $this->assertSame($nested ? $failures : 0, $bubbled);
        $this->assertCount($expectedAttempts, array_unique($paths));
        $this->assertSame(array_slice($paths, 0, $failures), $deletedPaths, 'Cleanup must reset its path list.');
        $this->assertSame(range(1, $failures), $rolledBackAttempts);
        $this->assertSame(0, DB::transactionLevel());
        // A later unrelated commit must not release abandoned callbacks.
        DB::transaction(fn () => null);
        Exceptions::assertNothingReported();
        if ($failures === 3) {
            $this->assertInstanceOf($nested ? DeadlockException::class : RuntimeException::class, $caught);
            $this->assertSame($message, $caught->getMessage());
            $this->assertSame([], $committedAttempts);
            $this->assertSame([], $disk->allFiles('mail/attachments'));
            $this->assertSame(0, $this->sent());
            foreach (['usage_counters', 'emails', 'email_dispatches', 'email_attachments', 'email_events', 'webhook_deliveries'] as $table) {
                $this->assertDatabaseCount($table, 0);
            }
            Queue::assertNothingPushed();
            Notification::assertNothingSent();
            $this->assertFalse(Cache::has('postivo:quota:'.$this->org->id.':'.now()->format('Y-m').':100'));

            return;
        }

        $this->assertNull($caught);
        $this->assertSame([$expectedAttempts], $committedAttempts);
        $this->assertSame($email->id, $this->accept($payload, 'deadlock')->id);
        $this->assertSame(1, $this->sent());
        foreach (['usage_counters', 'emails', 'email_dispatches', 'email_events', 'webhook_deliveries'] as $table) {
            $this->assertDatabaseCount($table, 1);
        }
        $this->assertRetainedAttachment($email);
        Queue::assertPushed(DispatchOutboundEmailJob::class, 1);
        Queue::assertPushed(DispatchOutboundEmailJob::class, fn ($job) => $job->dispatchId === $email->dispatches->sole()->id);
        Queue::assertPushed(DeliverWebhookJob::class, 1);
        Notification::assertSentToTimes($user, QuotaWarningNotification::class, 1);
    }

    public function test_commit_retry_keeps_committed_attachments_after_a_later_transaction_rolls_back(): void
    {
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $proxy = Mockery::mock($pdo);
        $attemptedPaths = [];
        $proxy->shouldReceive('commit')->twice()->andReturnUsing(function () use ($pdo, &$attemptedPaths) {
            $path = DB::table('email_attachments')->sole()->storage_path;
            Storage::disk('local')->assertExists($path);
            foreach ($attemptedPaths as $previousPath) {
                Storage::disk('local')->assertMissing($previousPath);
            }
            $attemptedPaths[] = $path;
            if (count($attemptedPaths) === 1) {
                throw new \PDOException('Deadlock found when trying to get lock (simulated at commit)', 40001);
            }

            return $pdo->commit();
        });
        $connection->setPdo($proxy);
        try {
            $email = $this->accept(['attachments' => [['filename' => 'dummy.txt', 'content' => base64_encode('dummy')]]]);
            $this->assertCount(2, array_unique($attemptedPaths));
            $this->assertRetainedAttachment($email);
            $this->assertSame(1, $this->sent());
            Queue::assertPushed(DispatchOutboundEmailJob::class, 1);

            // Exercise Laravel's retained failed-commit ancestry with a separate rollback.
            $connection->beginTransaction();
            $connection->rollBack();

            $this->assertRetainedAttachment($email);
            $this->assertDatabaseCount('emails', 1);
            $this->assertDatabaseCount('email_dispatches', 1);
            $this->assertSame(1, $this->sent());
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack(0);
            }
            $connection->setPdo($pdo);
        }
    }

    public function test_dispatch_insert_failure_rolls_back_counter_and_removes_attachment_files(): void
    {
        Event::listen('eloquent.creating: '.EmailDispatch::class, function () {
            throw new RuntimeException('Dummy dispatch insert failure');
        });
        try {
            $this->accept(['attachments' => [['filename' => 'dummy.txt', 'content' => base64_encode('dummy')]]]);
            $this->fail('Expected insert failure.');
        } catch (RuntimeException $e) {
            $this->assertSame('Dummy dispatch insert failure', $e->getMessage());
        }
        $this->assertSame(0, $this->sent());
        $this->assertDatabaseCount('emails', 0);
        $this->assertDatabaseCount('email_dispatches', 0);
        $this->assertDatabaseCount('email_attachments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('mail/attachments'));
        Queue::assertNothingPushed();
        Notification::assertNothingSent();
    }

    public function test_cancel_events_wait_for_commit_and_rollback_restores_queued_state(): void
    {
        $this->addObservers();
        $email = $this->accept();
        Queue::fake();
        DB::beginTransaction();
        try {
            $response = app(EmailController::class)->cancel($email->id, app(DispatchStateMachine::class));
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('FAILED', $email->dispatches->sole()->refresh()->status->value);
            Queue::assertNothingPushed();
            $this->assertSame(0, DB::table('email_events')->where('type', 'failed')->count());
        } finally {
            DB::rollBack();
        }
        $this->assertNull($email->refresh()->canceled_at);
        $this->assertSame(DispatchStatus::QUEUED, $email->dispatches->sole()->refresh()->status);
        Queue::assertNothingPushed();
        DB::transaction(fn () => app(EmailController::class)->cancel($email->id, app(DispatchStateMachine::class)));
        Queue::assertPushed(DeliverWebhookJob::class, 1);
        $this->assertSame(1, DB::table('email_events')->where('type', 'failed')->count());
    }

    public function test_synchronous_worker_invoked_inside_outer_transaction_waits_until_commit(): void
    {
        $email = $this->accept();
        $dispatch = $email->dispatches->sole();
        $this->enableFakeRelay();
        DB::beginTransaction();
        try {
            $this->work($dispatch);
            $this->assertSame(DispatchStatus::QUEUED, $dispatch->refresh()->status);
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        $this->assertSame(DispatchStatus::SENT, $dispatch->refresh()->status);
    }
}
