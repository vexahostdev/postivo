<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Enums\StaffRole;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasUuids, Notifiable;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime', 'password' => 'hashed', 'platform_role' => StaffRole::class,
            'disabled_at' => 'datetime', 'two_factor_secret' => 'encrypted', 'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function isStaff(): bool
    {
        return $this->platform_role !== null && $this->disabled_at === null;
    }

    public function staffCan(string $permission): bool
    {
        return $this->isStaff() && $this->platform_role->can($permission);
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    public function twoFactorFingerprint(): ?string
    {
        if (! $this->hasTwoFactor()) {
            return null;
        }

        return hash_hmac('sha256', $this->getAuthIdentifier().'|'.$this->two_factor_secret, (string) config('app.key'));
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_members')->withPivot('role');
    }
}
