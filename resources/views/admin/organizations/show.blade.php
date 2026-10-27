@extends('layouts.admin')
@section('title', $org->name)
@section('content')
@php
    $can = fn ($p) => auth()->user()->staffCan($p);
    $lvl = ['OK' => 'border-ok text-ok', 'WARN' => 'border-warn text-warn', 'DANGER' => 'border-red-500 text-red-500'][$rep['level']];
    $rp = fn ($n) => 'Rp '.number_format($n, 0, ',', '.');
@endphp
<a href="{{ route('admin.organizations.index') }}" class="mono text-muted hover:underline">← Organisasi</a>
<x-page-header :title="$org->name" :sub="$org->slug.' · dibuat '.$org->created_at->format('d M Y')">
    <x-status-pill :status="$org->isSuspended() ? 'SUSPENDED' : 'ACTIVE'" />
</x-page-header>

@if($org->isSuspended())
    <div class="mb-5 border border-red-500 bg-red-50 px-4 py-3 text-sm text-red-600"><b>Ditangguhkan</b> {{ $org->suspended_at?->diffForHumans() }} — {{ $org->suspended_reason }}</div>
@endif

<div class="grid gap-px border border-line bg-line sm:grid-cols-2 lg:grid-cols-4">
    <div class="bg-white p-5"><p class="kicker">Plan</p><p class="mt-2 text-xl font-semibold">{{ ucfirst($org->plan) }}</p><p class="hint">{{ $org->plan_expires_at ? 's/d '.$org->plan_expires_at->format('d M Y') : 'tanpa masa berlaku' }}</p></div>
    <div class="bg-white p-5"><p class="kicker">Kuota bulan ini</p><p class="mt-2 text-xl font-semibold tabular-nums">{{ number_format($usage->sent ?? 0) }} <span class="text-sm font-normal text-muted">/ {{ number_format($org->monthly_quota) }}</span></p><p class="hint">harian: {{ $org->daily_limit ? number_format($org->daily_limit) : 'tanpa batas' }}</p></div>
    <div class="bg-white p-5"><p class="kicker">Reputasi 7 hari</p><p class="mt-2"><span class="mono border px-1.5 py-0.5 uppercase {{ $lvl }}">{{ $rep['level'] }}</span></p><p class="hint">vol {{ number_format($rep['volume']) }} · bounce {{ $rep['bounce_rate'] }}% · komplain {{ $rep['complaint_rate'] }}%</p></div>
    <div class="bg-white p-5"><p class="kicker">Domain</p><p class="mt-2 text-xl font-semibold">{{ $domains->where('status', 'ACTIVE')->count() }} <span class="text-sm font-normal text-muted">/ {{ $domains->count() }} aktif</span></p></div>
</div>

<div class="mt-6 grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <section class="card">
            <div class="border-b border-line px-5 py-3"><h2 class="kicker">Anggota</h2></div>
            <table class="w-full"><tbody>@foreach($members as $m)<tr><td class="td"><a class="underline-offset-2 hover:underline" href="{{ route('admin.users.show', $m->user_id) }}">{{ $m->user->name }}</a></td><td class="td mono text-muted">{{ $m->user->email }}</td><td class="td mono">{{ $m->role->value }}</td></tr>@endforeach</tbody></table>
        </section>
        <section class="card">
            <div class="border-b border-line px-5 py-3"><h2 class="kicker">Domain</h2></div>
            <table class="w-full"><tbody>@forelse($domains as $d)<tr><td class="td mono">{{ $d->domain_name }}</td><td class="td"><x-status-pill :status="$d->status" /></td><td class="td mono text-muted">{{ $d->last_audit_at?->diffForHumans() ?? 'belum diaudit' }}</td></tr>@empty<tr><td class="td text-muted">Belum ada domain.</td></tr>@endforelse</tbody></table>
        </section>
        <section class="card">
            <div class="border-b border-line px-5 py-3"><h2 class="kicker">Order terakhir</h2></div>
            <table class="w-full"><tbody>@forelse($orders as $o)<tr><td class="td mono"><a class="underline-offset-2 hover:underline" href="{{ route('admin.billing.show', $o->id) }}">{{ $o->external_id }}</a></td><td class="td">{{ $o->planName() }} · {{ $o->months }}bln</td><td class="td mono">{{ $rp($o->amount) }}</td><td class="td mono">{{ $o->status }}</td></tr>@empty<tr><td class="td text-muted">Belum ada order.</td></tr>@endforelse</tbody></table>
        </section>
        <section class="card">
            <div class="flex items-center justify-between border-b border-line px-5 py-3"><h2 class="kicker">Audit organisasi</h2><a class="text-xs font-semibold text-red-500 underline" href="{{ route('admin.audit', ['org' => $org->id]) }}">Semua</a></div>
            <ul class="divide-y divide-line text-sm">@forelse($audit as $a)<li class="px-5 py-2.5"><span class="mono font-bold">{{ $a->action }}</span> <span class="text-muted">— {{ $a->admin?->name ?? 'sistem' }}, {{ $a->created_at->diffForHumans() }}</span>@if($a->reason)<p class="hint mt-0">“{{ $a->reason }}”</p>@endif</li>@empty<li class="px-5 py-6 text-muted">Belum ada.</li>@endforelse</ul>
        </section>
    </div>

    <div class="space-y-6">
        @if($can('org.suspend') || $can('impersonate'))
        <section class="card p-5 space-y-3">
            <h2 class="kicker">Tindakan</h2>
            @if($can('org.suspend'))
            <form method="POST" action="{{ route('admin.organizations.'.($org->isSuspended() ? 'unsuspend' : 'suspend'), $org->id) }}" class="space-y-2">@csrf
                <label class="label" for="r1">Alasan ({{ $org->isSuspended() ? 'cabut' : 'tangguhkan' }})</label>
                <input class="input" id="r1" name="reason" required minlength="5" placeholder="Wajib diisi — tercatat di audit">
                <button class="{{ $org->isSuspended() ? 'btn-outline' : 'btn-danger' }} w-full" onclick="return confirm('Lanjutkan?')">{{ $org->isSuspended() ? 'Cabut penangguhan' : 'Tangguhkan organisasi' }}</button>
            </form>
            @endif
            @if($can('impersonate'))
            <form method="POST" action="{{ route('admin.organizations.impersonate', $org->id) }}" class="space-y-2 border-t border-line pt-3">@csrf
                <label class="label" for="r2">Impersonasi hanya-baca</label>
                <input class="input" id="r2" name="reason" required minlength="5" placeholder="Alasan (mis. tiket #123)">
                <button class="btn-outline w-full">Lihat sebagai owner</button>
            </form>
            @endif
        </section>
        @endif

        @if($can('org.plan'))
        <section class="card p-5">
            <h2 class="kicker mb-3">Ubah plan / kuota</h2>
            <form method="POST" action="{{ route('admin.organizations.plan', $org->id) }}" class="space-y-3">@csrf
                <div><label class="label" for="pl">Plan</label><select class="input" id="pl" name="plan">@foreach($plans as $k => $p)<option value="{{ $k }}" @selected($org->plan === $k)>{{ $p['name'] }}</option>@endforeach</select></div>
                <div><label class="label" for="ex">Berlaku sampai</label><input class="input" id="ex" type="date" name="expires_at" value="{{ $org->plan_expires_at?->format('Y-m-d') }}"><p class="hint">Kosong = 1 bulan dari sekarang (Free: tanpa batas).</p></div>
                <div class="grid grid-cols-2 gap-2"><div><label class="label" for="mq">Kuota/bln</label><input class="input" id="mq" type="number" name="monthly_quota" placeholder="{{ $org->monthly_quota }}"></div><div><label class="label" for="dl">Batas/hari</label><input class="input" id="dl" type="number" name="daily_limit" placeholder="{{ $org->daily_limit ?? '∞' }}"></div></div>
                <p class="hint">Kuota/batas kosong = ikut default plan.</p>
                <div><label class="label" for="r3">Alasan</label><input class="input" id="r3" name="reason" required minlength="5"></div>
                <button class="btn-primary w-full">Simpan</button>
            </form>
        </section>
        @endif

        <section class="card p-5">
            <h2 class="kicker mb-3">Catatan internal</h2>
            <form method="POST" action="{{ route('admin.organizations.note', $org->id) }}" class="space-y-2">@csrf
                <textarea class="input" name="internal_notes" rows="5" maxlength="5000" @disabled(!$can('org.note'))>{{ $org->internal_notes }}</textarea>
                @if($can('org.note'))<button class="btn-outline btn-sm">Simpan catatan</button>@endif
            </form>
        </section>
    </div>
</div>
@endsection
