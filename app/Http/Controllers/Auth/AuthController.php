<?php

namespace App\Http\Controllers\Auth;

use App\Enums\MemberRole;
use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\WelcomeNotification;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $cred = $request->validate(['email' => ['required', 'email'], 'password' => ['required']]);

        if (! Auth::attempt($cred, $request->boolean('remember'))) {
            return back()->withInput($request->only('email'))->withErrors(['email' => 'Email atau kata sandi salah.']);
        }
        $request->session()->regenerate();

        return redirect()->intended(route('console.dashboard'));
    }

    public function showRegister()
    {
        return view('auth.register');
    }

    public function register(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'organization' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:191', 'unique:users,email'],
            'password' => ['required', 'confirmed', PasswordRule::min(10)],
        ]);

        $user = DB::transaction(function () use ($data) {
            $user = User::create(['name' => $data['name'], 'email' => strtolower($data['email']), 'password' => $data['password']]);
            $plan = config('postivo.plans.free');
            $org = Organization::create([
                'name' => $data['organization'],
                'slug' => Str::slug($data['organization']).'-'.Str::lower(Str::random(5)),
                'plan' => 'free', 'monthly_quota' => $plan['monthly_quota'], 'daily_limit' => $plan['daily_limit'],
            ]);
            Member::create(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => MemberRole::OWNER]);

            return $user;
        });

        event(new Registered($user)); // → email verifikasi
        $user->notify(new WelcomeNotification);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('console.dashboard'))->with('status', 'Akun dibuat. Cek email Anda untuk verifikasi.');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('landing');
    }

    // --- Verifikasi email -------------------------------------------------------
    public function verifyEmail(Request $request, string $id, string $hash): RedirectResponse
    {
        $user = User::findOrFail($id);
        abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403);

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return redirect()->route(Auth::check() ? 'console.dashboard' : 'login')->with('status', 'Email terverifikasi. Terima kasih!');
    }

    public function resendVerification(Request $request): RedirectResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            $request->user()->sendEmailVerificationNotification();
        }

        return back()->with('status', 'Email verifikasi dikirim ulang.');
    }

    // --- Reset kata sandi -------------------------------------------------------
    public function showForgot()
    {
        return view('auth.forgot-password');
    }

    public function sendReset(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink($request->only('email'));

        // Respons seragam agar tidak membocorkan email mana yang terdaftar.
        return back()->with('status', 'Jika email terdaftar, tautan pengaturan ulang sudah dikirim.');
    }

    public function showReset(Request $request, string $token)
    {
        return view('auth.reset-password', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required'], 'email' => ['required', 'email'], 'password' => ['required', 'confirmed', PasswordRule::min(10)],
        ]);

        $status = Password::reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($user));
        });

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('status', 'Kata sandi diperbarui. Silakan masuk.')
            : back()->withErrors(['email' => 'Tautan tidak valid atau sudah kedaluwarsa.']);
    }
}
