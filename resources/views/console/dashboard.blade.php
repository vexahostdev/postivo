@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')
@php
    $pct = $quota ? min(100, round($sent / $quota * 100)) : 0;
    $activeDomain = $domains->firstWhere('status', 'ACTIVE');
    $total = $stats->sum();
    $cards = [
        ['Terkirim (30 hari)', ($stats['DELIVERED'] ?? 0) + ($stats['SENT'] ?? 0) + ($stats['COMPLAINED'] ?? 0), null],
        ['Delivered', $stats['DELIVERED'] ?? 0, null],
        ['Bounced / Failed', ($stats['BOUNCED'] ?? 0) + ($stats['FAILED'] ?? 0), 'red'],
        ['Suppressed', $stats['SUPPRESSED'] ?? 0, null],
    ];
@endphp

<x-page-header title="Dashboard" :sub="$currentOrg->name" />

{{-- Onboarding: tampil sampai ada domain aktif --}}
@unless($activeDomain)
    <div class="mb-6 border border-red-500 bg-red-50 p-5">
        <p class="text-sm font-semibold text-red-600">Mulai mengirim dalam 3 langkah</p>
        <ol class="mt-2 list-decimal space-y-1 pl-5 text-sm">
            <li>Tambahkan domain pengirim dan pasang record DNS-nya.</li>
            <li>Tunggu semua record terverifikasi (status <b>Active</b>).</li>
            <li>Buat API key lalu panggil <code class="mono">POST /v1/emails</code>.</li>
        </ol>
        <a href="{{ route('console.domains.index') }}" class="btn-primary btn-sm mt-3">Tambah domain</a>
    </div>
@endunless

<div class="grid grid-cols-1 gap-px border border-line bg-line sm:grid-cols-2 lg:grid-cols-4">
    @foreach($cards as [$label, $value, $tone])
        <div class="bg-white p-5">
            <p class="kicker">{{ $label }}</p>
            <p @class(['mt-2 text-3xl font-semibold tracking-tight tabular-nums', 'text-red-500' => $tone === 'red' && $value > 0])>{{ number_format($value) }}</p>
        </div>
    @endforeach
</div>

<div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
    <section class="card lg:col-span-2">
        <div class="flex items-center justify-between border-b border-line px-5 py-3"><h2 class="kicker">Aktivitas 14 hari</h2></div>
        <div class="p-5" x-data="sendChart('{{ route('console.chart') }}')">
            <div x-cloak x-show="!empty" class="h-64"><canvas x-ref="canvas" aria-label="Grafik email delivered dan bounced per hari" role="img"></canvas></div>
            <p x-cloak x-show="empty" class="py-16 text-center text-sm text-muted">Belum ada aktivitas. Kirim email pertama untuk melihat grafik.</p>
        </div>
    </section>

    <section class="card">
        <div class="border-b border-line px-5 py-3"><h2 class="kicker">Kuota bulan ini</h2></div>
        <div class="p-5">
            <p class="text-2xl font-semibold tabular-nums">{{ number_format($sent) }} <span class="text-sm font-normal text-muted">/ {{ number_format($quota) }}</span></p>
            <div class="mt-3 h-1.5 w-full bg-line" role="progressbar" aria-valuenow="{{ $pct }}" aria-valuemin="0" aria-valuemax="100" aria-label="Pemakaian kuota">
                <div class="h-full {{ $pct >= 90 ? 'bg-red-500' : 'bg-ink' }}" style="width: {{ $pct }}%"></div>
            </div>
            <p class="hint">{{ $pct }}% terpakai @if($currentOrg->daily_limit) · batas harian {{ number_format($currentOrg->daily_limit) }} @endif</p>
        </div>
    </section>
</div>

<div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
    <section class="card lg:col-span-2">
        <div class="flex items-center justify-between border-b border-line px-5 py-3">
            <h2 class="kicker">Email terbaru</h2>
            <a class="text-xs font-semibold text-red-500 underline" href="{{ route('console.emails.index') }}">Lihat semua</a>
        </div>
        @forelse($recent as $d)
            <a href="{{ route('console.emails.show', $d->id) }}" class="flex items-center justify-between gap-3 border-b border-line px-5 py-3 last:border-b-0 hover:bg-paper">
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium">{{ $d->email->subject }}</p>
                    <p class="mono truncate text-muted">{{ $d->recipient }}</p>
                </div>
                <div class="flex shrink-0 items-center gap-3">
                    <span class="mono hidden text-muted sm:inline">{{ $d->created_at->diffForHumans() }}</span>
                    <x-status-pill :status="$d->status" />
                </div>
            </a>
        @empty
            <div class="p-5"><x-empty-state title="Belum ada email" text="Email yang dikirim lewat API atau SMTP akan muncul di sini." /></div>
        @endforelse
    </section>

    <section class="card">
        <div class="border-b border-line px-5 py-3"><h2 class="kicker">Kirim lewat API</h2></div>
        <div class="p-5">
            <pre class="mono overflow-x-auto border border-line bg-paper p-3 leading-relaxed">curl {{ url('/v1/emails') }} \
  -H "Authorization: Bearer $KEY" \
  -H "Content-Type: application/json" \
  -d '{"from":"Nama &lt;noreply@domain-anda.com&gt;",
       "to":["user@example.com"],
       "subject":"Halo","text":"Tes"}'</pre>
            <a href="{{ route('console.api-keys.index') }}" class="btn-outline btn-sm mt-3"><x-icon name="key" class="size-3.5" />Kelola API key</a>
        </div>
    </section>
</div>
@endsection
