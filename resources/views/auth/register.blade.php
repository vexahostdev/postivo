@extends('layouts.guest')
@section('title', 'Daftar')
@section('content')
    <h1 class="text-2xl font-semibold tracking-tight">Buat akun Postivo</h1>
    <p class="mt-1 text-sm text-muted">Paket Free: 3.000 email/bulan. Sudah punya akun? <a class="font-semibold text-red-500 underline" href="{{ route('login') }}">Masuk</a></p>

    @unless($open ?? true)
        <p class="mt-4 border border-warn px-3 py-2 text-sm text-warn">Pendaftaran baru sedang ditutup sementara. Silakan coba lagi nanti.</p>
    @endunless
    <form method="POST" action="{{ route('register.attempt') }}" class="mt-8 space-y-4">
        @csrf
        @foreach([['name', 'Nama lengkap', 'text', 'name'], ['organization', 'Nama organisasi / perusahaan', 'text', 'organization'], ['email', 'Email', 'email', 'email']] as [$f, $l, $t, $ac])
            <div>
                <label class="label" for="{{ $f }}">{{ $l }}</label>
                <input class="input" id="{{ $f }}" name="{{ $f }}" type="{{ $t }}" value="{{ old($f) }}" required autocomplete="{{ $ac }}">
                @error($f)<p class="err">{{ $message }}</p>@enderror
            </div>
        @endforeach
        <div>
            <label class="label" for="password">Kata sandi</label>
            <input class="input" id="password" name="password" type="password" required minlength="10" autocomplete="new-password">
            <p class="hint">Minimal 10 karakter.</p>
            @error('password')<p class="err">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="label" for="password_confirmation">Ulangi kata sandi</label>
            <input class="input" id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
        </div>
        <button class="btn-primary w-full">Buat akun</button>
    </form>
@endsection
