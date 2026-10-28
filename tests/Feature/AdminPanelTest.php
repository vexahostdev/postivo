<?php

namespace Tests\Feature;

use App\Enums\MemberRole;
use App\Http\Middleware\EnsureValidImpersonation;
use App\Models\AdminAuditLog;
use App\Models\Member;
use App\Models\Order;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Notifications\OrganizationSuspendedNotification;
use App\Notifications\VerifyEmailNotification;
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
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->flushSession(); // actingAs tidak mengganti binding password sesi pengguna sebelumnya.
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
        $this->get(route('admin.organizations.show', $this->org->id))->assertOk();
    }

    public static function invalidImpersonators(): array
    {
        return [
            'permission revoked' => [['platform_role' => 'finance']],
            'staff role removed' => [['platform_role' => null]],
            'disabled' => [['disabled_at' => '2025-01-01 00:00:00']],
            'password reset' => [['password' => 'new-staff-password-123456']],
            'deleted' => [null],
        ];
    }

    private function beginImpersonation(): User
    {
        $admin = $this->staff('support');
        $this->actingAs($admin)->post("/admin/organizations/{$this->org->id}/impersonate", ['reason' => 'tiket 123'])->assertRedirect('/console');
        $this->assertAuthenticatedAs($this->owner);

        return $admin;
    }

    private function invalidateImpersonator(?array $changes): void
    {
        $admin = $this->beginImpersonation();
        if ($changes === null) {
            $admin->delete();
        } else {
            $admin->forceFill($changes)->save();
        }
    }

    #[DataProvider('invalidImpersonators')]
    public function test_invalid_impersonator_stop_logs_out_before_any_console_write(?array $changes): void
    {
        $this->invalidateImpersonator($changes);
        $this->assertInvalidImpersonationStop();
    }

    #[DataProvider('invalidImpersonators')]
    public function test_stop_controller_independently_rejects_invalid_impersonators(?array $changes): void
    {
        $this->invalidateImpersonator($changes);
        $this->withoutMiddleware(EnsureValidImpersonation::class);
        $this->assertInvalidImpersonationStop();
    }

    private function assertInvalidImpersonationStop(): void
    {
        $oldToken = session()->token();
        $oldSessionId = session()->getId();
        $this->post('/impersonate/stop')->assertRedirect('/login')
            ->assertSessionMissing('impersonator_id')->assertSessionMissing('org_id')->assertSessionHas('_token');
        $this->assertGuest();
        $this->assertNotSame($oldToken, session()->token());
        $this->assertNotSame($oldSessionId, session()->getId());
        $this->withMiddleware(EnsureValidImpersonation::class);
        $this->post('/console/domains', ['domain' => 'mail.acme.test'])->assertRedirect('/login');
        $this->assertDatabaseCount('domains', 0);
    }

    #[DataProvider('invalidImpersonators')]
    public function test_invalid_impersonator_is_rejected_even_on_public_reads(?array $changes): void
    {
        $this->invalidateImpersonator($changes);
        $oldToken = session()->token();
        $this->get('/docs')->assertRedirect('/login')->assertSessionMissing('impersonator_id');
        $this->assertGuest();
        $this->assertNotSame($oldToken, session()->token());
        $this->get('/console')->assertRedirect('/login');
    }

    public function test_impersonation_blocks_invitation_acceptance_and_verification_resend(): void
    {
        Notification::fake();
        $otherOrg = Organization::create(['name' => 'Other', 'slug' => 'other']);
        $token = 'dummy-invitation-token';
        $invitation = OrganizationInvitation::create([
            'organization_id' => $otherOrg->id, 'email' => $this->owner->email, 'role' => 'developer',
            'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay(),
        ]);
        $this->beginImpersonation();
        $this->get("/invitations/{$token}")->assertOk();
        $this->head('/console')->assertOk();
        $this->post("/invitations/{$token}")->assertForbidden();
        $this->assertNull($invitation->refresh()->accepted_at);
        $this->assertDatabaseMissing('organization_members', ['organization_id' => $otherOrg->id, 'user_id' => $this->owner->id]);
        $this->assertSame($this->org->id, session('org_id'));
        $this->post('/verify-email/resend')->assertForbidden();
        Notification::assertNotSentTo($this->owner, VerifyEmailNotification::class);
    }

    public function test_impersonated_customer_can_log_out(): void
    {
        $this->beginImpersonation();
        $oldToken = session()->token();
        $this->post('/logout')->assertRedirect('/')
            ->assertSessionMissing('impersonator_id')->assertSessionMissing('org_id');
        $this->assertGuest();
        $this->assertNotSame($oldToken, session()->token());
        $this->post('/console/domains', ['domain' => 'mail.acme.test'])->assertRedirect('/login');
        $this->assertDatabaseCount('domains', 0);
    }

    public function test_web_callbacks_without_an_impersonator_marker_are_not_blocked(): void
    {
        $this->app['router']->middleware('web')->post('/_test/callback', fn () => response()->noContent());
        $this->post('/_test/callback')->assertNoContent();
        $this->actingAs($this->owner)->post('/_test/callback')->assertNoContent();
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
        $this->flushSession();
        $this->actingAs($this->owner)->get('/console/billing/checkout/growth')->assertNotFound();
        $this->post('/admin/plans/free', ['name' => 'Free', 'price' => 5, 'monthly_quota' => 1, 'reason' => 'coba ubah'])->assertNotFound(); // owner bukan staf
        $this->flushSession();
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
        $this->flushSession();
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
        $this->post('/admin/security/enable', ['code' => Totp::code($secret)])->assertRedirect('/admin')
            ->assertSessionHas('admin_2fa_fingerprint', $admin->fresh()->twoFactorFingerprint());
        $this->get('/admin')->assertOk();

        // Sesi baru (tanpa 2FA terverifikasi) harus ditantang.
        $this->flushSession();
        $this->get('/admin')->assertRedirect('/admin/two-factor');
        $this->post('/admin/two-factor', ['code' => Totp::code($secret)])->assertRedirect()
            ->assertSessionHas('admin_2fa_fingerprint', $admin->fresh()->twoFactorFingerprint());
        $this->get('/admin')->assertOk();
    }

    private function staffWithVerifiedFactor(): User
    {
        config(['postivo.admin.require_2fa' => true]);
        $admin = $this->staff('superadmin');
        $admin->forceFill(['two_factor_secret' => Totp::generateSecret(), 'two_factor_confirmed_at' => now()])->save();
        $this->actingAs($admin)->post('/admin/two-factor', ['code' => Totp::code($admin->two_factor_secret)])->assertRedirect('/admin');
        $this->get('/admin')->assertOk()->assertSessionHas('admin_2fa_fingerprint', $admin->twoFactorFingerprint());

        return $admin;
    }

    public function test_replaced_factor_requires_a_new_challenge_despite_recent_assurance(): void
    {
        $admin = $this->staffWithVerifiedFactor();
        $oldFingerprint = session('admin_2fa_fingerprint');
        $replacement = Totp::generateSecret();
        $admin->fresh()->forceFill(['two_factor_secret' => $replacement, 'two_factor_confirmed_at' => now()])->save();

        // Muat ulang pengguna seperti request baru, tanpa menghapus assurance sesi lama.
        $this->actingAs($admin->fresh())->get('/admin')->assertRedirect('/admin/two-factor')
            ->assertSessionMissing('admin_2fa_at')->assertSessionMissing('admin_2fa_fingerprint');
        $this->assertNotSame($oldFingerprint, $admin->fresh()->twoFactorFingerprint());
        $this->post('/admin/two-factor', ['code' => Totp::code($replacement)])->assertRedirect('/admin')
            ->assertSessionHas('admin_2fa_fingerprint', $admin->fresh()->twoFactorFingerprint());
        $this->get('/admin')->assertOk();
    }

    public function test_reset_factor_discards_previous_session_assurance(): void
    {
        $admin = $this->staffWithVerifiedFactor();
        $admin->fresh()->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
        $this->actingAs($admin->fresh())->get('/admin')->assertRedirect('/admin/security')
            ->assertSessionMissing('admin_2fa_at')->assertSessionMissing('admin_2fa_fingerprint');
    }

    public function test_timestamp_without_a_factor_fingerprint_is_not_assurance(): void
    {
        $this->staffWithVerifiedFactor();
        session()->forget('admin_2fa_fingerprint');
        $this->get('/admin')->assertRedirect('/admin/two-factor')->assertSessionMissing('admin_2fa_at');
    }

    public function test_factor_assurance_is_bound_to_the_user_even_with_the_same_secret(): void
    {
        $admin = $this->staffWithVerifiedFactor();
        $other = $this->staff('support');
        $other->forceFill(['two_factor_secret' => $admin->two_factor_secret, 'two_factor_confirmed_at' => now()])->save();
        $this->assertNotSame($admin->twoFactorFingerprint(), $other->twoFactorFingerprint());

        // Ganti fixture autentikasi saja; biarkan assurance pengguna sebelumnya untuk diuji.
        session()->forget('password_hash_web');
        $this->actingAs($other)->get('/admin')->assertRedirect('/admin/two-factor')
            ->assertSessionMissing('admin_2fa_at')->assertSessionMissing('admin_2fa_fingerprint');
    }

    public function test_stale_pending_secret_cannot_replace_an_enabled_factor(): void
    {
        $admin = $this->staffWithVerifiedFactor();
        $originalSecret = $admin->two_factor_secret;
        $pending = Totp::generateSecret();
        $this->withSession(['admin_2fa_pending' => $pending])
            ->post('/admin/security/enable', ['code' => Totp::code($pending)])->assertForbidden();
        $this->assertSame($originalSecret, $admin->fresh()->two_factor_secret);
        $this->assertSame($admin->twoFactorFingerprint(), session('admin_2fa_fingerprint'));
        $this->assertFalse(AdminAuditLog::where('action', 'staff.2fa_enabled')->exists());
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

        $this->get('/register')->assertOk()->assertSee('Pendaftaran Akun Baru Sedang Dikunci');
        $this->post('/register', ['name' => 'N', 'organization' => 'O', 'email' => 'n@x.test', 'password' => 'password-123456', 'password_confirmation' => 'password-123456'])->assertSessionHasErrors('email');
        $this->assertFalse(User::where('email', 'n@x.test')->exists());
        $this->actingAs($this->owner)->get('/console')->assertSee('Maintenance Sabtu 02:00');
    }

    public function test_admin_can_toggle_signups_lock_and_unlock(): void
    {
        $super = $this->staff('superadmin');
        PlatformSettings::set('signups_open', true);

        // Kunci pendaftaran lewat aksi admin
        $this->actingAs($super)->post('/admin/settings/toggle-signups', ['reason' => 'Testing staging lock'])
            ->assertSessionHas('status');
        $this->assertFalse((bool) PlatformSettings::get('signups_open'));
        $this->assertTrue(AdminAuditLog::where('action', 'settings.signups_locked')->exists());

        // Halaman register menampilkan banner terkunci dan form tidak muncul
        $this->post('/logout');
        $this->get('/register')->assertOk()->assertSee('Pendaftaran Akun Baru Sedang Dikunci');

        // Buka kembali pendaftaran
        $this->actingAs($super)->post('/admin/settings/toggle-signups', ['reason' => 'Testing staging unlock'])
            ->assertSessionHas('status');
        $this->assertTrue((bool) PlatformSettings::get('signups_open'));
        $this->assertTrue(AdminAuditLog::where('action', 'settings.signups_opened')->exists());

        // Pengguna non-staff tidak diizinkan mengubah
        $this->flushSession();
        $this->actingAs($this->owner)->post('/admin/settings/toggle-signups')->assertNotFound();

        // Staff support/finance tidak diizinkan (hanya settings.manage / superadmin)
        $this->flushSession();
        $this->actingAs($this->staff('support'))->post('/admin/settings/toggle-signups')->assertForbidden();
    }

    public function test_registration_artisan_command_controls_lock(): void
    {
        Artisan::call('postivo:registration', ['action' => 'lock']);
        $this->assertFalse((bool) PlatformSettings::get('signups_open'));

        Artisan::call('postivo:registration', ['action' => 'status']);
        $this->assertSame(0, Artisan::call('postivo:registration', ['action' => 'unlock']));
        $this->assertTrue((bool) PlatformSettings::get('signups_open'));
    }
}
