<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Gerbang /admin: staf aktif, allowlist IP opsional, 2FA TOTP (ADMIN_PANEL_SPEC §7). */
class EnsureStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user && $user->isStaff() && ! session('impersonator_id'), 404); // sembunyikan keberadaan panel

        $allow = array_filter(array_map('trim', explode(',', (string) config('postivo.admin.ip_allowlist'))));
        abort_if($allow && ! in_array($request->ip(), $allow, true), 404);

        if (config('postivo.admin.require_2fa')) {
            $onSecurity = $request->routeIs('admin.security*', 'admin.two-factor*');
            if (! $user->hasTwoFactor() && ! $onSecurity) {
                return redirect()->route('admin.security')->with('error', 'Aktifkan 2FA untuk mengakses panel admin.');
            }
            $ok = (int) session('admin_2fa_at', 0);
            if ($user->hasTwoFactor() && (time() - $ok) > config('postivo.admin.session_minutes') * 60 && ! $request->routeIs('admin.two-factor*')) {
                session(['admin_intended' => $request->fullUrl()]);

                return redirect()->route('admin.two-factor');
            }
        }

        // Idle timeout bergeser: setiap aktivitas memperpanjang sesi terverifikasi.
        if (session('admin_2fa_at') && ! $request->routeIs('admin.two-factor*')) {
            session(['admin_2fa_at' => time()]);
        }

        return $next($request);
    }
}
