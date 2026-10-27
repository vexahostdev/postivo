<?php

namespace App\Enums;

/** Peran staf platform (ADMIN_PANEL_SPEC §2–3). */
enum StaffRole: string
{
    case SUPERADMIN = 'superadmin';
    case SUPPORT = 'support';
    case FINANCE = 'finance';

    public const PERMISSIONS = [
        'superadmin' => ['*'],
        'support' => ['view', 'impersonate', 'org.suspend', 'org.note', 'user.manage', 'domain.manage', 'blocklist.manage'],
        'finance' => ['view', 'org.plan', 'org.note', 'billing.manage'],
    ];

    public function can(string $permission): bool
    {
        $p = self::PERMISSIONS[$this->value];

        return in_array('*', $p, true) || in_array($permission, $p, true);
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
