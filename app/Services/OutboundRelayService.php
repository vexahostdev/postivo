<?php

namespace App\Services;

use App\Exceptions\RelayException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Message;

class OutboundRelayService
{
    /**
     * @return array{relay: string, response: string}
     *
     * @throws RelayException
     */
    public function send(Message $signed, string $returnPath, string $recipient, string $dispatchId): array
    {
        $cfg = config('postivo.relay');
        if ($cfg['driver'] === 'log') {
            Storage::disk('local')->put("mail/outbox/{$dispatchId}.eml", $signed->toString());

            return ['relay' => 'LOG', 'response' => '250 2.0.0 OK (log relay)'];
        }

        $envelope = new Envelope(new Address($returnPath), [new Address($recipient)]);
        $order = array_filter([
            'STALWART' => $cfg['stalwart_dsn'] ?? null,
            'BREVO' => $cfg['brevo_dsn'] ?? null,
        ]);

        $last = null;
        foreach ($order as $name => $dsn) {
            if ($this->breakerOpen($name)) {
                continue;
            }
            try {
                Transport::fromDsn($dsn)->send($signed, $envelope);
                $this->breakerReset($name);

                return ['relay' => $name, 'response' => '250 2.0.0 OK'];
            } catch (TransportExceptionInterface $e) {
                $code = preg_match('/\b([245]\d{2})\b/', $e->getMessage(), $m) ? (int) $m[1] : null;
                // Error permanen = bounce nyata, bukan alasan failover.
                if ($code !== null && $code >= 500) {
                    throw new RelayException($e->getMessage(), false, $code);
                }
                $this->breakerFail($name);
                $last = new RelayException($e->getMessage(), true, $code);
            }
        }

        throw $last ?? new RelayException('No relay available (all circuits open or unconfigured).', true);
    }

    private function breakerOpen(string $relay): bool
    {
        return Cache::has("postivo:breaker:$relay:open");
    }

    private function breakerFail(string $relay): void
    {
        $cfg = config('postivo.relay');
        $key = "postivo:breaker:$relay:fails";
        Cache::add($key, 0, $cfg['breaker_window']);
        if (Cache::increment($key) >= $cfg['breaker_failures']) {
            Cache::put("postivo:breaker:$relay:open", 1, $cfg['breaker_open_seconds']);
            Cache::forget($key);
        }
    }

    private function breakerReset(string $relay): void
    {
        Cache::forget("postivo:breaker:$relay:fails");
    }
}
