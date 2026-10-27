<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Email extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'tags' => 'array',
            'metadata' => 'array',
            'track_opens' => 'boolean',
            'track_clicks' => 'boolean',
            'scheduled_at' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }

    public function dispatches(): HasMany
    {
        return $this->hasMany(EmailDispatch::class)->orderBy('id');
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(EmailEvent::class);
    }
}
