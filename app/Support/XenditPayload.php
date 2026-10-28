<?php

namespace App\Support;

use Illuminate\Support\Arr;

class XenditPayload
{
    public const RULES = [
        'id' => ['sometimes', 'nullable', 'string', 'max:64'],
        'external_id' => ['sometimes', 'nullable', 'string', 'max:100'],
        'status' => ['sometimes', 'nullable', 'string', 'max:30'],
        'amount' => ['sometimes', 'nullable', 'integer', 'min:0'],
        'paid_amount' => ['sometimes', 'nullable', 'integer', 'min:0'],
        'payment_channel' => ['sometimes', 'nullable', 'string', 'max:40'],
        'payment_method' => ['sometimes', 'nullable', 'string', 'max:40'],
    ];

    public static function only(array $payload): array
    {
        return array_filter(Arr::only($payload, array_keys(self::RULES)), fn ($value) => is_scalar($value) || $value === null);
    }
}
