<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

abstract class AdminController extends Controller
{
    /** Tolak bila peran staf tidak punya izin (ADMIN_PANEL_SPEC §3). */
    protected function need(string $permission): void
    {
        abort_unless(auth()->user()?->staffCan($permission), 403, "Peran Anda tidak memiliki izin '{$permission}'.");
    }

    /** Validasi alasan wajib untuk aksi sensitif. */
    protected function reason(\Illuminate\Http\Request $r, int $min = 5): string
    {
        return $r->validate(['reason' => ['required', 'string', "min:$min", 'max:500']])['reason'];
    }
}
