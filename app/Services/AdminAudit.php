<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AdminAudit
{
    /** Catat aksi admin. $admin null → diambil dari sesi; sistem (cron) → pakai AdminAudit::system(). */
    public static function log(string $action, ?string $targetType = null, ?string $targetId = null, ?string $orgId = null, ?string $reason = null, array $meta = [], ?string $adminId = null): AdminAuditLog
    {
        return AdminAuditLog::create([
            'admin_id' => $adminId ?? session('impersonator_id') ?? Auth::id(),
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'organization_id' => $orgId,
            'reason' => $reason ? mb_substr($reason, 0, 500) : null,
            'meta' => $meta ?: null,
            'ip' => Request::ip(),
            'user_agent' => mb_substr((string) Request::userAgent(), 0, 255),
        ]);
    }

    public static function system(string $action, ?string $targetType = null, ?string $targetId = null, ?string $orgId = null, ?string $reason = null, array $meta = []): AdminAuditLog
    {
        return AdminAuditLog::create([
            'admin_id' => null, 'action' => $action, 'target_type' => $targetType, 'target_id' => $targetId,
            'organization_id' => $orgId, 'reason' => $reason, 'meta' => $meta ?: null,
        ]);
    }
}
