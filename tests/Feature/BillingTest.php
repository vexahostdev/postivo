<?php

namespace Tests\Feature;

use App\Enums\MemberRole;
use App\Models\Member;
use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\InvoiceCreatedNotification;
use App\Notifications\PaymentReceivedNotification;
use App\Notifications\PlanExpiredNotification;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        config(['postivo.billing.xendit_secret_key' => 'xnd_test', 'postivo.billing.internal_secret' => 'internal-s', 'postivo.billing.xendit_webhook_token' => 'cb-token']);
        $this->org = Organization::create(['name' => 'T', 'slug' => 't']);
        $this->user = User::create(['name' => 'Own', 'email' => 'own@t.test', 'password' => 'password-123456']);
        Member::create(['organization_id' => $this->org->id, 'user_id' => $this->user->id, 'role' => MemberRole::OWNER]);
    }

    private function order(): Order
    {
        Http::fake(['api.xendit.co/v2/invoices' => Http::response(['id' => 'inv_1', 'invoice_url' => 'https://checkout.xendit.co/web/inv_1'])]);

        return app(BillingService::class)->checkout($this->org, $this->user, 'starter', 2);
    }

    private function hook(array $payload, array $headers = ['X-Internal-Token' => 'internal-s'])
    {
        return $this->withHeaders($headers)->postJson('/api/payment/xendit/callback', $payload);
    }

    public function test_checkout_creates_xendit_invoice_with_psv_prefix_and_redirects(): void
    {
        Notification::fake();
        Http::fake(['api.xendit.co/v2/invoices' => Http::response(['id' => 'inv_1', 'invoice_url' => 'https://checkout.xendit.co/web/inv_1'])]);

        $res = $this->actingAs($this->user)->post('/console/billing/checkout/starter', ['months' => 2]);

        $res->assertRedirect('https://checkout.xendit.co/web/inv_1');
        $o = Order::withoutGlobalScopes()->first();
        $this->assertStringStartsWith('PSV-', $o->external_id);
        $this->assertSame(198000, $o->amount);
        Http::assertSent(fn ($r) => $r['external_id'] === $o->external_id && $r['amount'] === 198000);
        Notification::assertSentTo($this->user, InvoiceCreatedNotification::class);
    }

    public function test_free_plan_cannot_be_purchased_and_viewer_cannot_checkout(): void
    {
        $this->actingAs($this->user)->post("/console/billing/checkout/free", ["months" => 1])->assertNotFound();
        $viewer = User::create(['name' => 'V', 'email' => 'v@t.test', 'password' => 'password-123456']);
        Member::create(['organization_id' => $this->org->id, 'user_id' => $viewer->id, 'role' => MemberRole::VIEWER]);
        $this->actingAs($viewer)->get('/console/billing/checkout/starter')->assertForbidden();
    }

    public function test_webhook_requires_valid_token(): void
    {
        $this->hook(['external_id' => 'PSV-X', 'status' => 'PAID'], [])->assertStatus(403);
        $this->hook(['external_id' => 'PSV-X', 'status' => 'PAID'], ['X-Internal-Token' => 'wrong'])->assertStatus(403);
        $this->hook(['external_id' => 'PSV-X', 'status' => 'PAID'], ['x-callback-token' => 'cb-token'])->assertOk();
    }

    public function test_paid_webhook_activates_plan_once_and_is_idempotent(): void
    {
        $o = $this->order();
        Notification::fake();
        $p = ['id' => 'inv_1', 'external_id' => $o->external_id, 'status' => 'PAID', 'amount' => 198000, 'payment_channel' => 'QRIS'];

        $this->hook($p)->assertOk()->assertJsonPath('status', 'success');
        $this->hook($p)->assertOk()->assertJsonPath('status', 'duplicate');
        $this->hook($p + ['status' => 'SETTLED'])->assertOk(); // event lain, tetap tidak ganda

        $org = $this->org->refresh();
        $this->assertSame('starter', $org->plan);
        $this->assertSame(25000, $org->monthly_quota);
        $this->assertNull($org->daily_limit);
        $this->assertEqualsWithDelta(now()->addMonths(2)->timestamp, $org->plan_expires_at->timestamp, 5);
        $this->assertSame('PAID', $o->refresh()->status);
        Notification::assertSentToTimes($this->user, PaymentReceivedNotification::class, 1);
    }

    public function test_amount_mismatch_and_foreign_prefix_are_rejected_or_ignored(): void
    {
        $o = $this->order();
        $this->hook(['id' => 'inv_1', 'external_id' => $o->external_id, 'status' => 'PAID', 'amount' => 1])->assertStatus(422);
        $this->assertSame('PENDING', $o->refresh()->status);
        $this->assertSame('free', $this->org->refresh()->plan);

        $this->hook(['id' => 'x', 'external_id' => 'VH-ORD-1', 'status' => 'PAID', 'amount' => 1])->assertJsonPath('status', 'ignored');
    }

    public function test_renewal_extends_from_current_expiry_and_sweep_downgrades(): void
    {
        $o = $this->order();
        app(BillingService::class)->settle($o);
        $first = $this->org->refresh()->plan_expires_at;

        $o2 = $this->order();
        app(BillingService::class)->settle($o2);
        $this->assertEqualsWithDelta($first->copy()->addMonths(2)->timestamp, $this->org->refresh()->plan_expires_at->timestamp, 5);

        Notification::fake();
        $this->org->update(['plan_expires_at' => now()->subMinute()]);
        app(BillingService::class)->sweepExpirations();
        $this->assertSame('free', $this->org->refresh()->plan);
        $this->assertSame(100, $this->org->daily_limit);
        Notification::assertSentTo($this->user, PlanExpiredNotification::class);
    }

    public function test_simulator_only_without_xendit_key_and_outside_production(): void
    {
        config(['postivo.billing.xendit_secret_key' => null, 'postivo.billing.allow_simulator' => true]);
        $o = app(BillingService::class)->checkout($this->org, $this->user, 'growth');
        $this->assertNull($o->invoice_url);

        $this->actingAs($this->user)->post("/console/billing/orders/{$o->id}/simulate")->assertRedirect();
        $this->assertSame('growth', $this->org->refresh()->plan);

        config(['postivo.billing.allow_simulator' => false]);
        $this->actingAs($this->user)->post("/console/billing/orders/{$o->id}/simulate")->assertNotFound();
    }
}
