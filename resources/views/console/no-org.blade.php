@extends('layouts.guest')
@section('title', 'Belum tergabung di organisasi')
@section('content')
    <h1 class="text-2xl font-semibold tracking-tight">Belum tergabung di organisasi</h1>
    <p class="mt-2 text-sm text-muted">Akun <b class="text-ink">{{ auth()->user()->email }}</b> belum menjadi anggota organisasi mana pun. Minta owner organisasi mengirim undangan ke email ini, atau hubungi <a class="underline" href="mailto:{{ config('postivo.support_email') }}">{{ config('postivo.support_email') }}</a>.</p>
    <form method="POST" action="{{ route('logout') }}" class="mt-6">@csrf<button class="btn-outline w-full">Keluar</button></form>
@endsection
