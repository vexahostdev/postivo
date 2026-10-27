<?php

namespace App\Http\Middleware;

use App\Enums\MemberRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRole
{
    public function handle(Request $request, Closure $next, string $min): Response
    {
        $role = $request->attributes->get('role');
        abort_unless($role instanceof MemberRole && $role->atLeast(MemberRole::from($min)), 403, 'Peran Anda tidak memiliki izin untuk aksi ini.');

        return $next($request);
    }
}
