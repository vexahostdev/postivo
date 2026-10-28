<?php

namespace App\Models;

use App\Support\XenditPayload;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['processed_at' => 'datetime'];
    }

    protected function payload(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => XenditPayload::only(json_decode($value ?? '{}', true, 512, JSON_THROW_ON_ERROR)),
            set: fn (array $value) => json_encode(XenditPayload::only($value), JSON_THROW_ON_ERROR),
        );
    }
}
