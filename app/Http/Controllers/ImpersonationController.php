<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AdminAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class ImpersonationController extends Controller
{
    public function stop(): RedirectResponse
    {
        $adminId = session('impersonator_id');
        abort_unless($adminId, 404);
        $admin = User::find($adminId);
        $orgId = session('org_id');
        AdminAudit::log('impersonate.stop', 'organization', $orgId, $orgId, null, [], $adminId);

        session()->forget(['impersonator_id', 'org_id']);
        abort_unless($admin && $admin->isStaff(), 403);
        Auth::login($admin);

        return redirect()->route('admin.organizations.show', $orgId);
    }
}
