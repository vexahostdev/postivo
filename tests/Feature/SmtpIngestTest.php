<?php

namespace Tests\Feature;

use App\Models\EmailDispatch;
use App\Models\Organization;
use App\Services\ApiKeyService;
use App\Services\DnsAuditorService;
use App\Services\DomainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SmtpIngestTest extends TestCase
{
    use RefreshDatabase;

    private string $spool;

    private string $keyId;

    private string $plain;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->spool = storage_path('framework/testing/spool');
        File::ensureDirectoryExists($this->spool);
        config(['postivo.relay.driver' => 'log', 'postivo.dns.assume_ok' => true, 'postivo.internal_secret' => 's', 'postivo.smtp.spool' => $this->spool]);

        $org = Organization::create(['name' => 'T', 'slug' => 't']);
        app(DnsAuditorService::class)->audit(app(DomainService::class)->create($org, 'mail.t.test')->load('records'));
        [$key, $this->plain] = app(ApiKeyService::class)->create($org, 'k', 'live', ['emails:send']);
        $this->keyId = $key->id;
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->spool);
        parent::tearDown();
    }

    private function signed(string $uri, array $payload)
    {
        $body = json_encode($payload);
        $ts = time();

        return $this->call('POST', $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_POSTIVO_TIMESTAMP' => $ts,
            'HTTP_X_POSTIVO_SIGNATURE' => hash_hmac('sha256', $ts.'.'.$body, 's'),
        ], $body);
    }

    private function spoolFile(string $from = 'a@mail.t.test'): string
    {
        $f = $this->spool.'/m.eml';
        file_put_contents($f, "From: A <$from>\r\nTo: x@example.com\r\nSubject: Hi\r\nMIME-Version: 1.0\r\nContent-Type: text/plain\r\n\r\nhello\r\n");

        return $f;
    }

    public function test_auth_endpoint(): void
    {
        $this->signed('/internal/smtp/auth', ['password' => $this->plain])->assertOk()->assertJsonPath('ok', true);
        $this->signed('/internal/smtp/auth', ['password' => 'nope'])->assertStatus(401);
        $this->postJson('/internal/smtp/auth', ['password' => $this->plain])->assertStatus(401); // tanpa HMAC
    }

    public function test_ingest_parses_mime_and_treats_unlisted_rcpt_as_bcc(): void
    {
        $f = $this->spoolFile();
        $this->signed('/internal/smtp/ingest', [
            'ingest_id' => 'i', 'api_key_id' => $this->keyId, 'spool_path' => $f, 'rcpt_to' => ['x@example.com', 'secret@example.com'],
        ])->assertStatus(202);

        $types = EmailDispatch::withoutGlobalScopes()->pluck('recipient_type', 'recipient');
        $this->assertSame('to', $types['x@example.com']);
        $this->assertSame('bcc', $types['secret@example.com']);
        $this->assertFileDoesNotExist($f);
    }

    public function test_ingest_rejects_path_outside_spool_and_unverified_domain(): void
    {
        $this->signed('/internal/smtp/ingest', ['ingest_id' => 'i', 'api_key_id' => $this->keyId, 'spool_path' => base_path('composer.json'), 'rcpt_to' => ['x@example.com']])->assertStatus(403);

        $f = $this->spoolFile('a@evil.test');
        $this->signed('/internal/smtp/ingest', ['ingest_id' => 'i', 'api_key_id' => $this->keyId, 'spool_path' => $f, 'rcpt_to' => ['x@example.com']])
            ->assertStatus(422)->assertJsonPath('error.code', 'domain_not_verified');
    }
}
