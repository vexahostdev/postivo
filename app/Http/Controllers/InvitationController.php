<?php

namespace App\Http\Controllers;

use App\Enums\MemberRole;
use App\Models\Member;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InvitationController extends Controller
{
    /** Tautan dari email undangan. Belum login → arahkan masuk/daftar dengan email yang diundang. */
    public function show(Request $request, string $token): RedirectResponse|\Illuminate\Contracts\View\View
    {
        $inv = $this->find($token);
        if (! $inv) {
            return redirect()->route('login')->withErrors(['email' => 'Undangan tidak valid atau sudah kedaluwarsa.']);
        }

        if (! Auth::check()) {
            session(['invite_token' => $token, 'url.intended' => route('invitation.accept', $token)]);
            $hasAccount = \App\Models\User::where('email', $inv->email)->exists();

            return redirect()->route($hasAccount ? 'login' : 'register')->with('status', 'Masuk atau daftar dengan '.$inv->email.' untuk menerima undangan.');
        }

        return view('auth.invitation', ['invitation' => $inv, 'org' => Organization::find($inv->organization_id), 'matches' => strtolower(Auth::user()->email) === strtolower($inv->email)]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $inv = $this->find($token);
        abort_unless($inv && strtolower($request->user()->email) === strtolower($inv->email), 403, 'Undangan ini untuk alamat email lain.');

        Member::firstOrCreate(
            ['organization_id' => $inv->organization_id, 'user_id' => $request->user()->id],
            ['role' => MemberRole::from($inv->role)],
        );
        $inv->update(['accepted_at' => now()]);
        session(['org_id' => $inv->organization_id]);
        session()->forget('invite_token');

        return redirect()->route('console.dashboard')->with('status', 'Anda bergabung ke organisasi.');
    }

    private function find(string $token): ?OrganizationInvitation
    {
        return OrganizationInvitation::where('token_hash', hash('sha256', $token))
            ->whereNull('accepted_at')->where('expires_at', '>', now())->first();
    }
}
