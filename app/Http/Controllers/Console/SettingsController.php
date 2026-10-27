<?php

namespace App\Http\Controllers\Console;

use App\Enums\MemberRole;
use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\OrganizationInvitation;
use App\Notifications\TeamInvitationNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    public function index()
    {
        $org = app('org.current');

        return view('console.settings', [
            'members' => Member::with('user')->where('organization_id', $org->id)->get(),
            'invitations' => OrganizationInvitation::where('organization_id', $org->id)->whereNull('accepted_at')->where('expires_at', '>', now())->get(),
            'smtp' => ['host' => config('postivo.smtp.host')],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        app('org.current')->update($data);

        return back()->with('status', 'Pengaturan organisasi disimpan.');
    }

    public function invite(Request $request): RedirectResponse
    {
        $org = app('org.current');
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:191'],
            'role' => ['required', Rule::in(['admin', 'developer', 'viewer'])],
        ]);
        $email = strtolower($data['email']);

        if (Member::where('organization_id', $org->id)->whereHas('user', fn ($q) => $q->where('email', $email))->exists()) {
            return back()->withErrors(['email' => 'Pengguna ini sudah menjadi anggota.']);
        }
        // Admin tidak boleh mengangkat admin lain; hanya owner.
        if ($data['role'] === 'admin' && $request->attributes->get('role') !== MemberRole::OWNER) {
            abort(403, 'Hanya owner yang dapat mengundang admin.');
        }

        $token = Str::random(48);
        OrganizationInvitation::create([
            'organization_id' => $org->id, 'email' => $email, 'role' => $data['role'],
            'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(7), 'created_at' => now(),
        ]);
        Notification::route('mail', $email)->notify(new TeamInvitationNotification($org, $data['role'], $token));

        return back()->with('status', "Undangan dikirim ke {$email}.");
    }
}
