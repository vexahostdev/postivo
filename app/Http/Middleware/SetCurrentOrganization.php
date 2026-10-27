<?php

namespace App\Http\Middleware;

use App\Models\Member;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class SetCurrentOrganization
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $memberships = Member::with('organization')->where('user_id', $user->id)->get();
        $member = $memberships->firstWhere('organization_id', session('org_id')) ?? $memberships->first();

        if (! $member) {
            abort(403, 'Akun ini tidak tergabung di organisasi mana pun.');
        }

        session(['org_id' => $member->organization_id]);
        app()->instance('org.current', $member->organization);
        $request->attributes->set('org', $member->organization);
        $request->attributes->set('role', $member->role);
        View::share(['currentOrg' => $member->organization, 'currentRole' => $member->role, 'memberships' => $memberships]);

        return $next($request);
    }
}
