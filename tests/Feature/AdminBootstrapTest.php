<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminBootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_superadmin_from_env_config_and_is_idempotent(): void
    {
        config(['postivo.admin.bootstrap' => ['name' => 'Administrator', 'email' => 'Admin@Postivo.my.id', 'password' => '@Lovey0u'], 'postivo.admin.require_2fa' => false]);

        $this->assertSame(0, Artisan::call('postivo:admin:create'));
        $u = User::where('email', 'admin@postivo.my.id')->firstOrFail();
        $this->assertSame('superadmin', $u->platform_role->value);
        $this->assertTrue($u->hasVerifiedEmail());
        $this->assertTrue(Hash::check('@Lovey0u', $u->password));
        $this->assertNotSame('@Lovey0u', $u->password);

        // Jalankan lagi dengan password baru: akun sama diperbarui, tidak digandakan, 2FA tidak disentuh.
        config(['postivo.admin.bootstrap.password' => 'password-baru-123']);
        Artisan::call('postivo:admin:create');
        $this->assertSame(1, User::where('email', 'admin@postivo.my.id')->count());
        $this->assertTrue(Hash::check('password-baru-123', $u->refresh()->password));
        $this->assertSame(['staff.bootstrap_create', 'staff.bootstrap_update'], AdminAuditLog::orderBy('id')->pluck('action')->all());
        $this->assertStringNotContainsString('Lovey0u', json_encode(AdminAuditLog::all()->toArray()));
    }

    public function test_command_refuses_without_credentials_and_reenables_disabled_account(): void
    {
        config(['postivo.admin.bootstrap' => ['name' => 'A', 'email' => null, 'password' => null]]);
        $this->assertSame(1, Artisan::call('postivo:admin:create'));
        $this->assertSame(0, User::count());

        User::create(['name' => 'Old', 'email' => 'admin@postivo.my.id', 'password' => 'x-password-123'])->forceFill(['disabled_at' => now()])->save();
        Artisan::call('postivo:admin:create', ['--email' => 'admin@postivo.my.id', '--password' => '@Lovey0u']);
        $this->assertNull(User::where('email', 'admin@postivo.my.id')->first()->disabled_at);
    }

    public function test_staff_can_use_the_customer_console_with_an_internal_org_and_regular_user_without_org_sees_friendly_page(): void
    {
        $staff = User::create(['name' => 'S', 'email' => 's@p.test', 'password' => 'password-123456']);
        $staff->forceFill(['platform_role' => 'superadmin'])->save();

        // Pertama kali buka console: organisasi internal dibuat otomatis (owner), console berfungsi penuh.
        $this->actingAs($staff)->get('/console')->assertOk()->assertSee('Postivo Internal');
        $this->assertSame(1, \App\Models\Member::where('user_id', $staff->id)->count());
        $this->get('/console/billing')->assertOk();
        $this->get('/console/domains')->assertOk();
        $this->post('/console/api-keys', ['name' => 'k', 'environment' => 'test', 'scopes' => ['emails:send']])->assertSessionHas('reveal'); // bisa menulis, bukan read-only
        $this->get('/console')->assertOk();
        $this->assertSame(1, \App\Models\Member::where('user_id', $staff->id)->count(), 'tidak digandakan');
        $this->get('/admin')->assertOk()->assertSee('Buka Console');

        $plain = User::create(['name' => 'P', 'email' => 'p@p.test', 'password' => 'password-123456']);
        $this->flushSession();
        $this->actingAs($plain)->get('/console')->assertStatus(403)->assertSee('Belum tergabung di organisasi')->assertSee('Keluar');
    }

    public function test_production_seeding_does_not_create_demo_accounts_domains_or_keys(): void
    {
        $this->app->instance('env', 'production');
        config(['postivo.admin.bootstrap' => ['name' => 'Administrator', 'email' => null, 'password' => null]]);

        $this->artisan('db:seed', ['--class' => \Database\Seeders\DatabaseSeeder::class, '--force' => true]);

        $this->assertSame(0, User::count());
        $this->assertSame(0, \App\Models\Organization::count());
        $this->assertSame(0, \App\Models\Domain::withoutGlobalScopes()->count());
        $this->assertSame(0, \App\Models\ApiKey::withoutGlobalScopes()->count());
    }

    public function test_staging_seeding_does_not_create_demo_data(): void
    {
        $this->app->instance('env', 'staging');
        config(['postivo.admin.bootstrap' => ['name' => 'Administrator', 'email' => null, 'password' => null]]);

        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $this->assertSame(0, User::count());
        $this->assertSame(0, \App\Models\Organization::count());
        $this->assertSame(0, \App\Models\Domain::withoutGlobalScopes()->count());
        $this->assertSame(0, \App\Models\ApiKey::withoutGlobalScopes()->count());
    }

    public function test_production_seeding_preserves_explicit_admin_bootstrap(): void
    {
        $this->app->instance('env', 'production');
        config(['postivo.admin.bootstrap' => ['name' => 'Administrator', 'email' => 'bootstrap@example.test', 'password' => 'dummy-bootstrap-123456']]);

        $this->artisan('db:seed', ['--class' => \Database\Seeders\DatabaseSeeder::class, '--force' => true]);

        $this->assertSame(1, User::count());
        $this->assertSame('superadmin', User::firstOrFail()->platform_role->value);
        $this->assertFalse(User::where('email', 'like', '%@postivo.test')->exists());
        $this->assertSame(0, \App\Models\Domain::withoutGlobalScopes()->count());
        $this->assertSame(0, \App\Models\ApiKey::withoutGlobalScopes()->count());
    }

    public function test_admin_can_log_in_and_lands_in_admin_panel(): void
    {
        config(['postivo.admin.bootstrap' => ['name' => 'A', 'email' => 'admin@postivo.my.id', 'password' => '@Lovey0u'], 'postivo.admin.require_2fa' => false]);
        Artisan::call('postivo:admin:create');

        $this->post('/login', ['email' => 'admin@postivo.my.id', 'password' => '@Lovey0u'])->assertRedirect('/admin');
        $this->get('/admin')->assertOk()->assertSee('Dashboard platform');
    }
}
