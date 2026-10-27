<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ApiKey extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $guarded = [];

    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'domain_ids' => 'array',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function can(string $scope): bool
    {
        return in_array('*', $this->scopes ?? [], true) || in_array($scope, $this->scopes ?? [], true);
    }

    public function allowsDomain(string $domainId): bool
    {
        return empty($this->domain_ids) || in_array($domainId, $this->domain_ids, true);
    }
}
