@extends('layouts.guest')
@section('title', 'Masuk')
@section('content')
    <h1 class="text-2xl font-semibold tracking-tight">Masuk ke console</h1>
    <p class="mt-1 text-sm text-muted">Belum punya akun? <a class="font-semibold text-red-500 underline" href="{{ route('register') }}">Daftar gratis</a></p>

    @if(session('status'))<p class="mt-4 border border-ok px-3 py-2 text-sm text-ok">{{ session('status') }}</p>@endif
    <form method="POST" action="{{ route('login.attempt') }}" class="mt-8 space-y-4">
        @csrf
        <div>
            <label class="label" for="email">Email</label>
            <input class="input" id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="email">
            @error('email')<p class="err">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="label" for="password">Kata sandi</label>
            <input class="input" id="password" name="password" type="password" required autocomplete="current-password">
        </div>
        <div class="flex items-center justify-between">
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="remember" class="accent-red-500"> Ingat saya</label>
            <a href="{{ route('password.request') }}" class="text-sm text-muted underline">Lupa kata sandi?</a>
        </div>
        <button class="btn-primary w-full">Masuk</button>
    </form>
@endsection
