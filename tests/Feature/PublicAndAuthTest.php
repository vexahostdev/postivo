<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\TeamInvitationNotification;
use App\Notifications\VerifyEmailNotification;
use App\Notifications\WelcomeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicAndAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_landing_and_docs_render_with_footer_and_domain(): void
    {
        $this->get('/')->assertOk()->assertSee('All rights reserved. Created by postivo', false)->assertSee('Pricing')->assertSee('Rp 99.000');
        $this->get('/docs')->assertOk()->assertSee('POST /v1/emails')->assertSee('api.postivo.my.id')->assertSee('Created by postivo')
            ->assertSee('Authorization: Bearer', false)->assertDontSee('postivo.id/', false);
    }

    public function test_register_sends_welcome_and_verification_then_verifies(): void
    {
        Notification::fake();
        $this->post('/register', ['name' => 'N', 'organization' => 'Org', 'email' => 'n@x.test', 'password' => 'password-123456', 'password_confirmation' => 'password-123456'])
            ->assertRedirect('/console');
        $user = User::where('email', 'n@x.test')->firstOrFail();
        Notification::assertSentTo($user, WelcomeNotification::class);
        Notification::assertSentTo($user, VerifyEmailNotification::class);

        $this->assertFalse($user->hasVerifiedEmail());
        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->actingAs($user)->get($url)->assertRedirect('/console');
        $this->assertTrue($user->refresh()->hasVerifiedEmail());
    }

    public function test_live_api_key_requires_verified_email_but_test_key_does_not(): void
    {
        $user = User::create(['name' => 'U', 'email' => 'u@x.test', 'password' => 'password-123456']);
        $org = Organization::create(['name' => 'O', 'slug' => 'o']);
        Member::create(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => 'owner']);
        $body = ['name' => 'k', 'scopes' => ['emails:send']];

        $this->actingAs($user)->post('/console/api-keys', $body + ['environment' => 'live'])->assertSessionHas('error');
        $this->assertSame(0, \App\Models\ApiKey::withoutGlobalScopes()->count());
        $this->actingAs($user)->post('/console/api-keys', $body + ['environment' => 'test'])->assertSessionHas('reveal');
    }

    public function test_password_reset_flow_invalidates_the_old_http_session(): void
    {
        Notification::fake();
        config(['session.driver' => 'array', 'session.encrypt' => false]);
        $user = User::create(['name' => 'U', 'email' => 'u@x.test', 'password' => 'password-123456']);
        $org = Organization::create(['name' => 'O', 'slug' => 'o']);
        Member::create(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => 'owner']);

        $cookieName = config('session.cookie');
        $browserRequest = function (string $method, string $uri, array $data = [], ?string $cookie = null) use ($cookieName) {
            // Hapus cache antar-request, bukan sesi yang tersimpan di handler.
            // Identitas harus dimuat dari cookie HTTP asli, bukan actingAs.
            $this->app['auth']->forgetGuards();
            $this->app['session']->driver()->flush();

            return $this->call($method, $uri, $data, $cookie === null ? [] : [$cookieName => $cookie]);
        };

        $login = $browserRequest('POST', '/login', ['email' => $user->email, 'password' => 'password-123456']);
        $login->assertRedirect('/console')->assertCookie($cookieName)->assertSessionHas('password_hash_web');
        $oldCookie = $login->getCookie($cookieName, false)->getValue();
        $oldSessionId = $login->getCookie($cookieName)->getValue();
        $oldPasswordBinding = session('password_hash_web');
        $browserRequest('GET', '/console', [], $oldCookie)->assertOk();
        $this->assertAuthenticatedAs($user);

        // Reset dilakukan dari browser lain; cookie lama tetap disimpan untuk replay.
        $browserRequest('POST', '/forgot-password', ['email' => $user->email])->assertSessionHas('status');
        $browserRequest('POST', '/forgot-password', ['email' => 'nobody@x.test'])->assertSessionHas('status'); // respons seragam
        $token = null;
        Notification::assertSentTo($user, ResetPasswordNotification::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });
        $this->assertNotNull($token);
        $browserRequest('POST', '/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'new-password-9999', 'password_confirmation' => 'new-password-9999'])
            ->assertRedirect('/login')->assertSessionHasNoErrors();

        // Buktikan penolakan bukan akibat fixture menghapus sesi lama dari storage.
        $this->assertStringContainsString($oldPasswordBinding, $this->app['session']->driver()->getHandler()->read($oldSessionId));
        $browserRequest('GET', '/console', [], $oldCookie)->assertRedirect('/login')->assertSessionMissing('password_hash_web');
        $this->assertGuest();
        $browserRequest('POST', '/login', ['email' => $user->email, 'password' => 'password-123456'])->assertSessionHasErrors('email');
        $freshLogin = $browserRequest('POST', '/login', ['email' => $user->email, 'password' => 'new-password-9999']);
        $freshLogin->assertRedirect('/console')->assertSessionHas('password_hash_web');
        $this->assertNotSame($oldPasswordBinding, session('password_hash_web'));
        $freshCookie = $freshLogin->getCookie($cookieName, false)->getValue();
        $this->assertNotSame($oldCookie, $freshCookie);
        $browserRequest('GET', '/console', [], $freshCookie)->assertOk();
        $this->assertAuthenticatedAs($user->fresh());
    }

    public static function impersonationRequestsAfterPasswordReset(): array
    {
        return [
            'customer console' => ['GET', '/console'],
            'restore original staff' => ['POST', '/impersonate/stop'],
        ];
    }

    #[DataProvider('impersonationRequestsAfterPasswordReset')]
    public function test_support_password_reset_invalidates_the_old_impersonation_http_session(string $method, string $uri): void
    {
        Notification::fake();
        Http::preventStrayRequests();
        config(['session.driver' => 'array', 'session.encrypt' => false, 'postivo.admin.require_2fa' => false]);
        $staff = User::create(['name' => 'Support', 'email' => 'support@x.test', 'password' => 'password-123456']);
        $staff->forceFill(['platform_role' => 'support'])->save();
        $owner = User::create(['name' => 'Owner', 'email' => 'owner@x.test', 'password' => 'owner-password-123456']);
        $org = Organization::create(['name' => 'Customer', 'slug' => 'customer']);
        Member::create(['organization_id' => $org->id, 'user_id' => $owner->id, 'role' => 'owner']);

        $cookieName = config('session.cookie');
        $browserRequest = function (string $method, string $uri, array $data = [], ?string $cookie = null) use ($cookieName) {
            // Hapus cache antar-request, bukan sesi yang tersimpan di handler.
            // Identitas harus dimuat dari cookie HTTP asli, bukan actingAs.
            $this->app['auth']->forgetGuards();
            $this->app['session']->driver()->flush();

            return $this->call($method, $uri, $data, $cookie === null ? [] : [$cookieName => $cookie]);
        };

        $login = $browserRequest('POST', '/login', ['email' => $staff->email, 'password' => 'password-123456']);
        $login->assertRedirect('/admin')->assertCookie($cookieName)->assertSessionHas('password_hash_web');
        $this->assertAuthenticatedAs($staff);
        $loginKey = Auth::guard()->getName();
        $staffPasswordBinding = session('password_hash_web');
        $impersonation = $browserRequest('POST', "/admin/organizations/{$org->id}/impersonate", ['reason' => 'tiket reset 123'], $login->getCookie($cookieName, false)->getValue());
        $impersonation->assertRedirect('/console')->assertCookie($cookieName)
            ->assertSessionHas($loginKey, $owner->id)->assertSessionHas('org_id', $org->id)
            ->assertSessionHas('impersonator_id', $staff->id)->assertSessionHas('impersonator_password_hash', $staffPasswordBinding);
        $this->assertAuthenticatedAs($owner);
        $oldCookie = $impersonation->getCookie($cookieName, false)->getValue();
        $oldSessionId = $impersonation->getCookie($cookieName)->getValue();
        $this->assertNotSame($oldSessionId, $oldCookie); // Cookie terenkripsi, bukan ID sesi mentah.
        $browserRequest('GET', '/console', [], $oldCookie)->assertOk();
        $this->assertAuthenticatedAs($owner);
        $ownerPasswordBinding = session('password_hash_web');
        $this->assertSame(Auth::guard()->hashPasswordForCookie($owner->getAuthPassword()), $ownerPasswordBinding);
        $this->assertSame(Auth::guard()->hashPasswordForCookie($staff->getAuthPassword()), $staffPasswordBinding);
        $this->assertNotSame($staffPasswordBinding, $ownerPasswordBinding);
        $handler = $this->app['session']->driver()->getHandler();
        $storedSession = $handler->read($oldSessionId);
        $this->assertStringContainsString($staffPasswordBinding, $storedSession);
        $this->assertStringContainsString($ownerPasswordBinding, $storedSession);
        $this->assertStringContainsString($owner->id, $storedSession);

        // Browser reset tidak membawa cookie staf maupun cookie impersonasi pelanggan.
        $browserRequest('POST', '/forgot-password', ['email' => $staff->email])->assertSessionHas('status');
        $this->assertGuest();
        $token = null;
        Notification::assertSentTo($staff, ResetPasswordNotification::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });
        $this->assertNotNull($token);
        $browserRequest('POST', '/reset-password', ['token' => $token, 'email' => $staff->email, 'password' => 'new-staff-password-9999', 'password_confirmation' => 'new-staff-password-9999'])
            ->assertRedirect('/login')->assertSessionHasNoErrors();
        $this->assertGuest();
        $this->assertNotSame($staffPasswordBinding, Auth::guard()->hashPasswordForCookie($staff->fresh()->getAuthPassword()));
        $this->assertSame($ownerPasswordBinding, Auth::guard()->hashPasswordForCookie($owner->fresh()->getAuthPassword()));

        // Sesi lama masih utuh; setiap dataset menguji penolakan pertama secara independen.
        $this->assertSame($storedSession, $handler->read($oldSessionId));
        $rejected = $browserRequest($method, $uri, [], $oldCookie);
        $rejected->assertRedirect('/login')->assertSessionMissing($loginKey)
            ->assertSessionMissing('password_hash_web')->assertSessionMissing('impersonator_id')
            ->assertSessionMissing('impersonator_password_hash')->assertSessionMissing('org_id');
        $this->assertGuest();
        $this->assertNotSame($oldSessionId, session()->getId());
        $this->assertSame('', $handler->read($oldSessionId));
        $browserRequest('GET', '/console', [], $rejected->getCookie($cookieName, false)->getValue())->assertRedirect('/login');
        $this->assertGuest();

        $browserRequest('POST', '/login', ['email' => $staff->email, 'password' => 'password-123456'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $freshLogin = $browserRequest('POST', '/login', ['email' => $staff->email, 'password' => 'new-staff-password-9999']);
        $freshLogin->assertRedirect('/admin')->assertSessionHas($loginKey, $staff->id)
            ->assertSessionHas('password_hash_web')->assertSessionMissing('impersonator_id')
            ->assertSessionMissing('impersonator_password_hash')->assertSessionMissing('org_id');
        $this->assertAuthenticatedAs($staff->fresh());
        $this->assertNotSame($staffPasswordBinding, session('password_hash_web'));
        $freshCookie = $freshLogin->getCookie($cookieName, false)->getValue();
        $this->assertNotSame($oldCookie, $freshCookie);
        $browserRequest('GET', '/admin', [], $freshCookie)->assertOk();
        $this->assertAuthenticatedAs($staff->fresh());
    }

    public function test_team_invitation_flow(): void
    {
        Notification::fake();
        $owner = User::create(['name' => 'O', 'email' => 'o@x.test', 'password' => 'password-123456']);
        $org = Organization::create(['name' => 'Org', 'slug' => 'org']);
        Member::create(['organization_id' => $org->id, 'user_id' => $owner->id, 'role' => 'owner']);

        $this->actingAs($owner)->post('/console/settings/invitations', ['email' => 'new@x.test', 'role' => 'developer'])->assertSessionHas('status');
        $token = null;
        Notification::assertSentOnDemand(TeamInvitationNotification::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $invited = User::create(['name' => 'N', 'email' => 'new@x.test', 'password' => 'password-123456']);
        $this->flushSession(); // actingAs tidak mengganti binding password sesi pengguna sebelumnya.
        $this->actingAs($invited)->post("/invitations/{$token}")->assertRedirect('/console');
        $this->assertTrue(Member::where('organization_id', $org->id)->where('user_id', $invited->id)->where('role', 'developer')->exists());

        // Pengguna lain tidak bisa memakai undangan orang lain.
        $other = User::create(['name' => 'X', 'email' => 'x@x.test', 'password' => 'password-123456']);
        $this->flushSession();
        $this->actingAs($owner)->post('/console/settings/invitations', ['email' => 'x@x.test', 'role' => 'viewer']);
        Notification::assertSentOnDemandTimes(TeamInvitationNotification::class, 2);
    }

    public function test_system_mail_uses_brevo_sender_and_support_reply_to(): void
    {
        $user = User::create(['name' => 'U', 'email' => 'u@x.test', 'password' => 'password-123456']);
        $mail = (new WelcomeNotification)->toMail($user);

        $this->assertSame('noreply@postivo.my.id', config('mail.from.address'));
        $this->assertSame('support@postivo.my.id', $mail->replyTo[0][0]);
        $this->assertSame('smtp', config('mail.mailers.brevo.transport'));
        $this->assertSame('smtp-relay.brevo.com', config('mail.mailers.brevo.host'));
    }
}
