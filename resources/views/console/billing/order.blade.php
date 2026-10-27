@extends('layouts.app')
@section('title', 'Tagihan '.$order->external_id)
@section('content')
@php
    $rp = fn ($n) => 'Rp '.number_format($n, 0, ',', '.');
    $tone = ['PAID' => 'border-ok text-ok', 'PENDING' => 'border-warn text-warn', 'EXPIRED' => 'border-line text-muted', 'FAILED' => 'border-red-500 text-red-500'][$order->status] ?? '';
    $canPay = $currentRole->atLeast(\App\Enums\MemberRole::ADMIN);
@endphp
<a href="{{ route('console.billing.index') }}" class="mono text-muted hover:underline">← Billing</a>
<x-page-header :title="'Tagihan '.$order->planName()" :sub="$order->external_id">
    <span class="mono border px-1.5 py-0.5 uppercase {{ $tone }}">{{ $order->status }}</span>
</x-page-header>

<div class="grid gap-6 lg:grid-cols-3">
    <section class="card lg:col-span-2">
        <div class="border-b border-line px-5 py-3"><h2 class="kicker">Status pembayaran</h2></div>
        <div class="space-y-4 p-6 text-sm">
            @if($order->status === 'PAID')
                <p class="flex items-start gap-2 text-ok"><x-icon name="check" class="mt-0.5 size-4" />Pembayaran diterima {{ $order->paid_at?->timezone('Asia/Jakarta')->format('d M Y H:i') }} WIB. Paket <b>{{ $order->planName() }}</b> aktif sampai {{ $currentOrg->plan_expires_at?->timezone('Asia/Jakarta')->format('d M Y') }}.</p>
                <a href="{{ route('console.dashboard') }}" class="btn-primary">Ke dashboard</a>
            @elseif($order->status === 'PENDING')
                <p>Menunggu pembayaran. Batas waktu: <b>{{ $order->expires_at?->timezone('Asia/Jakarta')->format('d M Y H:i') }} WIB</b>. Halaman ini diperbarui saat Anda kembali dari Xendit; paket aktif otomatis begitu pembayaran terkonfirmasi.</p>
                @if($canPay)
                    <div class="flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('console.billing.orders.pay', $order->id) }}">@csrf<button class="btn-primary"><x-icon name="card" />{{ $order->invoice_url ? 'Lanjutkan pembayaran' : 'Buat ulang invoice' }}</button></form>
                        <a href="{{ route('console.billing.orders.show', $order->id) }}" class="btn-outline"><x-icon name="refresh" />Cek status</a>
                        @if($simulator)
                            <form method="POST" action="{{ route('console.billing.orders.simulate', $order->id) }}">@csrf<button class="btn-outline">Simulasikan pembayaran (dev)</button></form>
                        @endif
                    </div>
                @endif
            @else
                <p class="text-muted">Tagihan ini {{ $order->status === 'EXPIRED' ? 'kedaluwarsa' : 'gagal' }}. Buat tagihan baru dari halaman Billing.</p>
                <a href="{{ route('console.billing.checkout', $order->plan) }}" class="btn-outline">Buat tagihan baru</a>
            @endif
        </div>
    </section>
    <aside class="card h-fit">
        <div class="border-b border-line px-5 py-3"><h2 class="kicker">Rincian</h2></div>
        <dl class="divide-y divide-line text-sm">
            @foreach([['Paket', $order->planName()], ['Durasi', $order->months.' bulan'], ['Total', $rp($order->amount)], ['Metode', $order->payment_method ?? '—'], ['Dibuat', $order->created_at->timezone('Asia/Jakarta')->format('d M Y H:i')]] as [$k, $v])
                <div class="flex justify-between gap-4 px-5 py-2.5"><dt class="kicker">{{ $k }}</dt><dd class="mono text-right">{{ $v }}</dd></div>
            @endforeach
        </dl>
    </aside>
</div>
@endsection
