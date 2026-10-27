<?php

namespace App\Models;

use App\Enums\DispatchStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailDispatch extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => DispatchStatus::class,
            'next_attempt_at' => 'datetime',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'first_opened_at' => 'datetime',
            'first_clicked_at' => 'datetime',
        ];
    }

    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(EmailEvent::class, 'dispatch_id')->orderBy('occurred_at');
    }
}
