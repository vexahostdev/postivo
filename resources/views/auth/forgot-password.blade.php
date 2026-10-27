@extends('layouts.guest')
@section('title', 'Lupa kata sandi')
@section('content')
    <h1 class="text-2xl font-semibold tracking-tight">Lupa kata sandi</h1>
    <p class="mt-1 text-sm text-muted">Masukkan email akun Anda. Kami kirim tautan untuk mengatur ulang.</p>
    @if(session('status'))<p class="mt-4 border border-ok px-3 py-2 text-sm text-ok">{{ session('status') }}</p>@endif
    <form method="POST" action="{{ route('password.email') }}" class="mt-6 space-y-4">
        @csrf
        <div>
            <label class="label" for="email">Email</label>
            <input class="input" id="email" name="email" type="email" value="{{ old('email') }}" required autofocus>
            @error('email')<p class="err">{{ $message }}</p>@enderror
        </div>
        <button class="btn-primary w-full">Kirim tautan</button>
        <a href="{{ route('login') }}" class="block text-center text-sm text-muted underline">Kembali ke halaman masuk</a>
    </form>
@endsection
