@extends('layouts.guest')
@section('title', 'Atur ulang kata sandi')
@section('content')
    <h1 class="text-2xl font-semibold tracking-tight">Atur ulang kata sandi</h1>
    <form method="POST" action="{{ route('password.update') }}" class="mt-6 space-y-4">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <div>
            <label class="label" for="email">Email</label>
            <input class="input" id="email" name="email" type="email" value="{{ old('email', $email) }}" required>
            @error('email')<p class="err">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="label" for="password">Kata sandi baru</label>
            <input class="input" id="password" name="password" type="password" required minlength="10" autocomplete="new-password">
            <p class="hint">Minimal 10 karakter.</p>
            @error('password')<p class="err">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="label" for="password_confirmation">Ulangi kata sandi</label>
            <input class="input" id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
        </div>
        <button class="btn-primary w-full">Simpan kata sandi</button>
    </form>
@endsection
