@extends('layouts.admin')
@section('title', 'Kesehatan sistem')
@section('content')
@php
    $can = auth()->user()->staffCan('system.manage');
    $hbOk = $heartbeatAge !== null && $heartbeatAge < 180;
@endphp
<x-page-header title="Kesehatan sistem" sub="Scheduler, relay, antrean, dan anomali." />

<div class="grid gap-px border border-line bg-line sm:grid-cols-2 xl:grid-cols-4">
    <div class="bg-white p-5"><p class="kicker">Scheduler</p><p class="mt-2 text-lg font-semibold {{ $hbOk ? 'text-ok' : 'text-red-500' }}">{{ $hbOk ? '✓ hidup' : ($heartbeatAge === null ? '✗ belum pernah jalan' : '✗ terhenti') }}</p><p class="hint">{{ $heartbeatAge !== null ? 'denyut '.$heartbeatAge.' dtk lalu' : 'jalankan php artisan schedule:work' }}</p></div>
    <div class="bg-white p-5"><p class="kicker">Relay pelanggan</p><p class="mt-2 text-lg font-semibold">{{ $relayDriver }}</p><p class="hint">driver aktif</p></div>
    <div class="bg-white p-5"><p class="kicker">Antrean ({{ $queue }})</p><p class="mt-2 text-lg font-semibold tabular-nums">{{ number_format($pendingJobs) }} <span class="text-sm font-normal text-muted">menunggu</span></p></div>
    <div class="bg-white p-5"><p class="kicker">Email tertahan &gt; 1 jam</p><p class="mt-2 text-lg font-semibold tabular-nums {{ $queuedStale ? 'text-red-500' : '' }}">{{ number_format($queuedStale) }}</p><p class="hint">spool SMTP: {{ $spoolFiles }} file</p></div>
</div>

<div class="mt-6 grid gap-6 lg:grid-cols-2">
    <section class="card">
        <div class="border-b border-line px-5 py-3"><h2 class="kicker">Circuit breaker relay</h2></div>
        @foreach($breakers as $name => $b)
            <div class="flex items-center justify-between border-b border-line px-5 py-3 text-sm last:border-b-0">
                <div><p class="mono font-bold">{{ $name }}</p><p class="hint mt-0">{{ $b['fails'] }} gagal dalam jendela</p></div>
                <div class="flex items-center gap-2"><span class="mono border px-1.5 py-0.5 text-[11px] uppercase {{ $b['open'] ? 'border-red-500 text-red-500' : 'border-ok text-ok' }}">{{ $b['open'] ? 'TERBUKA' : 'normal' }}</span>
                    @if($can && ($b['open'] || $b['fails']))<form method="POST" action="{{ route('admin.system.breaker', $name) }}">@csrf<button class="btn-outline btn-sm">Reset</button></form>@endif</div>
            </div>
        @endforeach
    </section>
    <section class="card">
        <div class="border-b border-line px-5 py-3"><h2 class="kicker">Lingkungan</h2></div>
        <dl class="divide-y divide-line text-sm">@foreach($env as $k => $v)<div class="flex justify-between px-5 py-2.5"><dt class="kicker">{{ $k }}</dt><dd class="mono">{{ $v }}</dd></div>@endforeach</dl>
    </section>
</div>

<section class="card mt-6">
    <div class="border-b border-line px-5 py-3"><h2 class="kicker">Failed jobs ({{ $failedJobs->count() }})</h2></div>
    @forelse($failedJobs as $j)
        <details class="border-b border-line px-5 py-3 text-sm">
            <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2"><span class="mono">{{ class_basename(json_decode($j->payload)->displayName ?? 'job') }} · {{ $j->queue }} · {{ \Illuminate\Support\Carbon::parse($j->failed_at)->diffForHumans() }}</span>
                @if($can)<span class="flex gap-1" onclick="event.stopPropagation()">
                    <form method="POST" action="{{ route('admin.system.retry', $j->uuid) }}">@csrf<button class="btn-outline btn-sm">Retry</button></form>
                    <form method="POST" action="{{ route('admin.system.delete', $j->uuid) }}" onsubmit="return confirm('Hapus?')">@csrf @method('DELETE')<button class="btn-danger btn-sm">Hapus</button></form></span>@endif</summary>
            <pre class="mono mt-2 max-h-48 overflow-auto whitespace-pre-wrap bg-paper p-3">{{ \Illuminate\Support\Str::limit($j->exception, 1200) }}</pre>
        </details>
    @empty<p class="px-5 py-6 text-sm text-muted">Tidak ada failed job. 👍</p>@endforelse
</section>

<div class="mt-6 grid gap-6 lg:grid-cols-2">
    <section class="card"><div class="border-b border-line px-5 py-3"><h2 class="kicker">Event relay tak cocok</h2></div>
        @forelse($unmatched as $u)<details class="border-b border-line px-5 py-2.5 text-sm"><summary class="mono cursor-pointer">{{ $u->relay }} · {{ $u->reason }} · {{ \Illuminate\Support\Carbon::parse($u->created_at)->diffForHumans() }}</summary><pre class="mono mt-2 overflow-auto bg-paper p-3">{{ $u->payload }}</pre></details>@empty<p class="px-5 py-6 text-sm text-muted">Tidak ada.</p>@endforelse</section>
    <section class="card"><div class="border-b border-line px-5 py-3"><h2 class="kicker">Webhook pelanggan bermasalah</h2></div>
        @forelse($badWebhooks as $w)<div class="flex justify-between gap-3 border-b border-line px-5 py-2.5 text-sm"><span class="mono break-all">{{ $w->url }}</span><span class="mono text-red-500">{{ $w->consecutive_failures }}× gagal{{ $w->is_active ? '' : ' · nonaktif' }}</span></div>@empty<p class="px-5 py-6 text-sm text-muted">Semua sehat.</p>@endforelse</section>
</div>
@endsection
