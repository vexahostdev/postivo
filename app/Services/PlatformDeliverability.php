<?php

namespace App\Services;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Diagnostik relay & DNS milik platform sendiri (bukan domain pelanggan):
 * dipakai halaman Admin → Alat & diagnostik untuk memastikan jalur kirim siap sebelum peluncuran.
 */
class PlatformDeliverability
{
    public function __construct(private DnsAuditorService $dns) {}

    /** @return array<string, array{configured: bool, target: ?string}> */
    public function relays(): array
    {
        $cfg = config('postivo.relay');
        $mail = config('mail.mailers.'.config('mail.default'), []);

        return [
            'STALWART' => $this->describe($cfg['stalwart_dsn'] ?? null),
            'BREVO' => $this->describe($cfg['brevo_dsn'] ?? null),
            'SYSTEM' => ['configured' => ($mail['transport'] ?? null) === 'smtp', 'target' => isset($mail['host']) ? $mail['host'].':'.($mail['port'] ?? '') : (string) config('mail.default')],
        ];
    }

    /** Buka koneksi + STARTTLS + AUTH tanpa mengirim email. @return array{ok: bool, ms: int, message: string} */
    public function testConnection(string $relay): array
    {
        $t0 = microtime(true);
        try {
            $transport = $this->transport($relay);
            if (! $transport instanceof SmtpTransport) {
                return ['ok' => false, 'ms' => 0, 'message' => 'Relay ini tidak memakai SMTP (mis. driver log) — tidak ada koneksi yang bisa diuji.'];
            }
            $transport->start();
            $transport->stop();

            return ['ok' => true, 'ms' => $this->ms($t0), 'message' => 'Koneksi, STARTTLS, dan AUTH berhasil. Tidak ada email dikirim.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'ms' => $this->ms($t0), 'message' => $this->clean($e->getMessage())];
        }
    }

    /** Kirim satu email uji lewat relay terpilih. @return array{ok: bool, ms: int, message: string} */
    public function sendTest(string $relay, string $to, string $by): array
    {
        $from = (string) config('mail.from.address');
        $mail = (new Email)
            ->from(new Address($from, (string) config('mail.from.name')))
            ->to($to)
            ->subject('Uji relay '.$relay.' — '.\App\Support\Brand::name())
            ->text("Email uji dari panel admin.\n\nRelay : $relay\nWaktu : ".now()->toDateTimeString()."\nOleh  : $by\n\nJika Anda menerima ini di Inbox (bukan Spam), jalur kirim berfungsi.\n");

        $t0 = microtime(true);
        try {
            $this->transport($relay)->send($mail, new Envelope(new Address($from), [new Address($to)]));

            return ['ok' => true, 'ms' => $this->ms($t0), 'message' => "Diterima relay $relay untuk dikirim ke $to. Cek inbox dan header (SPF/DKIM/DMARC = pass)."];
        } catch (Throwable $e) {
            return ['ok' => false, 'ms' => $this->ms($t0), 'message' => $this->clean($e->getMessage())];
        }
    }

    /**
     * Cek DNS domain pengirim platform. Setiap baris: label, record, ditemukan, status (ok|warn|fail|unknown), saran.
     *
     * @return list<array{label: string, record: string, found: string, status: string, hint: string}>
     */
    public function dnsChecks(): array
    {
        $domain = (string) config('postivo.hosts.app');
        $c = config('postivo.dns');
        $smtpHost = (string) config('postivo.smtp.host');
        $bounce = (string) $c['bounce_target'];
        $trk = (string) $c['tracking_target'];
        $spfHost = (string) $c['spf_include'];

        $q = [
            ['TXT', $domain], ['TXT', '_dmarc.'.$domain], ['TXT', $spfHost],
            ['A', $smtpHost], ['MX', $bounce], ['A', $bounce], ['A', $trk], ['CNAME', $trk],
            ['CNAME', 'brevo1._domainkey.'.$domain], ['TXT', 'brevo-code.'.$domain],
            ['TXT', ($c['dkim_selector'] ?? 'postivo').'._domainkey.'.$domain],
        ];
        $r = $this->dns->lookupMany($q);
        $get = fn (string $t, string $n) => $r["$t|$n"] ?? null;
        $join = fn (?array $v) => $v === null ? 'resolver tidak terjangkau' : ($v ? implode(' | ', array_map(fn ($x) => mb_strimwidth($x, 0, 140, '…'), $v)) : '(kosong)');
        $row = fn (string $label, string $record, ?array $v, bool $ok, string $hint, string $miss = 'fail') => [
            'label' => $label, 'record' => $record, 'found' => $join($v),
            'status' => $v === null ? 'unknown' : ($ok ? 'ok' : $miss), 'hint' => $hint,
        ];

        $txt = $get('TXT', $domain);
        $spf = collect($txt)->first(fn ($x) => str_starts_with(strtolower(trim($x, '" ')), 'v=spf1'));
        $dmarc = collect($get('TXT', '_dmarc.'.$domain))->first(fn ($x) => str_starts_with(strtolower(trim($x, '" ')), 'v=dmarc1'));
        $smtpA = $get('A', $smtpHost);
        $ptr = $this->ptr($smtpA[0] ?? null);

        return [
            $row('SPF domain pengirim', "TXT $domain", $txt, (bool) $spf, 'Harus ada satu record v=spf1 yang menyertakan relay (mis. include:spf.brevo.com) dan diakhiri ~all atau -all.'),
            $row('DMARC', "TXT _dmarc.$domain", $get('TXT', '_dmarc.'.$domain), (bool) $dmarc, 'Mulai dengan v=DMARC1; p=none; rua=mailto:'.$c['dmarc_rua'].' lalu naikkan ke quarantine/reject setelah laporan bersih.'),
            $row('SPF include pelanggan', "TXT $spfHost", $get('TXT', $spfHost), collect($get('TXT', $spfHost))->contains(fn ($x) => str_contains(strtolower($x), 'v=spf1')), 'Dirujuk record SPF pelanggan (include:'.$spfHost.'); isi dengan IP/relay Postivo.'),
            $row('DKIM Postivo', "TXT {$c['dkim_selector']}._domainkey.$domain", $get('TXT', ($c['dkim_selector'] ?? 'postivo').'._domainkey.'.$domain), collect($get('TXT', ($c['dkim_selector'] ?? 'postivo').'._domainkey.'.$domain))->contains(fn ($x) => str_contains($x, 'p=')), 'Untuk email sistem yang ditandatangani sendiri. Opsional bila memakai DKIM Brevo.', 'warn'),
            $row('DKIM Brevo', "CNAME brevo1._domainkey.$domain", $get('CNAME', 'brevo1._domainkey.'.$domain), ! empty($get('CNAME', 'brevo1._domainkey.'.$domain)), 'Diambil dari Brevo → Senders & IP → Domains. Tanpa ini email sistem lewat Brevo berisiko masuk spam.', 'warn'),
            $row('Verifikasi domain Brevo', "TXT brevo-code.$domain", $get('TXT', 'brevo-code.'.$domain), ! empty($get('TXT', 'brevo-code.'.$domain)), 'Record brevo-code dari halaman autentikasi domain Brevo.', 'warn'),
            $row('Host SMTP', "A $smtpHost", $smtpA, ! empty($smtpA), 'Harus mengarah ke server smtp-engine (port 587/465).'),
            $row('Reverse DNS (PTR)', $smtpA[0] ?? '—', $ptr['values'], $ptr['ok'], 'PTR IP pengirim harus mengarah ke hostname yang ber-A balik ke IP yang sama (forward-confirmed). Atur di panel penyedia VPS.', 'warn'),
            $row('Bounce / Return-Path', "MX $bounce", $get('MX', $bounce), ! empty($get('MX', $bounce)) || ! empty($get('A', $bounce)), 'Host VERP untuk Return-Path; harus ada MX atau A agar DSN bisa kembali.'),
            $row('Host tracking', "CNAME/A $trk", array_merge($get('CNAME', $trk) ?? [], $get('A', $trk) ?? []) ?: ($get('A', $trk) === null ? null : []), ! empty($get('CNAME', $trk)) || ! empty($get('A', $trk)), 'Dipakai link open/click pelanggan.'),
        ];
    }

    /** @return array{values: ?array, ok: bool} */
    private function ptr(?string $ip): array
    {
        if (! $ip || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return ['values' => [], 'ok' => false];
        }
        $name = implode('.', array_reverse(explode('.', $ip))).'.in-addr.arpa';
        $v = $this->dns->lookup('PTR', $name);

        return ['values' => $v, 'ok' => ! empty($v)];
    }

    private function transport(string $relay): \Symfony\Component\Mailer\Transport\TransportInterface
    {
        $dsn = match ($relay) {
            'STALWART' => config('postivo.relay.stalwart_dsn'),
            'BREVO' => config('postivo.relay.brevo_dsn'),
            'SYSTEM' => $this->systemDsn(),
            default => null,
        };
        if (! $dsn) {
            throw new \RuntimeException("Relay $relay belum dikonfigurasi (DSN kosong).");
        }

        return Transport::fromDsn($dsn);
    }

    private function systemDsn(): ?string
    {
        $m = config('mail.mailers.'.config('mail.default'), []);
        if (($m['transport'] ?? null) !== 'smtp' || empty($m['host'])) {
            return null;
        }
        $auth = ! empty($m['username']) ? rawurlencode($m['username']).':'.rawurlencode((string) ($m['password'] ?? '')).'@' : '';

        return 'smtp://'.$auth.$m['host'].':'.($m['port'] ?? 587);
    }

    /** @return array{configured: bool, target: ?string} */
    private function describe(?string $dsn): array
    {
        if (! $dsn) {
            return ['configured' => false, 'target' => null];
        }
        $p = parse_url($dsn);

        return ['configured' => true, 'target' => ($p['host'] ?? '?').':'.($p['port'] ?? '')];
    }

    private function ms(float $t0): int
    {
        return (int) round((microtime(true) - $t0) * 1000);
    }

    /** Jangan pernah membocorkan kredensial lewat pesan error. */
    private function clean(string $m): string
    {
        $m = preg_replace('/xsmtpsib-[A-Za-z0-9-]+|xkeysib-[A-Za-z0-9-]+/', '***', $m);

        return mb_strimwidth(preg_replace('#//[^/@\s]+:[^@\s]+@#', '//***:***@', $m), 0, 400, '…');
    }
}
