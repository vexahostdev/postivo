<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Endpoint /internal/*: header X-Postivo-Signature = hex HMAC-SHA256(secret, "{ts}.{body}"), toleransi 5 menit. */
class VerifyInternalSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('postivo.internal_secret');
        $ts = (int) $request->header('X-Postivo-Timestamp');
        $sig = (string) $request->header('X-Postivo-Signature');

        $valid = $secret && abs(time() - $ts) <= 300
            && hash_equals(hash_hmac('sha256', $ts.'.'.$request->getContent(), $secret), $sig);
        abort_unless($valid, 401, 'Invalid signature.');

        return $next($request);
    }
}
