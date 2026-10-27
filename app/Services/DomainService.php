<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DomainService
{
    public function create(Organization $org, string $name): Domain
    {
        $name = strtolower(trim($name));
        if (function_exists('idn_to_ascii')) {
            $name = idn_to_ascii($name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $name;
        }

        [$private, $der] = $this->generateKeypair();

        return DB::transaction(function () use ($org, $name, $private, $der) {
            $domain = Domain::withoutGlobalScopes()->create([
                'organization_id' => $org->id,
                'domain_name' => $name,
                'status' => 'PENDING',
                'dkim_selector' => config('postivo.dns.dkim_selector'),
                'dkim_private_key' => $private,
                'dkim_public_key' => $der,
                'return_path_host' => 'bounces.'.$name,
                'audit_started_at' => now(),
            ]);

            foreach ($this->expectedRecords($domain) as $purpose => $r) {
                $domain->records()->create(['purpose' => $purpose, 'is_required' => $purpose !== 'dmarc' && $purpose !== 'tracking'] + $r);
            }

            return $domain;
        });
    }

    /** @return array{0: string, 1: string} [PEM private, base64 DER public] */
    private function generateKeypair(): array
    {
        $opts = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $res = openssl_pkey_new($opts);

        // Windows tanpa OPENSSL_CONF: coba openssl.cnf bawaan PHP/Git.
        if (! $res) {
            $candidates = array_filter([
                env('POSTIVO_OPENSSL_CONF'),
                dirname(PHP_BINARY).'/extras/openssl/openssl.cnf',
                dirname(PHP_BINARY).'/extras/ssl/openssl.cnf',
                'C:/Program Files/Git/mingw64/etc/ssl/openssl.cnf',
            ]);
            foreach ($candidates as $cnf) {
                if (is_file($cnf) && ($res = openssl_pkey_new($opts + ['config' => $cnf]))) {
                    $opts['config'] = $cnf;
                    break;
                }
            }
        }
        if (! $res) {
            throw new RuntimeException('Gagal membuat kunci DKIM: '.openssl_error_string());
        }

        openssl_pkey_export($res, $private, null, array_intersect_key($opts, ['config' => 1]));
        $der = preg_replace('/-----[^-]+-----|\s/', '', openssl_pkey_get_details($res)['key']);

        return [$private, $der];
    }

    /** @return array<string, array{type: string, name: string, value: string}> */
    public function expectedRecords(Domain $d): array
    {
        $cfg = config('postivo.dns');

        return [
            'dkim' => ['type' => 'TXT', 'name' => $d->dkim_selector.'._domainkey.'.$d->domain_name, 'value' => 'v=DKIM1; k=rsa; p='.$d->dkim_public_key],
            'spf' => ['type' => 'TXT', 'name' => $d->domain_name, 'value' => 'v=spf1 include:'.$cfg['spf_include'].' ~all'],
            'return_path' => ['type' => 'CNAME', 'name' => $d->return_path_host, 'value' => $cfg['bounce_target']],
            'dmarc' => ['type' => 'TXT', 'name' => '_dmarc.'.$d->domain_name, 'value' => 'v=DMARC1; p=none; rua=mailto:'.$cfg['dmarc_rua']],
            'tracking' => ['type' => 'CNAME', 'name' => 'trk.'.$d->domain_name, 'value' => $cfg['tracking_target']],
        ];
    }
}
