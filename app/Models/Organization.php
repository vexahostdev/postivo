<?php

namespace App\Models;

use App\Enums\MemberRole;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Notification;

class Organization extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['plan_expires_at' => 'datetime'];
    }

    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    public function isSuspended(): bool
    {
        return $this->status === 'SUSPENDED';
    }

    /** Kirim notifikasi ke owner & admin organisasi. */
    public function notifyAdmins(mixed $notification): void
    {
        $users = User::whereIn('id', Member::where('organization_id', $this->id)
            ->whereIn('role', [MemberRole::OWNER->value, MemberRole::ADMIN->value])->pluck('user_id'))->get();

        Notification::send($users, $notification);
    }
}
