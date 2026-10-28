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
        abort_unless(session()->exists('impersonator_id'), 404);
        $adminId = session('impersonator_id');
        $admin = is_string($adminId) ? User::find($adminId) : null;
        $passwordHash = session('impersonator_password_hash');
        if (! $admin || ! $admin->staffCan('impersonate') || ! is_string($passwordHash)
            || ! hash_equals(Auth::guard()->hashPasswordForCookie($admin->getAuthPassword()), $passwordHash)) {
            Auth::logout();
            session()->invalidate();
            session()->regenerateToken();

            return redirect()->route('login');
        }

        $orgId = session('org_id');
        AdminAudit::log('impersonate.stop', 'organization', $orgId, $orgId, null, [], $adminId);

        session()->forget(['impersonator_id', 'impersonator_password_hash', 'org_id']);
        Auth::login($admin);

        return redirect()->route('admin.organizations.show', $orgId);
    }
}
