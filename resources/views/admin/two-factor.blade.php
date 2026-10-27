@extends('layouts.guest')
@section('title', 'Verifikasi 2FA')
@section('content')
    <h1 class="text-2xl font-semibold tracking-tight">Verifikasi dua faktor</h1>
    <p class="mt-1 text-sm text-muted">Masukkan kode 6 digit dari aplikasi autentikator Anda untuk membuka panel admin.</p>
    <form method="POST" action="{{ route('admin.two-factor.verify') }}" class="mt-6 space-y-4">@csrf
        <input class="input mono text-center text-xl tracking-widest" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required autofocus aria-label="Kode 6 digit">
        @error('code')<p class="err">{{ $message }}</p>@enderror
        <button class="btn-primary w-full">Verifikasi</button>
    </form>
    <form method="POST" action="{{ route('logout') }}" class="mt-4">@csrf<button class="text-sm text-muted underline">Keluar</button></form>
@endsection
