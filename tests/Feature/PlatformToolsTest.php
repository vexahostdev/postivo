<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\User;
use App\Services\PlatformSettings;
use App\Support\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PlatformToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['postivo.admin.require_2fa' => false]);
    }

    private function staff(string $role = 'superadmin'): User
    {
        $u = User::create(['name' => ucfirst($role), 'email' => "$role@p.test", 'password' => 'password-123456']);
        $u->forceFill(['platform_role' => $role])->save();

        return $u;
    }

    public function test_health_endpoints(): void
    {
        $this->getJson('/healthz')->assertOk()->assertJson(['status' => 'ok']);
        $this->getJson('/readyz')->assertOk()->assertJsonPath('checks.database', true);
    }

    public function test_security_headers_are_present(): void
    {
        $this->get('/login')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Content-Security-Policy-Report-Only');
    }

    public function test_legal_pages_render_and_are_linked(): void
    {
        foreach (['/terms', '/privacy', '/aup'] as $url) {
            $this->get($url)->assertOk()->assertSee('postivo. All rights reserved', false);
        }
        $this->get('/register')->assertSee(route('legal.terms'), false);
    }

    public function test_branding_defaults_and_overrides_apply_sitewide(): void
    {
        $this->assertSame('Postivo', Brand::name());
        $this->assertStringContainsString('Created by postivo', Brand::footer());

        Storage::fake('public');
        $admin = $this->staff();
        $this->actingAs($admin)->get('/admin/branding')->assertOk();

        $this->actingAs($admin)->post('/admin/branding', [
            'brand_name' => 'Kirimin', 'brand_footer' => '© Kirimin 2026', 'brand_accent' => '#1d4ed8',
            'logo' => UploadedFile::fake()->image('l.png', 200, 60), 'reason' => 'Rebrand uji',
        ])->assertSessionHasNoErrors();
        PlatformSettings::set('x', 1); // bersihkan cache pengaturan

        $this->assertSame('Kirimin', Brand::name());
        $this->assertSame('#1D4ED8', Brand::accent());
        $this->assertStringContainsString('brand/logo-', Brand::logo());
        $this->get('/terms')->assertSee('© Kirimin 2026', false)->assertSee('--color-red-500:#1D4ED8', false);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'branding.update']);

        $this->actingAs($admin)->post('/admin/branding', ['reset_logo' => 1, 'brand_accent' => '', 'reason' => 'Kembali ke bawaan']);
        $this->assertSame(Brand::DEFAULT_ACCENT, Brand::accent());
        $this->assertStringNotContainsString('brand/logo-', Brand::logo());
    }

    public function test_branding_rejects_invalid_colour_and_non_image(): void
    {
        $this->actingAs($this->staff())->post('/admin/branding', [
            'brand_accent' => 'red', 'logo' => UploadedFile::fake()->create('x.exe', 10), 'reason' => 'uji validasi',
        ])->assertSessionHasErrors(['brand_accent', 'logo']);
    }

    public function test_branding_is_forbidden_for_support_role(): void
    {
        $this->actingAs($this->staff('support'))->post('/admin/branding', ['reason' => 'tidak boleh'])->assertForbidden();
    }

    public function test_tools_page_and_unconfigured_relay_test(): void
    {
        config(['postivo.relay.stalwart_dsn' => null]);
        $admin = $this->staff();
        $this->actingAs($admin)->get('/admin/tools')->assertOk()->assertSee('belum dikonfigurasi');

        $this->actingAs($admin)->post('/admin/tools/connect', ['relay' => 'STALWART'])
            ->assertRedirect()->assertSessionHas('tool_result.ok', false);
        $this->assertSame(1, AdminAuditLog::where('action', 'tools.relay_connect')->count());
    }

    public function test_tool_error_messages_never_leak_credentials(): void
    {
        config(['postivo.relay.brevo_dsn' => 'smtp://user:xsmtpsib-SECRET123@127.0.0.1:1']);
        $res = $this->actingAs($this->staff())->post('/admin/tools/connect', ['relay' => 'BREVO'])->assertRedirect();
        $msg = session('tool_result.message');
        $this->assertStringNotContainsString('SECRET123', (string) $msg);
    }

    public function test_dns_check_reports_ok_fail_and_unknown(): void
    {
        config(['postivo.hosts.app' => 'postivo.test', 'postivo.dns.doh_primary' => 'https://doh.test/q', 'postivo.dns.doh_fallback' => 'https://doh2.test/q']);
        Http::fake(function ($req) {
            parse_str(parse_url($req->url(), PHP_URL_QUERY), $q);
            $ans = fn (array $d) => Http::response(['Status' => 0, 'Answer' => array_map(fn ($x) => ['data' => $x], $d)]);

            return match ($q['type'].'|'.$q['name']) {
                'TXT|postivo.test' => $ans(['"v=spf1 include:spf.brevo.com ~all"']),
                'TXT|_dmarc.postivo.test' => Http::response(['Status' => 3]),
                default => Http::response('x', 500),
            };
        });

        $this->actingAs($this->staff())->post('/admin/tools/dns')->assertRedirect();
        $rows = collect(session('dns_result.rows'));
        $this->assertSame('ok', $rows->firstWhere('label', 'SPF domain pengirim')['status']);
        $this->assertSame('fail', $rows->firstWhere('label', 'DMARC')['status']);
        $this->assertSame('unknown', $rows->firstWhere('label', 'Host SMTP')['status']);
    }

    public function test_send_test_validates_recipient_and_is_staff_only(): void
    {
        $this->actingAs($this->staff())->post('/admin/tools/send', ['relay' => 'SYSTEM', 'to' => 'bukan-email'])->assertSessionHasErrors('to');
        $this->actingAs($this->staff('finance'))->post('/admin/tools/send', ['relay' => 'SYSTEM', 'to' => 'a@b.test'])->assertForbidden();
    }
}
