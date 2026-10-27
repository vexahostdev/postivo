<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailEvent extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'array', 'occurred_at' => 'datetime', 'created_at' => 'datetime', 'is_bot' => 'boolean'];
    }

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(EmailDispatch::class, 'dispatch_id');
    }
}
