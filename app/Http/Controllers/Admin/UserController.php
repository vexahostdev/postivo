<?php

namespace App\Http\Controllers\Admin;

use App\Enums\StaffRole;
use App\Models\Member;
use App\Models\User;
use App\Services\AdminAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;

class UserController extends AdminController
{
    public function index(Request $request)
    {
        $q = User::query()->latest();
        if ($s = trim((string) $request->query('q'))) {
            $like = '%'.addcslashes($s, '%_').'%';
            $q->where(fn ($w) => $w->where('email', 'like', $like)->orWhere('name', 'like', $like));
        }
        if ($request->query('staff')) {
            $q->whereNotNull('platform_role');
        }

        return view('admin.users.index', ['users' => $q->paginate(25)->withQueryString()]);
    }

    public function show(string $id)
    {
        $user = User::findOrFail($id);

        return view('admin.users.show', [
            'user' => $user,
            'memberships' => Member::with('organization')->where('user_id', $id)->get(),
            'roles' => StaffRole::cases(),
        ]);
    }

    public function verify(string $id): RedirectResponse
    {
        $this->need('user.manage');
        $user = User::findOrFail($id);
        $user->markEmailAsVerified();
        AdminAudit::log('user.verify_email', 'user', $id);

        return back()->with('status', 'Email ditandai terverifikasi.');
    }

    public function sendReset(string $id): RedirectResponse
    {
        $this->need('user.manage');
        $user = User::findOrFail($id);
        Password::sendResetLink(['email' => $user->email]);
        AdminAudit::log('user.send_reset', 'user', $id);

        return back()->with('status', 'Tautan reset dikirim ke pengguna.');
    }

    public function toggleDisabled(Request $request, string $id): RedirectResponse
    {
        $this->need('user.manage');
        $reason = $this->reason($request);
        $user = User::findOrFail($id);
        abort_if($user->id === auth()->id(), 422, 'Tidak bisa menonaktifkan akun sendiri.');
        abort_if($user->platform_role === StaffRole::SUPERADMIN && ! auth()->user()->staffCan('staff.manage'), 403);

        $user->forceFill(['disabled_at' => $user->disabled_at ? null : now(), 'remember_token' => null])->save();
        // Matikan sesi aktif pengguna.
        \Illuminate\Support\Facades\DB::table('sessions')->where('user_id', $user->id)->delete();
        AdminAudit::log($user->disabled_at ? 'user.disable' : 'user.enable', 'user', $id, null, $reason);

        return back()->with('status', $user->disabled_at ? 'Akun dinonaktifkan dan sesi diputus.' : 'Akun diaktifkan kembali.');
    }

    public function staffRole(Request $request, string $id): RedirectResponse
    {
        $this->need('staff.manage');
        $data = $request->validate(['platform_role' => ['nullable', Rule::in(array_column(StaffRole::cases(), 'value'))], 'reason' => ['required', 'string', 'min:5', 'max:500']]);
        $user = User::findOrFail($id);
        abort_if($user->id === auth()->id(), 422, 'Tidak bisa mengubah peran staf sendiri.');
        // Jangan sampai platform tanpa superadmin.
        if ($user->platform_role === StaffRole::SUPERADMIN && ($data['platform_role'] ?? null) !== 'superadmin') {
            abort_if(User::where('platform_role', 'superadmin')->whereNull('disabled_at')->count() <= 1, 422, 'Harus ada minimal satu superadmin aktif.');
        }

        $before = $user->platform_role?->value;
        $user->forceFill(['platform_role' => $data['platform_role'] ?: null])->save();
        AdminAudit::log('staff.role', 'user', $id, null, $data['reason'], ['before' => $before, 'after' => $data['platform_role'] ?: null]);

        return back()->with('status', 'Peran staf diperbarui.');
    }

    public function reset2fa(Request $request, string $id): RedirectResponse
    {
        $this->need('staff.manage');
        $reason = $this->reason($request);
        $user = User::findOrFail($id);
        abort_if($user->id === auth()->id(), 422, 'Reset 2FA sendiri dilakukan oleh superadmin lain.');
        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
        AdminAudit::log('staff.reset_2fa', 'user', $id, null, $reason);

        return back()->with('status', '2FA direset; pengguna harus mendaftar ulang.');
    }
}
