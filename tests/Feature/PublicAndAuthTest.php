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
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
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

    public function test_password_reset_flow(): void
    {
        Notification::fake();
        $user = User::create(['name' => 'U', 'email' => 'u@x.test', 'password' => 'password-123456']);

        $this->post('/forgot-password', ['email' => 'u@x.test'])->assertSessionHas('status');
        $this->post('/forgot-password', ['email' => 'nobody@x.test'])->assertSessionHas('status'); // respons seragam
        $token = null;
        Notification::assertSentTo($user, ResetPasswordNotification::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $this->post('/reset-password', ['token' => $token, 'email' => 'u@x.test', 'password' => 'new-password-9999', 'password_confirmation' => 'new-password-9999'])->assertRedirect('/login');
        $this->post('/login', ['email' => 'u@x.test', 'password' => 'new-password-9999'])->assertRedirect('/console');
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
        $this->actingAs($invited)->post("/invitations/{$token}")->assertRedirect('/console');
        $this->assertTrue(Member::where('organization_id', $org->id)->where('user_id', $invited->id)->where('role', 'developer')->exists());

        // Pengguna lain tidak bisa memakai undangan orang lain.
        $other = User::create(['name' => 'X', 'email' => 'x@x.test', 'password' => 'password-123456']);
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
