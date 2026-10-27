<?php

namespace App\Services;

use App\Models\Suppression;
use App\Support\Addr;

class SuppressionService
{
    public function isSuppressed(string $orgId, string $email): bool
    {
        return Suppression::withoutGlobalScopes()
            ->where('organization_id', $orgId)->where('email_hash', Addr::hash($email))->exists();
    }

    public function add(string $orgId, string $email, string $reason, ?string $dispatchId = null, ?string $note = null): Suppression
    {
        return Suppression::withoutGlobalScopes()->firstOrCreate(
            ['organization_id' => $orgId, 'email_hash' => Addr::hash($email)],
            ['email' => Addr::normalize($email), 'reason' => $reason, 'source_dispatch_id' => $dispatchId, 'note' => $note, 'created_at' => now()],
        );
    }
}
