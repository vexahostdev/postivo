<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WebhookEndpoint extends Model
{
    use BelongsToOrganization, HasUuids, SoftDeletes;

    protected $guarded = [];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['secret' => 'encrypted', 'events' => 'array', 'is_active' => 'boolean', 'disabled_at' => 'datetime'];
    }

    public function subscribesTo(string $event): bool
    {
        return in_array('*', $this->events, true) || in_array($event, $this->events, true);
    }
}
