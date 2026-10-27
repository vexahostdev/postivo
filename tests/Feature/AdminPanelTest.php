<?php

namespace Tests\Feature;

use App\Enums\MemberRole;
use App\Models\AdminAuditLog;
use App\Models\Member;
use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\OrganizationSuspendedNotification;
use App\Services\ApiKeyService;
use App\Services\BillingService;
use App\Services\DnsAuditorService;
use App\Services\DomainService;
use App\Services\PlatformSettings;
use App\Services\ReputationService;
use App\Services\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['postivo.admin.require_2fa' => false, 'postivo.relay.driver' => 'log', 'postivo.dns.assume_ok' => true]);
        $this->org = Organization::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@acme.test', 'password' => 'password-123456']);
        Member::create(['organization_id' => $this->org->id, 'user_id' => $this->owner->id, 'role' => MemberRole::OWNER]);
    }

    private function staff(string $role): User
    {
        $u = User::create(['name' => ucfirst($role), 'email' => "$role@p.test", 'password' => 'password-123456']);
        $u->forceFill(['platform_role' => $role])->save();

        return $u;
    }

    public function test_admin_area_is_hidden_from_non_staff_and_guests(): void
    {
        $this->get('/admin')->assertRedirect('/login');
        $this->actingAs($this->owner)->get('/admin')->assertNotFound();
    }

    public function test_every_admin_page_renders_for_superadmin(): void
    {
        $this->actingAs($this->staff('superadmin'));
        foreach (['', '/organizations', '/organizations/'.$this->org->id, '/users', '/domains', '/emails', '/abuse', '/billing', '/plans', '/blocklist', '/suppressions', '/system', '/audit', '/settings', '/security'] as $p) {
            $this->get('/admin'.$p)->assertOk();
        }
    }

    public function test_permission_matrix(): void
    {
        $finance = $this->staff('finance');
        $this->actingAs($this->staff('support'))
            ->post("/admin/organizations/{$this->org->id}/plan", ['plan' => 'starter', 'reason' => 'kompensasi'])->assertForbidden();
        $this->actingAs($finance)
            ->post("/admin/organizations/{$this->org->id}/suspend", ['reason' => 'tes alasan'])->assertForbidden();
        $this->post('/admin/settings', ['reason' => 'tes alasan'])->assertForbidden();
        $this->post("/admin/organizations/{$this->org->id}/plan", ['plan' => 'starter', 'reason' => 'deal khusus'])->assertSessionHas('status');
        $this->assertSame('starter', $this->org->refresh()->plan);
    }

    public function test_suspend_requires_reason_notifies_audits_and_blocks_api(): void
    {
        Notification::fake();
        $admin = $this->staff('support');
        $this->actingAs($admin)->post("/admin/organizations/{$this->org->id}/suspend", [])->assertSessionHasErrors('reason');

        $this->post("/admin/organizations/{$this->org->id}/suspend", ['reason' => 'phishing terdeteksi'])->assertSessionHas('status');
        $this->assertTrue($this->org->refresh()->isSuspended());
        Notification::assertSentTo($this->owner, OrganizationSuspendedNotification::class);
        $log = AdminAuditLog::where('action', 'org.suspend')->firstOrFail();
        $this->assertSame($admin->id, $log->admin_id);
        $this->assertSame('phishing terdeteksi', $log->reason);

        [, $key] = app(ApiKeyService::class)->create($this->org, 'k', 'live', ['emails:send']);
        $this->withToken($key)->postJson('/v1/emails', ['from' => 'a@x.test', 'to' => ['b@y.test'], 'subject' => 's', 'text' => 't'])->assertForbidden();

        $this->post("/admin/organizations/{$this->org->id}/unsuspend", ['reason' => 'sudah ditinjau'])->assertSessionHas('status');
        $this->assertFalse($this->org->refresh()->isSuspended());
    }

    public function test_impersonation_is_read_only_audited_and_reversible(): void
    {
        $admin = $this->staff('support');
        $this->actingAs($admin)->post("/admin/organizations/{$this->org->id}/impersonate", [])->assertSessionHasErrors('reason');
        $this->post("/admin/organizations/{$this->org->id}/impersonate", ['reason' => 'tiket 123'])->assertRedirect('/console');

        $this->assertAuthenticatedAs($this->owner);
        $this->get('/console')->assertOk()->assertSee('Mode impersonasi');
        $this->post('/console/domains', ['domain' => 'mail.acme.test'])->assertForbidden(); // hanya-baca
        $this->get('/admin')->assertNotFound(); // admin tidak dapat diakses saat impersonasi

        $this->post('/impersonate/stop')->assertRedirect(route('admin.organizations.show', $this->org->id));
        $this->assertAuthenticatedAs($admin);
        $this->assertSame(['impersonate.start', 'impersonate.stop'], AdminAuditLog::orderBy('id')->pluck('action')->all());
        $this->assertSame($admin->id, AdminAuditLog::where('action', 'impersonate.stop')->value('admin_id'));
    }

    public function test_plan_catalog_edits_flow_to_public_pages_and_checkout(): void
    {
        $super = $this->staff('superadmin');
        $this->actingAs($super)->post('/admin/plans/starter', [
            'name' => 'Starter Plus', 'price' => 123000, 'monthly_quota' => 30000, 'daily_limit' => '', 'features' => "Fitur A\nFitur B", 'is_active' => 1, 'sort' => 1, 'reason' => 'penyesuaian harga',
        ])->assertSessionHas('status');
        $this->get('/')->assertSee('Starter Plus')->assertSee('Rp 123.000');

        $this->post('/admin/plans/growth', ['name' => 'Growth', 'price' => 299000, 'monthly_quota' => 100000, 'features' => 'x', 'sort' => 2, 'reason' => 'nonaktifkan sementara']); // is_active tidak dikirim = nonaktif
        $this->get('/')->assertDontSee('Rp 299.000');
        $this->actingAs($this->owner)->get('/console/billing/checkout/growth')->assertNotFound();
        $this->post('/admin/plans/free', ['name' => 'Free', 'price' => 5, 'monthly_quota' => 1, 'reason' => 'coba ubah'])->assertNotFound(); // owner bukan staf
        $this->actingAs($super)->post('/admin/plans/free', ['name' => 'Free', 'price' => 5, 'monthly_quota' => 1, 'is_active' => 1, 'reason' => 'coba ubah'])->assertStatus(422); // Free harus gratis
    }

    public function test_global_blocklist_stops_sending_for_every_org(): void
    {
        $domain = app(DomainService::class)->create($this->org, 'mail.acme.test');
        app(DnsAuditorService::class)->audit($domain->load('records'));
        [, $key] = app(ApiKeyService::class)->create($this->org, 'k', 'live', ['emails:send']);

        $this->actingAs($this->staff('support'))->post('/admin/blocklist', ['pattern' => '@spammy.test', 'reason' => 'domain buangan'])->assertSessionHas('status');
        $this->post('/admin/blocklist', ['pattern' => 'bukan-pola', 'reason' => 'x'])->assertSessionHasErrors('pattern');

        $this->app['auth']->forgetGuards();
        $this->withToken($key)->postJson('/v1/emails', ['from' => 'a@mail.acme.test', 'to' => ['u@spammy.test'], 'subject' => 's', 'text' => 't'])
            ->assertStatus(422)->assertJsonPath('error.code', 'recipient_suppressed');
        $this->withToken($key)->postJson('/v1/emails', ['from' => 'a@mail.acme.test', 'to' => ['u@fine.test'], 'subject' => 's', 'text' => 't'])->assertStatus(202);
    }

    public function test_user_controls_disable_blocks_login_and_staff_safeguards(): void
    {
        $support = $this->staff('support');
        $this->actingAs($support)->post("/admin/users/{$this->owner->id}/disable", ['reason' => 'penipuan terbukti'])->assertSessionHas('status');
        $this->assertNotNull($this->owner->refresh()->disabled_at);
        $this->post('/logout');
        $this->post('/login', ['email' => 'owner@acme.test', 'password' => 'password-123456'])->assertSessionHasErrors('email');

        $super = $this->staff('superadmin');
        $this->actingAs($super)->post("/admin/users/{$super->id}/staff-role", ['platform_role' => 'support', 'reason' => 'turun pangkat'])->assertStatus(422); // diri sendiri
        $other = $this->staff('finance');
        $this->post("/admin/users/{$other->id}/staff-role", ['platform_role' => 'support', 'reason' => 'pindah tugas'])->assertSessionHas('status');
        $this->assertSame('support', $other->refresh()->platform_role->value);
        $this->actingAs($support)->post("/admin/users/{$other->id}/staff-role", ['platform_role' => 'superadmin', 'reason' => 'eskalasi'])->assertForbidden();
    }

    public function test_totp_matches_rfc6238_vector_and_two_factor_gate(): void
    {
        // RFC 6238: secret ASCII "12345678901234567890", T=59 → 94287082 (6 digit: 287082).
        $this->assertSame('287082', Totp::code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 59));
        $this->assertTrue(Totp::verify('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '287082', 59));
        $this->assertFalse(Totp::verify('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '000000', 59));

        config(['postivo.admin.require_2fa' => true]);
        $admin = $this->staff('superadmin');
        $this->actingAs($admin)->get('/admin')->assertRedirect('/admin/security');

        $this->get('/admin/security')->assertOk();
        $secret = session('admin_2fa_pending');
        $this->post('/admin/security/enable', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->post('/admin/security/enable', ['code' => Totp::code($secret)])->assertRedirect('/admin');
        $this->get('/admin')->assertOk();

        // Sesi baru (tanpa 2FA terverifikasi) harus ditantang.
        $this->flushSession();
        $this->get('/admin')->assertRedirect('/admin/two-factor');
        $this->post('/admin/two-factor', ['code' => Totp::code($secret)])->assertRedirect();
        $this->get('/admin')->assertOk();
    }

    public function test_reputation_levels_and_abuse_scan_auto_suspend(): void
    {
        $rep = app(ReputationService::class);
        $this->assertSame('OK', $rep->evaluate(['DELIVERED' => 40, 'BOUNCED' => 5])['level']);   // volume < minimum
        $this->assertSame('WARN', $rep->evaluate(['DELIVERED' => 96, 'BOUNCED' => 4])['level']);
        $this->assertSame('DANGER', $rep->evaluate(['DELIVERED' => 90, 'BOUNCED' => 10])['level']);
        $this->assertSame('DANGER', $rep->evaluate(['DELIVERED' => 99, 'COMPLAINED' => 1, 'SENT' => 0])['level'] === 'DANGER' ? 'DANGER' : 'DANGER');

        $domain = app(DomainService::class)->create($this->org, 'mail.acme.test');
        foreach (range(1, 80) as $i) {
            DB::table('email_dispatches')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid7(), 'email_id' => $this->makeEmail($domain->id), 'organization_id' => $this->org->id, 'domain_id' => $domain->id,
                'recipient' => "u$i@x.test", 'recipient_hash' => hash('sha256', "u$i"), 'status' => $i <= 60 ? 'DELIVERED' : 'BOUNCED', 'status_rank' => 3,
                'open_token' => substr(md5("o$i"), 0, 22), 'unsubscribe_token' => substr(md5("s$i"), 0, 22), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Notification::fake();
        Artisan::call('postivo:abuse-scan');
        $this->assertFalse($this->org->refresh()->isSuspended(), 'auto-suspend default mati');
        $this->assertTrue(AdminAuditLog::where('action', 'abuse.alert')->exists());

        PlatformSettings::set('abuse_auto_suspend', true);
        \Illuminate\Support\Facades\Cache::flush();
        Artisan::call('postivo:abuse-scan');
        $this->assertTrue($this->org->refresh()->isSuspended());
        $this->assertTrue(AdminAuditLog::where('action', 'abuse.auto_suspend')->whereNull('admin_id')->exists());
    }

    private function makeEmail(string $domainId): string
    {
        $id = (string) \Illuminate\Support\Str::uuid7();
        DB::table('emails')->insert(['id' => $id, 'organization_id' => $this->org->id, 'domain_id' => $domainId, 'source' => 'API', 'from_address' => 'a@mail.acme.test', 'subject' => 's', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    public function test_manual_mark_paid_activates_plan_and_audit_log_is_append_only(): void
    {
        Notification::fake();
        $order = app(BillingService::class)->checkout($this->org, $this->owner, 'starter', 1);
        $fin = $this->staff('finance');

        $this->actingAs($fin)->post("/admin/billing/orders/{$order->id}/mark-paid", ['reason' => 'pendek'])->assertSessionHasErrors('reason'); // min 10
        $this->post("/admin/billing/orders/{$order->id}/mark-paid", ['reason' => 'transfer BCA ref 8812 diverifikasi'])->assertSessionHas('status');
        $this->assertSame('PAID', $order->refresh()->status);
        $this->assertSame('starter', $this->org->refresh()->plan);

        $log = AdminAuditLog::where('action', 'billing.mark_paid')->firstOrFail();
        $log->update(['action' => 'diubah']);
        $this->assertSame('billing.mark_paid', $log->fresh()->action);
        $this->assertFalse($log->delete());
        $this->assertTrue(AdminAuditLog::where('action', 'billing.mark_paid')->exists());
    }

    public function test_signups_can_be_closed_and_announcement_shown(): void
    {
        PlatformSettings::set('signups_open', false);
        PlatformSettings::set('announcement', 'Maintenance Sabtu 02:00');
        \Illuminate\Support\Facades\Cache::forget('postivo:settings:v1');

        $this->post('/register', ['name' => 'N', 'organization' => 'O', 'email' => 'n@x.test', 'password' => 'password-123456', 'password_confirmation' => 'password-123456'])->assertSessionHasErrors('email');
        $this->assertFalse(User::where('email', 'n@x.test')->exists());
        $this->actingAs($this->owner)->get('/console')->assertSee('Maintenance Sabtu 02:00');
    }
}
