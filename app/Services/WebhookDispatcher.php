<?php

namespace App\Services;

use App\Jobs\DeliverWebhookJob;
use App\Models\EmailEvent;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;

class WebhookDispatcher
{
    public function fanOut(EmailEvent $event): void
    {
        $type = 'email.'.$event->type;

        WebhookEndpoint::withoutGlobalScopes()
            ->where('organization_id', $event->organization_id)
            ->where('is_active', true)
            ->get()
            ->filter(fn (WebhookEndpoint $e) => $e->subscribesTo($type))
            ->each(function (WebhookEndpoint $e) use ($event) {
                $delivery = WebhookDelivery::create([
                    'endpoint_id' => $e->id,
                    'event_id' => $event->id,
                    'event_public_id' => $event->public_id,
                    'status' => 'PENDING',
                ]);
                DeliverWebhookJob::dispatch($delivery->id)->onQueue('postivo_webhooks');
            });
    }

    /** Payload sesuai PRD §8.7. */
    public function payload(EmailEvent $event): array
    {
        $d = $event->dispatch()->withoutGlobalScopes()->first();
        $email = $d?->email()->withoutGlobalScopes()->first();

        return [
            'id' => 'evt_'.$event->public_id,
            'type' => 'email.'.$event->type,
            'created_at' => $event->occurred_at->utc()->toIso8601ZuluString(),
            'data' => [
                'email_id' => $event->email_id,
                'dispatch_id' => $event->dispatch_id,
                'recipient' => $d?->recipient,
                'domain' => $email?->domain()->withoutGlobalScopes()->value('domain_name'),
                'tags' => $email?->tags ?? [],
                'metadata' => $email?->metadata ?? (object) [],
                'smtp_response' => $d?->smtp_response,
                'bounce' => $d?->bounce_type ? ['type' => $d->bounce_type] : null,
            ],
        ];
    }

    public function sign(string $secret, int $t, string $body): string
    {
        return 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$body, $secret);
    }

    /** Tolak target non-publik (SSRF). Mengembalikan IP yang di-pin atau null bila tidak aman. */
    public function resolveSafeIp(string $url): ?string
    {
        $p = parse_url($url);
        if (! $p || ! in_array($p['scheme'] ?? '', ['https', 'http'], true) || empty($p['host'])) {
            return null;
        }
        $ips = filter_var($p['host'], FILTER_VALIDATE_IP) ? [$p['host']] : (gethostbynamel($p['host']) ?: []);
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                // Hanya untuk pengembangan lokal, dan harus dinyalakan eksplisit (default mati).
                return config('postivo.webhook.allow_local') && in_array($ip, ['127.0.0.1', '::1'], true) ? $ip : null;
            }
        }

        return $ips[0] ?? null;
    }
}
