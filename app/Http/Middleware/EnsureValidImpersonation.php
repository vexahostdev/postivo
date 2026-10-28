<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureValidImpersonation
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! $request->session()->exists('impersonator_id')) {
            return $next($request);
        }

        $adminId = $request->session()->get('impersonator_id');
        $admin = is_string($adminId) ? User::find($adminId) : null;
        $passwordHash = $request->session()->get('impersonator_password_hash');
        if (! $admin || ! $admin->staffCan('impersonate') || ! is_string($passwordHash)
            || ! hash_equals(Auth::guard()->hashPasswordForCookie($admin->getAuthPassword()), $passwordHash)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        // Berlaku di seluruh web, termasuk undangan dan kirim ulang verifikasi.
        $canExit = $request->isMethod('POST') && $request->routeIs('logout', 'impersonate.stop');
        abort_unless(
            in_array($request->method(), ['GET', 'HEAD'], true) || $canExit,
            403,
            'Mode impersonasi hanya-baca. Keluar dari impersonasi untuk mengubah data.'
        );

        return $next($request);
    }
}
