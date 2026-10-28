<?php

namespace App\Http\Controllers\Admin;

use App\Services\AdminAudit;
use App\Services\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class SecurityController extends AdminController
{
    /** Halaman setup 2FA (secret sementara disimpan di sesi sampai dikonfirmasi). */
    public function index(Request $request)
    {
        $user = $request->user();
        $secret = null;
        if (! $user->hasTwoFactor()) {
            $secret = $request->session()->get('admin_2fa_pending') ?? tap(Totp::generateSecret(), fn ($s) => $request->session()->put('admin_2fa_pending', $s));
        }

        return view('admin.security', ['user' => $user, 'secret' => $secret, 'uri' => $secret ? Totp::uri($secret, $user->email) : null]);
    }

    public function enable(Request $request): RedirectResponse
    {
        abort_if($request->user()->hasTwoFactor(), 403, '2FA sudah aktif.');

        $data = $request->validate(['code' => ['required', 'string']]);
        $secret = $request->session()->get('admin_2fa_pending');
        if (! $secret || ! Totp::verify($secret, $data['code'])) {
            return back()->withErrors(['code' => 'Kode salah atau kedaluwarsa. Coba lagi.']);
        }
        $request->user()->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();
        $request->session()->forget('admin_2fa_pending');
        $request->session()->put([
            'admin_2fa_at' => time(),
            'admin_2fa_fingerprint' => $request->user()->twoFactorFingerprint(),
        ]);
        AdminAudit::log('staff.2fa_enabled', 'user', $request->user()->id);

        return redirect()->route('admin.dashboard')->with('status', '2FA aktif.');
    }

    public function challenge()
    {
        return view('admin.two-factor');
    }

    public function verify(Request $request): RedirectResponse
    {
        $key = '2fa:'.$request->user()->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['code' => 'Terlalu banyak percobaan. Tunggu '.RateLimiter::availableIn($key).' detik.']);
        }
        $data = $request->validate(['code' => ['required', 'string']]);
        if (! $request->user()->hasTwoFactor() || ! Totp::verify($request->user()->two_factor_secret, $data['code'])) {
            RateLimiter::hit($key, 300);
            AdminAudit::log('staff.2fa_failed', 'user', $request->user()->id);

            return back()->withErrors(['code' => 'Kode salah.']);
        }
        RateLimiter::clear($key);
        $request->session()->put([
            'admin_2fa_at' => time(),
            'admin_2fa_fingerprint' => $request->user()->twoFactorFingerprint(),
        ]);

        return redirect()->to($request->session()->pull('admin_intended', route('admin.dashboard')));
    }
}
