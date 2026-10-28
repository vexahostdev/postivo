<?php

namespace Tests\Feature;

use App\Enums\MemberRole;
use App\Jobs\DeliverWebhookJob;
use App\Models\Domain;
use App\Models\Email;
use App\Models\EmailDispatch;
use App\Models\EmailEvent;
use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Notifications\DomainFailedNotification;
use App\Notifications\DomainVerifiedNotification;
use App\Services\DnsAuditorService;
use App\Services\WebhookDispatcher;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class LifecycleSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        config([
            'postivo.admin.require_2fa' => false,
            'postivo.dns.assume_ok' => false,
            'postivo.dns.doh_primary' => 'https://doh.lifecycle.test/query',
            'postivo.dns.doh_fallback' => 'https://fallback.lifecycle.test/query',
            'postivo.webhook.timeout' => 2,
            'postivo.webhook.retry_backoff' => [60, 300],
            'postivo.webhook.disable_after' => 10,
        ]);
        Http::preventStrayRequests();
        Queue::fake();
        Notification::fake();
        $this->org = Organization::create(['name' => 'Lifecycle', 'slug' => 'lifecycle']);
        $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@lifecycle.test', 'password' => 'test-password-123']);
        Member::create(['organization_id' => $this->org->id, 'user_id' => $this->owner->id, 'role' => MemberRole::OWNER]);
    }

    public function test_successful_dns_audit_preserves_suspension_and_updates_audit_metadata(): void
    {
        $domain = $this->domain('SUSPENDED');
        $this->fakeDns($domain);

        $audited = app(DnsAuditorService::class)->audit($domain);

        $this->assertSame('SUSPENDED', $audited->status);
        $this->assertSame('SUSPENDED', $domain->fresh()->status);
        $this->assertTrue($audited->last_audit_at->eq(now()));
        $this->assertTrue($audited->verified_at->eq(now()));
        $this->assertSame('track.'.$domain->domain_name, $audited->tracking_host);
        foreach ($audited->records as $record) {
            $this->assertTrue($record->is_verified);
            $this->assertSame($record->value, $record->last_seen_value);
            $this->assertTrue($record->last_checked_at->eq(now()));
        }
        Http::assertSentCount(2);
        Notification::assertNothingSent();
    }

    public function test_stale_active_instance_cannot_overwrite_current_suspension(): void
    {
        $domain = $this->domain('ACTIVE');
        $verifiedAt = now()->subMonth();
        Domain::withoutGlobalScope('organization')->whereKey($domain->id)->update([
            'status' => 'SUSPENDED', 'verified_at' => $verifiedAt,
        ]);
        $this->app->instance('org.current', Organization::create(['name' => 'Other', 'slug' => 'other']));
        $this->fakeDns($domain);

        $audited = app(DnsAuditorService::class)->audit($domain);

        $this->assertSame('SUSPENDED', $audited->status);
        $this->assertTrue($audited->verified_at->eq($verifiedAt));
        $this->assertDatabaseHas('domains', ['id' => $domain->id, 'status' => 'SUSPENDED']);
        Notification::assertNothingSent();
    }

    public function test_suspension_during_dns_lookup_wins_over_successful_audit(): void
    {
        $domain = $this->domain('ACTIVE');
        $transactionLevel = DB::transactionLevel();
        $suspended = false;
        $this->fakeDns($domain, function () use ($domain, $transactionLevel, &$suspended) {
            // Deterministic interleaving: emulate an admin write while DNS is in flight.
            $this->assertSame($transactionLevel, DB::transactionLevel(), 'DNS must run before the auditor transaction.');
            Domain::withoutGlobalScope('organization')->whereKey($domain->id)->update(['status' => 'SUSPENDED']);
            $suspended = true;
        });

        $audited = app(DnsAuditorService::class)->audit($domain);

        $this->assertTrue($suspended);
        $this->assertSame('SUSPENDED', $audited->status);
        $this->assertSame('SUSPENDED', $domain->fresh()->status);
        $this->assertTrue($audited->records->every->is_verified);
        Notification::assertNothingSent();
    }

    public function test_failed_dns_cannot_change_an_expired_suspended_domain_to_failed(): void
    {
        $domain = $this->domain('SUSPENDED');
        $domain->update(['audit_started_at' => now()->subDays(8)]);
        $this->fakeDns($domain, successful: false);

        $audited = app(DnsAuditorService::class)->audit($domain);

        $this->assertSame('SUSPENDED', $audited->status);
        $this->assertTrue($audited->last_audit_at->eq(now()));
        $this->assertNull($audited->verified_at);
        $this->assertNull($audited->tracking_host);
        $this->assertFalse($audited->records->first()->is_verified);
        Notification::assertNothingSent();
    }

    public function test_assume_ok_mode_also_preserves_suspension(): void
    {
        config(['postivo.dns.assume_ok' => true]);
        Http::fake();
        $domain = $this->domain('SUSPENDED');

        $audited = app(DnsAuditorService::class)->audit($domain);

        $this->assertSame('SUSPENDED', $audited->status);
        $this->assertTrue($audited->records->every->is_verified);
        $this->assertNull($audited->tracking_host);
        Http::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_domain_deleted_during_dns_lookup_is_not_persisted_or_resurrected(): void
    {
        $domain = $this->domain();
        $this->fakeDns($domain, function () use ($domain) {
            Domain::withoutGlobalScope('organization')->findOrFail($domain->id)->delete();
        });

        try {
            app(DnsAuditorService::class)->audit($domain);
            $this->fail('Auditing a deleted domain must fail closed.');
        } catch (ModelNotFoundException $e) {
            $this->assertSame(Domain::class, $e->getModel());
        }

        $this->assertSoftDeleted('domains', ['id' => $domain->id]);
        $this->assertDatabaseHas('domains', ['id' => $domain->id, 'status' => 'PENDING', 'last_audit_at' => null, 'verified_at' => null]);
        $this->assertSame(0, $domain->records()->whereNotNull('last_checked_at')->count());
        Notification::assertNothingSent();
    }

    public function test_scheduled_audit_skips_deleted_domains_and_continues_after_mid_audit_deletion(): void
    {
        config(['postivo.dns.assume_ok' => true]);
        $deleted = $this->domain();
        $deleted->delete();
        $removedDuringAudit = $this->domain();
        $live = $this->domain();
        $auditor = Mockery::mock(DnsAuditorService::class)->makePartial();
        $auditor->shouldReceive('audit')->once()->withArgs(fn ($domain) => $domain->id === $removedDuringAudit->id)
            ->andReturnUsing(function ($domain) {
                $domain->delete();
                throw (new ModelNotFoundException)->setModel(Domain::class, [$domain->id]);
            });
        $auditor->shouldReceive('audit')->once()->withArgs(fn ($domain) => $domain->id === $live->id)->passthru();
        $this->app->instance(DnsAuditorService::class, $auditor);

        $this->artisan('postivo:audit-domains')->expectsOutput('Audited 1 domain(s).')->assertSuccessful();

        $this->assertSame('ACTIVE', $live->fresh()->status);
        $this->assertSoftDeleted('domains', ['id' => $deleted->id]);
        $this->assertSoftDeleted('domains', ['id' => $removedDuringAudit->id]);
        Http::assertNothingSent();
    }

    public function test_authorized_admin_can_explicitly_unsuspend_then_verify(): void
    {
        $domain = $this->domain('SUSPENDED');
        $admin = User::create(['name' => 'Staff', 'email' => 'staff@lifecycle.test', 'password' => 'test-password-123']);
        $admin->forceFill(['platform_role' => 'superadmin'])->save();
        $this->fakeDns($domain);

        $this->actingAs($admin)->post("/admin/domains/{$domain->id}/suspend", ['reason' => 'Reviewed and approved'])
            ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('status');

        $this->assertSame('ACTIVE', $domain->fresh()->status);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'domain.unsuspend', 'admin_id' => $admin->id]);
        Notification::assertSentTo($this->owner, DomainVerifiedNotification::class);
        Notification::assertNotSentTo($this->owner, DomainFailedNotification::class);
    }

    public function test_failure_notification_uses_current_status_and_waits_for_outer_commit(): void
    {
        $domain = $this->domain();
        $this->fakeDns($domain, function () use ($domain) {
            Domain::withoutGlobalScope('organization')->whereKey($domain->id)->update(['status' => 'ACTIVE']);
        }, successful: false);

        DB::transaction(function () use ($domain) {
            $audited = app(DnsAuditorService::class)->audit($domain);
            $this->assertSame('FAILED', $audited->status);
            Notification::assertNothingSent();
        });

        Notification::assertSentTo($this->owner, DomainFailedNotification::class, function ($notification) use ($domain) {
            return $notification->domain->id === $domain->id && $notification->domain->status === 'FAILED';
        });
        Notification::assertNotSentTo($this->owner, DomainVerifiedNotification::class);
    }

    public function test_stale_pending_instance_does_not_emit_duplicate_verified_notification(): void
    {
        $domain = $this->domain();
        Domain::withoutGlobalScope('organization')->whereKey($domain->id)->update(['status' => 'ACTIVE']);
        $this->fakeDns($domain);

        $this->assertSame('ACTIVE', app(DnsAuditorService::class)->audit($domain)->status);

        Notification::assertNothingSent();
    }

    public function test_rolled_back_audit_does_not_send_notification_or_keep_record_changes(): void
    {
        $domain = $this->domain();
        $this->fakeDns($domain);

        try {
            DB::transaction(function () use ($domain) {
                $this->assertSame('ACTIVE', app(DnsAuditorService::class)->audit($domain)->status);
                Notification::assertNothingSent();
                throw new RuntimeException('rollback audit');
            });
        } catch (RuntimeException $e) {
            $this->assertSame('rollback audit', $e->getMessage());
        }

        $this->assertSame('PENDING', $domain->fresh()->status);
        $this->assertNull($domain->fresh()->last_audit_at);
        $this->assertSame(0, $domain->records()->where('is_verified', true)->count());
        Notification::assertNothingSent();
    }

    public function test_deleted_endpoint_gets_no_new_fanout_deliveries_or_jobs(): void
    {
        Http::fake();
        $event = $this->event();
        $endpoint = $this->endpoint();
        $endpoint->delete();
        $otherOrg = Organization::create(['name' => 'Other', 'slug' => 'other']);
        $this->endpoint($otherOrg);

        app(WebhookDispatcher::class)->fanOut($event);

        $this->assertDatabaseCount('webhook_deliveries', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_pending_retry_for_deleted_endpoint_does_no_dns_http_or_rescheduling(): void
    {
        $event = $this->event();
        $endpoint = $this->endpoint();
        $delivery = $this->delivery($endpoint, $event);
        $delivery->update(['attempt' => 1, 'next_attempt_at' => now()->subMinute()]);
        $endpoint->delete();

        $this->assertDeliverySkipped($delivery);
    }

    public function test_cross_organization_delivery_is_rejected_before_dns_or_http(): void
    {
        $event = $this->event();
        $otherOrg = Organization::create(['name' => 'Other', 'slug' => 'other']);
        $endpoint = $this->endpoint($otherOrg);

        $this->assertDeliverySkipped($this->delivery($endpoint, $event));
    }

    public function test_active_same_tenant_endpoint_still_fans_out_and_delivers_signed_payload(): void
    {
        $event = $this->event();
        $endpoint = $this->endpoint();
        $otherOrg = Organization::create(['name' => 'Other', 'slug' => 'other']);
        $this->endpoint($otherOrg);
        $this->app->instance('org.current', $otherOrg);
        Http::fake([$endpoint->url => Http::response('', 204)]);
        $dispatcher = Mockery::mock(WebhookDispatcher::class)->makePartial();
        $dispatcher->shouldReceive('resolveSafeIp')->once()->with($endpoint->url)->andReturn('93.184.216.34');

        DB::transaction(fn () => $dispatcher->fanOut($event));

        $delivery = WebhookDelivery::sole();
        $this->assertSame($endpoint->id, $delivery->endpoint_id);
        Queue::assertPushed(DeliverWebhookJob::class, 1);
        Queue::assertPushed(DeliverWebhookJob::class, fn ($job) => $job->deliveryId === $delivery->id
            && $job->queue === 'postivo_webhooks' && $job->afterCommit === true);
        (new DeliverWebhookJob($delivery->id))->handle($dispatcher);

        $this->assertSame('SUCCESS', $delivery->fresh()->status);
        $this->assertSame(1, $delivery->fresh()->attempt);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($endpoint, $event, $dispatcher) {
            $signature = $request->header('Postivo-Signature')[0] ?? '';
            $this->assertSame(1, preg_match('/^t=(\d+),v1=[a-f0-9]{64}$/', $signature, $parts));
            $this->assertSame($dispatcher->sign($endpoint->secret, (int) $parts[1], $request->body()), $signature);

            return $request->url() === $endpoint->url && $request->method() === 'POST'
                && $request->hasHeader('Postivo-Event-Id', $event->public_id)
                && $request['id'] === 'evt_'.$event->public_id
                && $request['type'] === 'email.delivered'
                && $request['data']['recipient'] === 'recipient@lifecycle.test';
        });
    }

    public function test_webhook_retries_also_explicitly_dispatch_after_commit(): void
    {
        $event = $this->event();
        $endpoint = $this->endpoint();
        $delivery = $this->delivery($endpoint, $event);
        Http::fake([$endpoint->url => Http::response('retry', 503)]);
        $dispatcher = Mockery::mock(WebhookDispatcher::class)->makePartial();
        $dispatcher->shouldReceive('resolveSafeIp')->once()->andReturn('93.184.216.34');

        (new DeliverWebhookJob($delivery->id))->handle($dispatcher);

        $this->assertSame('PENDING', $delivery->fresh()->status);
        $this->assertSame(1, $delivery->fresh()->attempt);
        Queue::assertPushed(DeliverWebhookJob::class, 1);
        Queue::assertPushed(DeliverWebhookJob::class, fn ($job) => $job->deliveryId === $delivery->id
            && $job->afterCommit === true && $job->delay === 60);
    }

    public function test_fanout_waits_for_outer_commit_and_discards_rolled_back_jobs(): void
    {
        // QueueFake captures flags, not commit timing. Except this job to the local DB queue;
        // inspect its rows only, without running a worker or contacting a queue service.
        config([
            'queue.default' => 'lifecycle',
            'queue.connections.lifecycle' => [
                'driver' => 'database', 'connection' => null, 'table' => 'jobs',
                'queue' => 'postivo_webhooks', 'retry_after' => 90, 'after_commit' => false,
            ],
        ]);
        Queue::fake()->except([DeliverWebhookJob::class]);
        Http::fake();
        $event = $this->event();
        $this->endpoint();
        $dispatcher = app(WebhookDispatcher::class);

        DB::transaction(function () use ($event, $dispatcher) {
            DB::transaction(fn () => $dispatcher->fanOut($event));
            $this->assertDatabaseCount('jobs', 0);
        });
        $this->assertDatabaseCount('jobs', 1);

        try {
            DB::transaction(function () use ($event, $dispatcher) {
                $dispatcher->fanOut($event);
                $this->assertDatabaseCount('jobs', 1);
                throw new RuntimeException('rollback fanout');
            });
        } catch (RuntimeException $e) {
            $this->assertSame('rollback fanout', $e->getMessage());
        }

        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('webhook_deliveries', 1);
        Http::assertNothingSent();
    }

    private function domain(string $status = 'PENDING'): Domain
    {
        $name = 'mail.'.strtolower(Str::random(10)).'.test';
        $domain = Domain::create([
            'organization_id' => $this->org->id, 'domain_name' => $name, 'status' => $status,
            'dkim_private_key' => 'dummy-private-key', 'dkim_public_key' => 'dummy-public-key',
            'return_path_host' => 'bounce.'.$name, 'audit_started_at' => now(),
        ]);
        $domain->records()->createMany([
            ['purpose' => 'verification', 'type' => 'TXT', 'name' => '_verify.'.$name, 'value' => 'dummy-verification', 'is_required' => true],
            ['purpose' => 'tracking', 'type' => 'CNAME', 'name' => 'track.'.$name, 'value' => 'tracking.lifecycle.test', 'is_required' => false],
        ]);

        return $domain->load('records');
    }

    private function fakeDns(Domain $domain, ?Closure $duringLookup = null, bool $successful = true): void
    {
        $records = $domain->records->keyBy(fn ($record) => $record->type.'|'.$record->name);
        $interleaved = false;
        Http::fake(function (Request $request) use ($records, $duringLookup, $successful, &$interleaved) {
            if (! $interleaved && $duringLookup) {
                $interleaved = true;
                $duringLookup();
            }
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
            $record = $records->get(($query['type'] ?? '').'|'.($query['name'] ?? ''));
            $this->assertNotNull($record, 'Only fixture DNS queries are expected.');

            return Http::response($successful
                ? ['Status' => 0, 'Answer' => [['data' => $record->value]]]
                : ['Status' => 3]);
        });
    }

    private function event(): EmailEvent
    {
        $domain = $this->domain('ACTIVE');
        $email = Email::create([
            'organization_id' => $this->org->id, 'domain_id' => $domain->id,
            'source' => 'API', 'from_address' => 'sender@'.$domain->domain_name, 'subject' => 'Lifecycle test',
        ]);
        $dispatch = EmailDispatch::create([
            'organization_id' => $this->org->id, 'domain_id' => $domain->id, 'email_id' => $email->id,
            'recipient' => 'recipient@lifecycle.test', 'recipient_hash' => hash('sha256', 'recipient@lifecycle.test'),
            'open_token' => Str::random(22), 'unsubscribe_token' => Str::random(22),
        ]);

        return EmailEvent::create([
            'public_id' => (string) Str::uuid(), 'organization_id' => $this->org->id,
            'email_id' => $email->id, 'dispatch_id' => $dispatch->id, 'type' => 'delivered',
            'occurred_at' => now(), 'created_at' => now(),
        ]);
    }

    private function endpoint(?Organization $org = null): WebhookEndpoint
    {
        return WebhookEndpoint::create([
            'organization_id' => ($org ?? $this->org)->id, 'url' => 'https://webhook.lifecycle.test/events',
            'secret' => 'dummy-webhook-secret', 'events' => ['email.delivered'], 'is_active' => true,
        ]);
    }

    private function delivery(WebhookEndpoint $endpoint, EmailEvent $event): WebhookDelivery
    {
        return WebhookDelivery::create([
            'endpoint_id' => $endpoint->id, 'event_id' => $event->id,
            'event_public_id' => $event->public_id, 'status' => 'PENDING',
        ])->refresh();
    }

    private function assertDeliverySkipped(WebhookDelivery $delivery): void
    {
        Http::fake();
        $before = $delivery->getAttributes();
        $dispatcher = Mockery::mock(WebhookDispatcher::class)->makePartial();
        $dispatcher->shouldNotReceive('payload');
        $dispatcher->shouldNotReceive('resolveSafeIp');

        (new DeliverWebhookJob($delivery->id))->handle($dispatcher);

        $this->assertSame($before, $delivery->fresh()->getAttributes());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }
}
