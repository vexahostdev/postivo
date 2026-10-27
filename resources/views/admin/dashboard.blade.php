@extends('layouts.admin')
@section('title', 'Dashboard')
@section('content')
@php
    $rp = fn ($n) => 'Rp '.number_format($n, 0, ',', '.');
    $kpi = [
        ['Organisasi', number_format($orgs), $paidOrgs.' berbayar · '.$suspended.' ditangguhkan', null],
        ['Pengguna', number_format($users), null, null],
        ['MRR (perkiraan)', $rp($mrr), 'org berbayar aktif × harga plan', null],
        ['Email 24 jam', number_format($s24->sum()), number_format($s24['DELIVERED'] ?? 0).' delivered', null],
        ['Email 30 hari', number_format($s30->sum()), 'bounce '.$bounceRate.'% · komplain '.$complaintRate.'%', $bounceRate > 5 || $complaintRate > 0.3 ? 'red' : null],
    ];
    $alerts = [
        ['Order pending', $pendingOrders, route('admin.billing.index', ['status' => 'PENDING'])],
        ['Webhook Xendit gagal', $failedWebhookEvents, route('admin.billing.index')],
        ['Failed jobs', $failedJobs, route('admin.system.index')],
        ['Event relay tak cocok (24j)', $unmatched, route('admin.system.index')],
        ['Endpoint webhook pelanggan bermasalah', $failingEndpoints, route('admin.system.index')],
        ['Org reputasi BAHAYA', $risk['DANGER'] ?? 0, route('admin.abuse', ['level' => 'DANGER'])],
        ['Org reputasi WASPADA', $risk['WARN'] ?? 0, route('admin.abuse', ['level' => 'WARN'])],
    ];
@endphp
<x-page-header title="Dashboard platform" sub="Ringkasan operasional Postivo." />

<div class="grid gap-px border border-line bg-line sm:grid-cols-2 xl:grid-cols-5">
    @foreach($kpi as [$l, $v, $sub, $tone])
        <div class="bg-white p-5"><p class="kicker">{{ $l }}</p><p @class(['mt-2 text-2xl font-semibold tracking-tight tabular-nums', 'text-red-500' => $tone === 'red'])>{{ $v }}</p>@if($sub)<p class="hint">{{ $sub }}</p>@endif</div>
    @endforeach
</div>

<div class="mt-6 grid gap-6 lg:grid-cols-3">
    <section class="card lg:col-span-2">
        <div class="border-b border-line px-5 py-3"><h2 class="kicker">Perlu perhatian</h2></div>
        <ul class="divide-y divide-line">
            @foreach($alerts as [$l, $n, $url])
                <li><a href="{{ $url }}" class="flex items-center justify-between px-5 py-3 text-sm hover:bg-paper"><span>{{ $l }}</span>
                    <span @class(['mono border px-2 py-0.5', 'border-red-500 text-red-500' => $n > 0, 'border-line text-muted' => $n == 0])>{{ number_format($n) }}</span></a></li>
            @endforeach
        </ul>
    </section>

    <section class="card">
        <div class="flex items-center justify-between border-b border-line px-5 py-3"><h2 class="kicker">Aktivitas admin</h2><a class="text-xs font-semibold text-red-500 underline" href="{{ route('admin.audit') }}">Semua</a></div>
        <ul class="divide-y divide-line text-sm">
            @forelse($audit as $a)
                <li class="px-5 py-2.5"><p class="mono font-bold">{{ $a->action }}</p><p class="hint mt-0">{{ $a->admin?->name ?? 'sistem' }} · {{ $a->created_at->diffForHumans() }}</p></li>
            @empty<li class="px-5 py-6 text-sm text-muted">Belum ada aktivitas.</li>@endforelse
        </ul>
    </section>
</div>

<div class="mt-6 card">
    <div class="border-b border-line px-5 py-3"><h2 class="kicker">Status email 30 hari</h2></div>
    <div class="grid gap-px bg-line sm:grid-cols-3 lg:grid-cols-9">
        @foreach(['QUEUED','SENT','DELIVERED','DEFERRED','BOUNCED','COMPLAINED','FAILED','SUPPRESSED','SENDING'] as $st)
            <div class="bg-white p-4"><p class="kicker">{{ $st }}</p><p class="mt-1 text-lg font-semibold tabular-nums">{{ number_format($s30[$st] ?? 0) }}</p></div>
        @endforeach
    </div>
</div>
@endsection
