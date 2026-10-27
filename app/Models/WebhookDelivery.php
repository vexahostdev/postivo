<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookDelivery extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['next_attempt_at' => 'datetime'];
    }
}
