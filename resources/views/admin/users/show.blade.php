@extends('layouts.admin')
@section('title', $user->name)
@section('content')
@php $can = fn ($p) => auth()->user()->staffCan($p); @endphp
<a href="{{ route('admin.users.index') }}" class="mono text-muted hover:underline">← Pengguna</a>
<x-page-header :title="$user->name" :sub="$user->email">
    @if($user->disabled_at)<span class="mono border border-red-500 px-1.5 py-0.5 uppercase text-red-500">Nonaktif</span>@endif
    @if($user->platform_role)<span class="mono border border-ink px-1.5 py-0.5 uppercase">{{ $user->platform_role->value }}</span>@endif
</x-page-header>

<div class="grid gap-6 lg:grid-cols-3">
    <section class="card lg:col-span-2">
        <div class="border-b border-line px-5 py-3"><h2 class="kicker">Keanggotaan</h2></div>
        <table class="w-full"><tbody>@forelse($memberships as $m)<tr><td class="td"><a class="underline-offset-2 hover:underline" href="{{ route('admin.organizations.show', $m->organization_id) }}">{{ $m->organization->name }}</a></td><td class="td mono">{{ $m->role->value }}</td><td class="td mono text-muted">{{ $m->organization->plan }}</td></tr>@empty<tr><td class="td text-muted">Bukan anggota organisasi mana pun.</td></tr>@endforelse</tbody></table>
        <dl class="grid grid-cols-2 gap-px border-t border-line bg-line text-sm">
            <div class="bg-white p-4"><dt class="kicker">Email terverifikasi</dt><dd class="mono mt-1">{{ $user->email_verified_at?->format('d M Y H:i') ?? '—' }}</dd></div>
            <div class="bg-white p-4"><dt class="kicker">2FA</dt><dd class="mono mt-1">{{ $user->hasTwoFactor() ? 'aktif' : 'tidak aktif' }}</dd></div>
        </dl>
    </section>

    <div class="space-y-6">
        @if($can('user.manage'))
        <section class="card space-y-3 p-5">
            <h2 class="kicker">Tindakan akun</h2>
            @unless($user->hasVerifiedEmail())<form method="POST" action="{{ route('admin.users.verify', $user->id) }}">@csrf<button class="btn-outline w-full">Tandai email terverifikasi</button></form>@endunless
            <form method="POST" action="{{ route('admin.users.reset', $user->id) }}">@csrf<button class="btn-outline w-full">Kirim tautan reset kata sandi</button></form>
            <form method="POST" action="{{ route('admin.users.disable', $user->id) }}" class="space-y-2 border-t border-line pt-3">@csrf
                <input class="input" name="reason" required minlength="5" placeholder="Alasan (wajib)" aria-label="Alasan">
                <button class="{{ $user->disabled_at ? 'btn-outline' : 'btn-danger' }} w-full" onclick="return confirm('Lanjutkan?')">{{ $user->disabled_at ? 'Aktifkan kembali akun' : 'Nonaktifkan akun & putus sesi' }}</button>
            </form>
        </section>
        @endif

        @if($can('staff.manage'))
        <section class="card space-y-3 p-5">
            <h2 class="kicker">Peran staf platform</h2>
            <form method="POST" action="{{ route('admin.users.staff-role', $user->id) }}" class="space-y-2">@csrf
                <select class="input" name="platform_role" aria-label="Peran staf"><option value="">— bukan staf —</option>@foreach($roles as $r)<option value="{{ $r->value }}" @selected($user->platform_role === $r)>{{ $r->label() }}</option>@endforeach</select>
                <input class="input" name="reason" required minlength="5" placeholder="Alasan (wajib)" aria-label="Alasan">
                <button class="btn-primary w-full">Simpan peran</button>
            </form>
            @if($user->hasTwoFactor())
            <form method="POST" action="{{ route('admin.users.reset-2fa', $user->id) }}" class="space-y-2 border-t border-line pt-3">@csrf
                <input class="input" name="reason" required minlength="5" placeholder="Alasan reset 2FA" aria-label="Alasan">
                <button class="btn-danger w-full" onclick="return confirm('Reset 2FA pengguna ini?')">Reset 2FA</button>
            </form>
            @endif
        </section>
        @endif
    </div>
</div>
@endsection
