@extends('layouts.admin')
@section('title', 'Keamanan saya')
@section('content')
<x-page-header title="Keamanan akun admin" sub="Autentikasi dua faktor (TOTP) melindungi panel dari pengambilalihan akun." />
<section class="card max-w-2xl p-6">
    @if($user->hasTwoFactor())
        <p class="flex items-center gap-2 text-ok"><x-icon name="check" class="size-4" /><b>2FA aktif</b> sejak {{ $user->two_factor_confirmed_at->format('d M Y H:i') }}.</p>
        <p class="hint mt-3">Kehilangan perangkat? Minta superadmin lain mereset 2FA Anda dari halaman Pengguna (tercatat di audit).</p>
    @else
        <ol class="list-decimal space-y-2 pl-5 text-sm">
            <li>Buka aplikasi autentikator (Google Authenticator, Authy, 1Password).</li>
            <li>Tambahkan akun dengan memasukkan kunci ini secara manual:
                <div class="my-2 break-all border border-line bg-paper px-3 py-2 font-mono text-base font-bold tracking-widest" x-data="clipboard(@js($secret))">{{ $secret }} <button type="button" class="btn-outline btn-sm ml-2" @click="copy()" x-text="copied ? 'Tersalin' : 'Salin'"></button></div>
                <details class="text-muted"><summary class="cursor-pointer">URI otpauth (untuk impor)</summary><code class="mono break-all">{{ $uri }}</code></details>
            </li>
            <li>Masukkan kode 6 digit yang tampil untuk mengonfirmasi:</li>
        </ol>
        <form method="POST" action="{{ route('admin.security.enable') }}" class="mt-4 flex max-w-xs gap-2">@csrf
            <input class="input mono text-center text-lg tracking-widest" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required autofocus aria-label="Kode 6 digit">
            <button class="btn-primary">Aktifkan</button>
        </form>
        @error('code')<p class="err mt-2">{{ $message }}</p>@enderror
    @endif
</section>
@endsection
