<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Menelusuri semua rute GET tanpa parameter sebagai tamu, owner, dan superadmin: tidak boleh ada 5xx. */
class SmokeCrawlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['postivo.dns.assume_ok' => true, 'postivo.admin.require_2fa' => false, 'postivo.relay.driver' => 'log']);
        $this->seed(DatabaseSeeder::class);
    }

    private function paths(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => in_array('GET', $r->methods(), true) && ! str_contains($r->uri(), '{') && ! str_starts_with($r->uri(), 'up') && ! str_starts_with($r->uri(), 'storage')
                && ! str_starts_with($r->uri(), '_') && ! str_starts_with($r->uri(), 'internal') && $r->uri() !== 'logout')
            ->map(fn ($r) => '/'.ltrim($r->uri(), '/'))->unique()->values()->all();
    }

    private function crawl(string $as): void
    {
        $bad = [];
        foreach ($this->paths() as $p) {
            $res = $this->get($p);
            if ($res->getStatusCode() >= 500) {
                $bad[] = "$as $p → ".$res->getStatusCode();
            }
        }
        $this->assertSame([], $bad, implode("\n", $bad));
    }

    public function test_guest_pages_have_no_server_errors(): void
    {
        $this->crawl('guest');
        $this->get('/logout')->assertRedirect('/login');
    }

    public function test_owner_pages_have_no_server_errors(): void
    {
        $this->actingAs(User::where('email', 'demo@postivo.test')->firstOrFail());
        $this->crawl('owner');
        $this->get('/logout')->assertRedirect('/console');
    }

    public function test_detail_pages_with_real_records_render(): void
    {
        $org = \App\Models\Organization::where('slug', 'demo-org')->firstOrFail();
        $owner = User::where('email', 'demo@postivo.test')->firstOrFail();
        [, $key] = app(\App\Services\ApiKeyService::class)->create($org, 'smoke', 'live', ['emails:send', 'emails:read']);
        $res = $this->withToken($key)->postJson('/v1/emails', ['from' => 'a@mail.demo.test', 'to' => ['x@example.com'], 'subject' => 'Smoke', 'html' => '<p>hi</p>', 'text' => 'hi', 'track_clicks' => true])->assertStatus(202);
        $dispatchId = $res->json('recipients.0.dispatch_id');
        $order = app(\App\Services\BillingService::class)->checkout($org, $owner, 'starter');
        $domain = $org->domains()->firstOrFail();

        $this->app['auth']->forgetGuards();
        $this->actingAs($owner);
        foreach (["/console/emails/$dispatchId", "/console/domains/{$domain->id}", "/console/billing/orders/{$order->id}", '/console/billing/checkout/starter'] as $p) {
            $this->get($p)->assertOk();
        }
        $this->actingAs(User::where('email', 'superadmin@postivo.test')->firstOrFail());
        foreach (["/admin/emails/$dispatchId", "/admin/organizations/{$org->id}", "/admin/users/{$owner->id}", "/admin/billing/orders/{$order->id}"] as $p) {
            $this->get($p)->assertOk();
        }
    }

    public function test_expired_csrf_redirects_to_login_instead_of_419(): void
    {
        $this->withMiddleware(); // aktifkan verifikasi CSRF (dinonaktifkan di environment testing)
        $this->post('/logout')->assertRedirect('/login');
    }

    public function test_superadmin_pages_have_no_server_errors(): void
    {
        $this->actingAs(User::where('email', 'superadmin@postivo.test')->firstOrFail());
        $this->crawl('superadmin');
    }
}
