<?php

namespace Tests\Feature;

use App\Enums\DispatchStatus;
use App\Models\EmailDispatch;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Suppression;
use App\Models\User;
use App\Services\ApiKeyService;
use App\Services\DispatchStateMachine;
use App\Services\DnsAuditorService;
use App\Services\DomainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IngestApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['postivo.relay.driver' => 'log', 'postivo.dns.assume_ok' => true, 'postivo.internal_secret' => 'test-secret']);

        $this->org = Organization::create(['name' => 'T', 'slug' => 't']);
        $domain = app(DomainService::class)->create($this->org, 'mail.t.test');
        app(DnsAuditorService::class)->audit($domain->load('records'));
        [, $this->key] = app(ApiKeyService::class)->create($this->org, 'k', 'live', ApiKeyService::ALL_SCOPES);
    }

    private function send(array $over = [], array $headers = [])
    {
        return $this->withToken($this->key)->withHeaders($headers)->postJson('/v1/emails', $over + [
            'from' => 'T <noreply@mail.t.test>', 'to' => ['a@example.com'], 'subject' => 'Hi', 'text' => 'x',
        ]);
    }

    public function test_requires_api_key(): void
    {
        $this->postJson('/v1/emails', [])->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_send_is_accepted_signed_and_delivered(): void
    {
        $res = $this->send()->assertStatus(202)->assertJsonPath('recipients.0.status', 'QUEUED');
        $d = EmailDispatch::withoutGlobalScopes()->findOrFail($res->json('recipients.0.dispatch_id'));

        $this->assertSame(DispatchStatus::DELIVERED, $d->status);
        $this->assertStringContainsString('DKIM-Signature:', Storage::disk('local')->get("mail/outbox/{$d->id}.eml"));
    }

    public function test_idempotency_replay_and_conflict(): void
    {
        $first = $this->send([], ['Idempotency-Key' => 'k1'])->assertStatus(202);
        $this->send([], ['Idempotency-Key' => 'k1'])->assertStatus(200)->assertJsonPath('id', $first->json('id'));
        $this->send(['subject' => 'Other'], ['Idempotency-Key' => 'k1'])->assertStatus(409);
        $this->assertSame(1, EmailDispatch::withoutGlobalScopes()->count());
    }

    public function test_unverified_domain_is_rejected(): void
    {
        $this->send(['from' => 'x@other.test'])->assertStatus(422)->assertJsonPath('error.code', 'domain_not_verified');
    }

    public function test_suppressed_recipients(): void
    {
        Suppression::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'email' => 'bad@example.com', 'email_hash' => hash('sha256', 'bad@example.com'), 'reason' => 'MANUAL', 'created_at' => now()]);

        $this->send(['to' => ['bad@example.com']])->assertStatus(422)->assertJsonPath('error.code', 'recipient_suppressed');
        $res = $this->send(['to' => ['bad@example.com', 'ok@example.com']])->assertStatus(202);
        $byEmail = collect($res->json('recipients'))->pluck('status', 'email');
        $this->assertSame('SUPPRESSED', $byEmail['bad@example.com']);
        $this->assertSame('QUEUED', $byEmail['ok@example.com']);
    }

    public function test_late_event_never_regresses_status(): void
    {
        $this->send()->assertStatus(202);
        $d = EmailDispatch::withoutGlobalScopes()->firstOrFail();
        $machine = app(DispatchStateMachine::class);

        $machine->transition($d, DispatchStatus::BOUNCED, 'bounced');
        $this->assertFalse($machine->transition($d->refresh(), DispatchStatus::DELIVERED, 'delivered'));
        $this->assertSame(DispatchStatus::BOUNCED, $d->refresh()->status);
    }

    public function test_hard_bounce_feedback_suppresses_recipient_and_requires_signature(): void
    {
        $this->send()->assertStatus(202);
        $d = EmailDispatch::withoutGlobalScopes()->firstOrFail();
        $payload = ['type' => 'bounced', 'dispatch_id' => $d->id, 'code' => 550, 'response' => '550 5.1.1 unknown user'];

        $this->postJson('/internal/relay/events/stalwart', $payload)->assertStatus(401);

        $ts = time();
        $sig = hash_hmac('sha256', $ts.'.'.json_encode($payload), 'test-secret');
        $this->call('POST', '/internal/relay/events/stalwart', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_POSTIVO_TIMESTAMP' => $ts, 'HTTP_X_POSTIVO_SIGNATURE' => $sig,
        ], json_encode($payload))->assertOk();

        $this->assertSame('BOUNCED', $d->refresh()->status->value);
        $this->assertTrue(Suppression::withoutGlobalScopes()->where('email', 'a@example.com')->exists());
    }

    public function test_console_requires_login_and_scopes_to_own_org(): void
    {
        $this->get('/console')->assertRedirect('/login');

        $user = User::create(['name' => 'U', 'email' => 'u@t.test', 'password' => 'password-123456']);
        Member::create(['organization_id' => $this->org->id, 'user_id' => $user->id, 'role' => 'owner']);
        $this->actingAs($user)->get('/console/domains')->assertOk()->assertSee('mail.t.test');

        $other = Organization::create(['name' => 'O', 'slug' => 'o']);
        $ou = User::create(['name' => 'O', 'email' => 'o@t.test', 'password' => 'password-123456']);
        Member::create(['organization_id' => $other->id, 'user_id' => $ou->id, 'role' => 'owner']);
        $this->actingAs($ou)->get('/console/domains')->assertOk()->assertDontSee('mail.t.test');
    }
}
