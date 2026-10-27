<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Domain extends Model
{
    use BelongsToOrganization, HasUuids, SoftDeletes;

    protected $guarded = [];

    protected $hidden = ['dkim_private_key'];

    protected function casts(): array
    {
        return [
            'dkim_private_key' => 'encrypted',
            'track_opens_default' => 'boolean',
            'track_clicks_default' => 'boolean',
            'verified_at' => 'datetime',
            'last_audit_at' => 'datetime',
            'audit_started_at' => 'datetime',
        ];
    }

    public function records(): HasMany
    {
        return $this->hasMany(DnsRecord::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'ACTIVE';
    }
}
