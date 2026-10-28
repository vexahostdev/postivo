@extends('layouts.guest')
@section('title', 'Undangan')
@section('content')
    <h1 class="text-2xl font-semibold tracking-tight">Undangan bergabung</h1>
    <p class="mt-2 text-sm text-muted">Anda diundang ke <b class="text-ink">{{ $org->name }}</b> sebagai <b class="text-ink">{{ $invitation->role }}</b>.</p>
    @if($matches)
        <form method="POST" action="{{ route('invitation.accept.confirm', request()->route('token')) }}" class="mt-6">@csrf
            <button class="btn-primary w-full">Terima undangan</button>
        </form>
    @else
        <p class="mt-4 break-words border border-red-500 bg-red-50 px-3 py-2 text-sm text-red-600">Undangan ini untuk <span class="mono break-all">{{ $invitation->email }}</span>, sedangkan Anda masuk sebagai <span class="mono break-all">{{ auth()->user()->email }}</span>.
            <form method="POST" action="{{ route('logout') }}" class="mt-2 inline-flex">@csrf<button class="btn-outline btn-sm">Keluar</button></form> lalu masuk dengan akun yang benar.</p>
    @endif
@endsection
