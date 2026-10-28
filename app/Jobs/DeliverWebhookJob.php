<?php

namespace App\Jobs;

use App\Models\EmailEvent;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\WebhookDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Throwable;

class DeliverWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $deliveryId) {}

    public function handle(WebhookDispatcher $wh): void
    {
        $delivery = WebhookDelivery::find($this->deliveryId);
        $endpoint = $delivery ? WebhookEndpoint::withoutGlobalScope('organization')->find($delivery->endpoint_id) : null;
        $event = $delivery ? EmailEvent::withoutGlobalScopes()->find($delivery->event_id) : null;
        if (
            ! $delivery || ! $endpoint || ! $event || ! $endpoint->is_active
            || $endpoint->organization_id !== $event->organization_id
        ) {
            return;
        }

        $body = json_encode($wh->payload($event), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $t = time();
        $attempt = $delivery->attempt + 1;
        $started = microtime(true);
        $status = null;
        $snippet = null;
        $ok = false;

        $ip = $wh->resolveSafeIp($endpoint->url);
        if ($ip === null) {
            $snippet = 'blocked: target not publicly routable';
        } else {
            try {
                $p = parse_url($endpoint->url);
                $port = $p['port'] ?? ($p['scheme'] === 'https' ? 443 : 80);
                $res = Http::timeout(config('postivo.webhook.timeout'))
                    ->withOptions(['allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => ["{$p['host']}:{$port}:{$ip}"]]])
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'User-Agent' => 'Postivo-Webhook/1.0',
                        'Postivo-Event-Id' => $event->public_id,
                        'Postivo-Signature' => $wh->sign($endpoint->secret, $t, $body),
                    ])->withBody($body, 'application/json')->post($endpoint->url);
                $status = $res->status();
                $snippet = mb_substr($res->body(), 0, 500);
                $ok = $res->successful();
            } catch (Throwable $e) {
                $snippet = mb_substr($e->getMessage(), 0, 500);
            }
        }

        $backoff = config('postivo.webhook.retry_backoff');
        $retry = ! $ok && $attempt <= count($backoff);
        $delivery->update([
            'attempt' => $attempt,
            'status' => $ok ? 'SUCCESS' : ($retry ? 'PENDING' : 'FAILED'),
            'http_status' => $status,
            'response_snippet' => $snippet,
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            'next_attempt_at' => $retry ? now()->addSeconds($backoff[$attempt - 1]) : null,
        ]);

        if ($ok) {
            $endpoint->update(['consecutive_failures' => 0]);
        } else {
            $endpoint->increment('consecutive_failures');
            if ($endpoint->consecutive_failures >= config('postivo.webhook.disable_after')) {
                $endpoint->update(['is_active' => false, 'disabled_at' => now()]);
                \App\Models\Organization::find($endpoint->organization_id)?->notifyAdmins(new \App\Notifications\WebhookDisabledNotification($endpoint));
            }
            if ($retry) {
                self::dispatch($delivery->id)->onQueue('postivo_webhooks')->delay($backoff[$attempt - 1])->afterCommit();
            }
        }
    }
}
