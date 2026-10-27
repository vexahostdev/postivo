@extends('layouts.app')
@section('title', 'Settings')
@section('content')
<x-page-header title="Settings" sub="Organisasi, anggota, dan akses SMTP." />

<div class="grid gap-6 lg:grid-cols-2">
    <section class="card">
        <div class="border-b border-line px-5 py-3"><h2 class="kicker">Organisasi</h2></div>
        <form method="POST" action="{{ route('console.settings.update') }}" class="space-y-4 p-5">
            @csrf @method('PATCH')
            <div>
                <label class="label" for="name">Nama</label>
                <input class="input" id="name" name="name" value="{{ old('name', $currentOrg->name) }}" required @disabled(!$currentRole->atLeast(\App\Enums\MemberRole::ADMIN))>
                @error('name')<p class="err">{{ $message }}</p>@enderror
            </div>
            <dl class="grid grid-cols-2 gap-3 text-sm">
                <div><dt class="kicker">Paket</dt><dd class="mono mt-0.5">{{ $currentOrg->plan }}</dd></div>
                <div><dt class="kicker">Kuota bulanan</dt><dd class="mono mt-0.5">{{ number_format($currentOrg->monthly_quota) }}</dd></div>
            </dl>
            @if($currentRole->atLeast(\App\Enums\MemberRole::ADMIN))<button class="btn-outline btn-sm">Simpan</button>@endif
        </form>
    </section>

    <section class="card">
        <div class="border-b border-line px-5 py-3"><h2 class="kicker">SMTP</h2></div>
        <div class="space-y-3 p-5 text-sm">
            <p>Pakai Postivo sebagai relay SMTP dari aplikasi apa pun.</p>
            <dl class="divide-y divide-line border border-line">
                @foreach([['Host', $smtp['host']], ['Port', '587 (STARTTLS) · 465 (TLS) · 2525'], ['Username', 'postivo (bebas)'], ['Password', 'API key dengan scope emails:send']] as [$k, $v])
                    <div class="flex justify-between gap-4 px-4 py-2.5"><dt class="kicker">{{ $k }}</dt><dd class="mono text-right">{{ $v }}</dd></div>
                @endforeach
            </dl>
            <p class="hint">Alamat <span class="mono">From</span> harus berasal dari domain Active milik organisasi.</p>
        </div>
    </section>

    @if($currentRole->atLeast(\App\Enums\MemberRole::ADMIN))
    <section class="card lg:col-span-2">
        <div class="border-b border-line px-5 py-3"><h2 class="kicker">Undang anggota</h2></div>
        <form method="POST" action="{{ route('console.settings.invite') }}" class="flex flex-wrap items-end gap-3 p-5">
            @csrf
            <div class="min-w-56 flex-1"><label class="label" for="inv-email">Email</label><input class="input" id="inv-email" type="email" name="email" required value="{{ old('email') }}">
                @error('email')<p class="err">{{ $message }}</p>@enderror</div>
            <div><label class="label" for="inv-role">Peran</label>
                <select class="input" id="inv-role" name="role"><option value="viewer">Viewer</option><option value="developer" selected>Developer</option>@if($currentRole === \App\Enums\MemberRole::OWNER)<option value="admin">Admin</option>@endif</select></div>
            <button class="btn-primary"><x-icon name="plus" />Kirim undangan</button>
        </form>
        @if($invitations->isNotEmpty())
            <ul class="divide-y divide-line border-t border-line text-sm">@foreach($invitations as $i)<li class="flex justify-between px-5 py-2.5"><span class="mono">{{ $i->email }}</span><span class="mono text-muted">{{ $i->role }} · berlaku s/d {{ $i->expires_at->format('d M') }}</span></li>@endforeach</ul>
        @endif
    </section>
    @endif

    <section class="card lg:col-span-2">
        <div class="border-b border-line px-5 py-3"><h2 class="kicker">Anggota</h2></div>
        <table class="w-full">
            <thead><tr><th class="th">Nama</th><th class="th">Email</th><th class="th">Peran</th></tr></thead>
            <tbody>
            @foreach($members as $m)
                <tr><td class="td">{{ $m->user->name }}</td><td class="td mono text-muted">{{ $m->user->email }}</td><td class="td mono">{{ $m->role->value }}</td></tr>
            @endforeach
            </tbody>
        </table>
    </section>
</div>
@endsection
