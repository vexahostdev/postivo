@extends('layouts.guest')
@section('title', 'Daftar')
@section('content')
    <h1 class="text-2xl font-semibold tracking-tight">Buat akun Postivo</h1>
    <p class="mt-1 text-sm text-muted">Paket Free: 3.000 email/bulan. Sudah punya akun? <a class="font-semibold text-red-500 underline" href="{{ route('login') }}">Masuk</a></p>

    @unless($open ?? true)
        <div class="mt-5 border border-amber-500/50 bg-amber-50/60 p-4 text-sm text-amber-900 rounded-none">
            <div class="flex items-center gap-2 font-bold">
                <span class="text-base">🔒</span>
                <span>Pendaftaran Akun Baru Sedang Dikunci</span>
            </div>
            <p class="mt-1 text-xs leading-relaxed text-amber-800">
                Layanan saat ini sedang dalam tahap pengujian &amp; penyesuaian sistem. Pendaftaran akun publik sementara waktu belum dibuka.
            </p>
            <div class="mt-3 flex items-center gap-3">
                <a class="btn-outline btn-sm bg-white" href="{{ route('landing') }}">Kembali ke Beranda</a>
                <a class="btn-sm font-semibold text-red-500 underline" href="{{ route('login') }}">Masuk dengan Akun yang Sudah Ada</a>
            </div>
        </div>
    @endunless

    @if($open ?? true)
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
        <p class="mb-3 text-xs text-muted">Dengan mendaftar, Anda menyetujui <a class="underline" href="{{ route('legal.terms') }}" target="_blank">Syarat Layanan</a>, <a class="underline" href="{{ route('legal.privacy') }}" target="_blank">Kebijakan Privasi</a>, dan <a class="underline" href="{{ route('legal.aup') }}" target="_blank">Penggunaan yang Dapat Diterima</a>.</p>
        <button class="btn-primary w-full">Buat akun</button>
    </form>
    @else
        <div class="mt-6 border-t border-line pt-4 text-xs text-muted text-center">
            Punya pertanyaan atau ingin akses awal? Hubungi tim support di <a class="font-semibold text-red-500 underline" href="mailto:{{ config('postivo.support_email') }}">{{ config('postivo.support_email') }}</a>.
        </div>
    @endif
@endsection
