<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Header keamanan untuk semua respons web. CSP diatur lewat POSTIVO_CSP: off | report | enforce
 * (default report agar tidak memutus halaman; set enforce di production setelah diverifikasi).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $h = $response->headers;

        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $h->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        if (! $h->has('X-Frame-Options')) {
            $h->set('X-Frame-Options', 'SAMEORIGIN');
        }
        if ($request->isSecure() && app()->environment('production')) {
            $h->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        $mode = (string) config('postivo.csp', 'report');
        if ($mode !== 'off' && ! app()->environment('local')) {
            // Alpine membutuhkan 'unsafe-eval'; style inline dipakai komponen Blade.
            $csp = "default-src 'self'; script-src 'self' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'";
            $h->set($mode === 'enforce' ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only', $csp);
        }

        return $response;
    }
}
