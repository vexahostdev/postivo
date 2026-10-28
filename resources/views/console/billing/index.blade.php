@extends('layouts.app')
@section('title', 'Billing')
@section('content')
@php
    $canBuy = $currentRole->atLeast(\App\Enums\MemberRole::ADMIN);
    $rp = fn ($n) => 'Rp '.number_format($n, 0, ',', '.');
    $pct = $currentOrg->monthly_quota ? min(100, round($used / $currentOrg->monthly_quota * 100)) : 0;
    $statusStyle = ['PAID' => 'border-ok text-ok', 'PENDING' => 'border-warn text-warn', 'EXPIRED' => 'border-line text-muted', 'FAILED' => 'border-red-500 text-red-500'];
@endphp
<x-page-header title="Billing" sub="Paket, pemakaian, dan riwayat tagihan." />

<section class="card mb-8 grid grid-cols-1 gap-px bg-line sm:grid-cols-3">
    <div class="bg-white p-5"><p class="kicker">Paket aktif</p><p class="mt-2 text-2xl font-semibold">{{ ucfirst($currentOrg->plan) }}</p>
        <p class="hint">{{ $currentOrg->plan_expires_at ? 'Aktif sampai '.$currentOrg->plan_expires_at->timezone('Asia/Jakarta')->format('d M Y') : 'Tanpa masa berlaku' }}</p></div>
    <div class="bg-white p-5"><p class="kicker">Pemakaian bulan ini</p><p class="mt-2 text-2xl font-semibold tabular-nums">{{ number_format($used) }} <span class="text-sm font-normal text-muted">/ {{ number_format($currentOrg->monthly_quota) }}</span></p>
        <div class="mt-2 h-1.5 bg-line"><div class="h-full {{ $pct >= 90 ? 'bg-red-500' : 'bg-ink' }}" style="width: {{ $pct }}%"></div></div></div>
    <div class="bg-white p-5"><p class="kicker">Batas harian</p><p class="mt-2 text-2xl font-semibold">{{ $currentOrg->daily_limit ? number_format($currentOrg->daily_limit) : 'Tanpa batas' }}</p></div>
</section>

<h2 class="kicker mb-3">Pilih paket</h2>
<div class="grid grid-cols-1 gap-px border border-line bg-line sm:grid-cols-2 xl:grid-cols-4">
    @foreach($plans as $key => $p)
        @php $current = $currentOrg->plan === $key; @endphp
        <div class="flex flex-col bg-white p-5">
            <div class="flex items-center justify-between"><h3 class="text-lg font-semibold">{{ $p['name'] }}</h3>@if($current)<span class="mono border border-red-500 px-1.5 text-[11px] uppercase text-red-500">Aktif</span>@endif</div>
            <p class="mt-3 text-3xl font-semibold tracking-tight tabular-nums">{{ $p['price'] ? $rp($p['price']) : 'Gratis' }}<span class="text-sm font-normal text-muted">{{ $p['price'] ? ' /bulan' : '' }}</span></p>
            <ul class="mt-4 flex-1 space-y-1.5 text-sm">@foreach($p['features'] as $f)<li class="flex gap-2"><x-icon name="check" class="mt-0.5 size-4 shrink-0 text-ok" />{{ $f }}</li>@endforeach</ul>
            @if($p['price'] > 0)
                @if($canBuy)<a href="{{ route('console.billing.checkout', $key) }}" class="{{ $current ? 'btn-outline' : 'btn-primary' }} mt-5">{{ $current ? 'Perpanjang' : 'Pilih '.$p['name'] }}</a>
                @else<p class="hint mt-5">Hanya owner/admin yang dapat membeli.</p>@endif
            @endif
        </div>
    @endforeach
</div>
<p class="hint">Harga dalam rupiah, belum termasuk pajak bila berlaku. Pembayaran diproses Xendit (QRIS, virtual account, minimarket, e-wallet).</p>

<h2 class="kicker mb-3 mt-10">Riwayat tagihan</h2>
@if($orders->isEmpty())
    <x-empty-state icon="card" title="Belum ada tagihan" text="Tagihan akan muncul di sini setelah Anda memilih paket berbayar." />
@else
    <div class="card overflow-x-auto">
        <table class="w-full min-w-[640px]">
            <thead><tr><th class="th">Referensi</th><th class="th">Paket</th><th class="th">Jumlah</th><th class="th">Status</th><th class="th">Dibuat</th></tr></thead>
            <tbody>@foreach($orders as $o)
                <tr class="hover:bg-paper">
                    <td class="td mono"><a class="underline-offset-2 hover:underline" href="{{ route('console.billing.orders.show', $o->id) }}">{{ $o->external_id }}</a></td>
                    <td class="td">{{ $o->planName() }} · {{ $o->months }} bln</td>
                    <td class="td mono">{{ $rp($o->amount) }}</td>
                    <td class="td"><span class="mono border px-1.5 py-0.5 text-[11px] uppercase {{ $statusStyle[$o->status] ?? '' }}">{{ $o->status }}</span></td>
                    <td class="td mono text-muted">{{ $o->created_at->diffForHumans() }}</td>
                </tr>
            @endforeach</tbody>
        </table>
    </div>
@endif
@endsection
