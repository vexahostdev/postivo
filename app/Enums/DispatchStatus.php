<?php

namespace App\Enums;

enum DispatchStatus: string
{
    case QUEUED = 'QUEUED';
    case SENDING = 'SENDING';
    case DEFERRED = 'DEFERRED';
    case SENT = 'SENT';
    case DELIVERED = 'DELIVERED';
    case BOUNCED = 'BOUNCED';
    case COMPLAINED = 'COMPLAINED';
    case FAILED = 'FAILED';
    case SUPPRESSED = 'SUPPRESSED';

    /** Peringkat agar event telat tidak memundurkan status (BACKEND_SPEC §3). */
    public function rank(): int
    {
        return match ($this) {
            self::QUEUED => 0,
            self::SENDING, self::DEFERRED => 1,
            self::SENT => 2,
            self::DELIVERED => 3,
            self::COMPLAINED => 4,
            self::BOUNCED, self::FAILED, self::SUPPRESSED => 5,
        };
    }

    public function isFailure(): bool
    {
        return in_array($this, [self::BOUNCED, self::FAILED, self::SUPPRESSED], true);
    }

    public function label(): string
    {
        return ucfirst(strtolower($this->value));
    }
}
