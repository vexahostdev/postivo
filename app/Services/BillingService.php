<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\InvoiceCreatedNotification;
use App\Notifications\PaymentReceivedNotification;
use App\Notifications\PlanExpiredNotification;
use App\Notifications\PlanExpiringNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BillingService
{
    public function __construct(private XenditService $xendit) {}

    public function plan(string $key): array
    {
        $plan = config("postivo.plans.$key");
        if (! $plan || $plan['price'] <= 0) {
            abort(404, 'Paket tidak tersedia untuk dibeli.');
        }

        return $plan;
    }

    /** Buat order + invoice Xendit. Order tetap dibuat walau gateway gagal agar bisa dicoba ulang. */
    public function checkout(Organization $org, User $user, string $planKey, int $months = 1): Order
    {
        $plan = $this->plan($planKey);
        $months = max(1, min(12, $months));

        $order = Order::withoutGlobalScopes()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'plan' => $planKey,
            'months' => $months,
            'amount' => $plan['price'] * $months,
            'external_id' => config('postivo.billing.external_prefix').'ORD-'.strtoupper((string) Str::ulid()),
            'expires_at' => now()->addSeconds(config('postivo.billing.invoice_duration')),
        ]);

        $res = $this->xendit->createInvoice($order, $user);
        if ($res['success']) {
            $order->update(['xendit_invoice_id' => $res['invoice_id'], 'invoice_url' => $res['invoice_url']]);
            $user->notify(new InvoiceCreatedNotification($order->refresh()));
        } else {
            $order->update(['raw_payload' => ['create_error' => $res['error']]]);
        }

        return $order;
    }

    /** Tandai lunas + aktifkan paket. Idempoten: panggilan kedua tidak berefek. */
    public function settle(Order $order, ?string $method = null, array $payload = []): bool
    {
        $activated = DB::transaction(function () use ($order, $method, $payload) {
            $locked = Order::withoutGlobalScopes()->lockForUpdate()->find($order->id);
            if (! $locked || $locked->isPaid()) {
                return false;
            }
            $locked->update(['status' => 'PAID', 'paid_at' => now(), 'payment_method' => $method ?? $locked->payment_method, 'raw_payload' => $payload ?: $locked->raw_payload]);

            $org = Organization::findOrFail($locked->organization_id);
            $plan = config("postivo.plans.{$locked->plan}");
            // Perpanjangan paket yang sama menyambung dari masa aktif; ganti paket mulai dari sekarang.
            $base = $org->plan === $locked->plan && $org->plan_expires_at?->isFuture() ? $org->plan_expires_at : now();
            $org->update([
                'plan' => $locked->plan, 'monthly_quota' => $plan['monthly_quota'], 'daily_limit' => $plan['daily_limit'],
                'plan_expires_at' => $base->copy()->addMonths($locked->months),
            ]);

            return true;
        });

        if ($activated) {
            $order->refresh();
            $org = Organization::find($order->organization_id);
            $org->notifyAdmins(new PaymentReceivedNotification($order));
        }

        return $activated;
    }

    public function markUnpaid(Order $order, string $status): void
    {
        Order::withoutGlobalScopes()->whereKey($order->id)->where('status', 'PENDING')->update(['status' => $status]);
    }

    /** Dipanggil scheduler harian: peringatan 7 & 1 hari, lalu turunkan ke Free saat habis. */
    public function sweepExpirations(): array
    {
        $warned = $expired = 0;
        Organization::query()->where('plan', '!=', 'free')->whereNotNull('plan_expires_at')->each(function (Organization $org) use (&$warned, &$expired) {
            if ($org->plan_expires_at->isPast()) {
                $free = config('postivo.plans.free');
                $old = $org->plan;
                $org->update(['plan' => 'free', 'monthly_quota' => $free['monthly_quota'], 'daily_limit' => $free['daily_limit'], 'plan_expires_at' => null]);
                $org->notifyAdmins(new PlanExpiredNotification($org, $old));
                $expired++;

                return;
            }
            $days = (int) ceil(now()->floatDiffInDays($org->plan_expires_at));
            foreach ([7, 1] as $threshold) {
                if ($days <= $threshold && Cache::add("postivo:expiring:{$org->id}:{$org->plan_expires_at->timestamp}:$threshold", 1, now()->addDays(10))) {
                    $org->notifyAdmins(new PlanExpiringNotification($org, $days));
                    $warned++;
                    break;
                }
            }
        });

        return compact('warned', 'expired');
    }

    /** Kedaluwarsakan order PENDING yang lewat batas waktu invoice. */
    public function expirePending(): int
    {
        return Order::withoutGlobalScopes()->where('status', 'PENDING')->where('expires_at', '<', now())->update(['status' => 'EXPIRED']);
    }
}
