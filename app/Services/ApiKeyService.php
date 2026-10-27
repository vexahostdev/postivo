<?php

namespace App\Services;

use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\User;

class ApiKeyService
{
    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public const ALL_SCOPES = ['emails:send', 'emails:read', 'domains:manage', 'suppressions:manage', 'webhooks:manage', 'api_keys:manage'];

    /** @return array{0: ApiKey, 1: string} model dan plaintext (hanya tampil sekali) */
    public function create(Organization $org, string $name, string $env = 'live', array $scopes = ['emails:send'], ?array $domainIds = null, ?User $by = null): array
    {
        $plain = 'psv_'.$env.'_'.$this->base62(32);

        $key = ApiKey::withoutGlobalScopes()->create([
            'organization_id' => $org->id,
            'name' => $name,
            'prefix' => substr($plain, 0, 13),
            'key_hash' => hash('sha256', $plain),
            'environment' => $env,
            'scopes' => $scopes,
            'domain_ids' => $domainIds ?: null,
            'created_by' => $by?->id,
        ]);

        return [$key, $plain];
    }

    public function findByPlain(string $plain): ?ApiKey
    {
        if (! preg_match('/^psv_(live|test)_[0-9A-Za-z]{32}$/', $plain)) {
            return null;
        }

        return ApiKey::withoutGlobalScopes()->where('key_hash', hash('sha256', $plain))->first();
    }

    private function base62(int $len): string
    {
        $out = '';
        $max = strlen(self::ALPHABET);
        while (strlen($out) < $len) {
            foreach (str_split(random_bytes($len * 2)) as $b) {
                // Rejection sampling agar distribusi seragam (tanpa modulo bias).
                if (ord($b) < 248 && strlen($out) < $len) {
                    $out .= self::ALPHABET[ord($b) % $max];
                }
            }
        }

        return $out;
    }
}
