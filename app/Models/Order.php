<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['raw_payload' => 'array', 'expires_at' => 'datetime', 'paid_at' => 'datetime', 'amount' => 'integer'];
    }

    public function isPaid(): bool
    {
        return $this->status === 'PAID';
    }

    public function planName(): string
    {
        return config("postivo.plans.{$this->plan}.name", ucfirst($this->plan));
    }
}
