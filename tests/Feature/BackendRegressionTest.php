<?php

namespace Tests\Feature;

use App\Models\Email;
use App\Models\EmailDispatch;
use App\Models\Organization;
use App\Services\ApiKeyService;
use App\Services\DnsAuditorService;
use App\Services\DomainService;
use App\Services\TrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Regresi bug backend: validasi lampiran, batas kolom MySQL, dan tracking opt-out. */
class BackendRegressionTest extends TestCase
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

    public function test_invalid_base64_attachment_is_rejected(): void
    {
        $this->send(['attachments' => [['filename' => 'a.txt', 'content' => '!!!not-base64!!!']]])
            ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

        $this->assertSame(0, Email::withoutGlobalScopes()->count());
    }

    public function test_valid_base64_attachment_is_stored(): void
    {
        $this->send(['attachments' => [['filename' => 'a.txt', 'content' => base64_encode('hello'), 'content_type' => 'text/plain']]])
            ->assertStatus(202);

        $email = Email::withoutGlobalScopes()->latest('id')->firstOrFail();
        $row = \Illuminate\Support\Facades\DB::table('email_attachments')->where('email_id', $email->id)->first();
        $this->assertSame(5, (int) $row->size_bytes);
        $this->assertSame('hello', Storage::disk('local')->get($row->storage_path));
    }

    public function test_long_from_name_is_truncated_to_column_width(): void
    {
        $this->send(['from' => str_repeat('N', 150).' <noreply@mail.t.test>'])->assertStatus(202);

        $email = Email::withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertLessThanOrEqual(100, mb_strlen((string) $email->from_name));
    }

    public function test_long_org_name_slug_fits_column(): void
    {
        $this->post('/register', [
            'name' => 'User', 'organization' => str_repeat('OrganisasiPanjang', 7),
            'email' => 'u@example.com', 'password' => 'password1234', 'password_confirmation' => 'password1234',
        ]);

        $org = Organization::where('name', 'like', 'Organisasi%')->firstOrFail();
        $this->assertLessThanOrEqual(80, strlen($org->slug));
    }

    public function test_link_tracking_respects_opt_out_and_length_limit(): void
    {
        $this->send(['track_clicks' => true])->assertStatus(202);
        $email = Email::withoutGlobalScopes()->latest('id')->firstOrFail();
        $dispatch = EmailDispatch::withoutGlobalScopes()->where('email_id', $email->id)->firstOrFail();
        $domain = \App\Models\Domain::withoutGlobalScopes()->findOrFail($email->domain_id);

        $long = 'https://example.com/'.str_repeat('a', 2100);
        $html = '<a href="https://example.com/ok">ok</a>'
            .'<a href="https://example.com/no" data-postivo-notrack>no</a>'
            .'<a href="'.$long.'">long</a>';

        $out = app(TrackingService::class)->apply($html, $email, $dispatch, $domain);

        $this->assertSame(1, substr_count($out, '/c/'));
        $this->assertStringContainsString('href="https://example.com/no"', $out);
        $this->assertStringContainsString('href="'.$long.'"', $out);
    }

    public function test_suspended_organization_does_not_send_queued_email(): void
    {
        \Illuminate\Support\Facades\Queue::fake([\App\Jobs\DispatchOutboundEmailJob::class]);

        $res = $this->send()->assertStatus(202);
        $dispatch = EmailDispatch::withoutGlobalScopes()->findOrFail($res->json('recipients.0.dispatch_id'));

        $this->org->update(['status' => 'SUSPENDED']);
        app()->call([new \App\Jobs\DispatchOutboundEmailJob($dispatch->id), 'handle']);

        $dispatch->refresh();
        $this->assertSame(\App\Enums\DispatchStatus::FAILED, $dispatch->status);
        $this->assertSame('organization_suspended', $dispatch->events()->where('type', 'failed')->first()?->data['reason'] ?? null);
    }

    public function test_requeue_stuck_dispatches_skips_scheduled_emails(): void
    {
        \Illuminate\Support\Facades\Queue::fake([\App\Jobs\DispatchOutboundEmailJob::class]);

        $now = EmailDispatch::withoutGlobalScopes()->findOrFail($this->send()->assertStatus(202)->json('recipients.0.dispatch_id'));
        $later = EmailDispatch::withoutGlobalScopes()->findOrFail(
            $this->send(['scheduled_at' => now()->addDay()->toIso8601String()])->assertStatus(202)->json('recipients.0.dispatch_id')
        );
        // Simulasi worker mati setelah klaim (SENDING) dan job terjadwal yang belum waktunya.
        EmailDispatch::withoutGlobalScopes()->whereKey($now->id)->update(['status' => 'SENDING', 'status_rank' => 1, 'updated_at' => now()->subHour()]);
        EmailDispatch::withoutGlobalScopes()->whereKey($later->id)->update(['updated_at' => now()->subHour()]);

        $this->artisan('postivo:requeue-stuck')->assertSuccessful();

        $this->assertSame(\App\Enums\DispatchStatus::QUEUED, $now->refresh()->status);
        $pushed = \Illuminate\Support\Facades\Queue::pushed(\App\Jobs\DispatchOutboundEmailJob::class);
        $this->assertSame(2, $pushed->filter(fn ($j) => $j->dispatchId === $now->id)->count());
        $this->assertSame(1, $pushed->filter(fn ($j) => $j->dispatchId === $later->id)->count());
    }
}
