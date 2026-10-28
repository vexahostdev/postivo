@extends('layouts.app')
@section('title', 'Checkout '.$plan['name'])
@section('content')
@php $rp = fn ($n) => 'Rp '.number_format($n, 0, ',', '.'); @endphp
<a href="{{ route('console.billing.index') }}" class="mono text-muted hover:underline">← Billing</a>
<x-page-header title="Checkout" :sub="'Paket '.$plan['name']" />

<div class="grid grid-cols-1 gap-6 lg:grid-cols-3" x-data="{ months: {{ $months }}, price: {{ $plan['price'] }}, fmt(n) { return 'Rp ' + n.toLocaleString('id-ID'); } }">
    <form method="POST" action="{{ route('console.billing.store', $planKey) }}" class="card space-y-6 p-6 lg:col-span-2">
        @csrf
        <fieldset>
            <legend class="label">Durasi</legend>
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-4">
                @foreach([1 => '1 bulan', 3 => '3 bulan', 6 => '6 bulan', 12 => '12 bulan'] as $m => $label)
                    <label class="flex cursor-pointer items-center gap-2 border px-3 py-2.5 text-sm" :class="months == {{ $m }} ? 'border-red-500 bg-red-50' : 'border-line'">
                        <input type="radio" name="months" value="{{ $m }}" x-model.number="months" class="accent-red-500"> {{ $label }}
                    </label>
                @endforeach
            </div>
        </fieldset>

        <div>
            <p class="label">Metode pembayaran</p>
            <p class="text-sm text-muted">Anda akan diarahkan ke halaman pembayaran aman Xendit untuk memilih QRIS, virtual account (BCA, BNI, BRI, Mandiri, Permata, CIMB), Indomaret/Alfamart, atau e-wallet.</p>
        </div>

        @unless($configured)
            <p class="border border-warn px-3 py-2 text-sm text-warn">Gateway pembayaran belum dikonfigurasi di lingkungan ini (XENDIT_SECRET_KEY kosong). Order tetap dibuat; {{ config('postivo.billing.allow_simulator') && !app()->isProduction() ? 'Anda dapat menyimulasikan pembayaran di halaman order.' : 'hubungi support untuk bantuan.' }}</p>
        @endunless

        <button class="btn-primary w-full sm:w-auto"><x-icon name="card" />Buat tagihan &amp; bayar</button>
    </form>

    <aside class="card h-fit">
        <div class="border-b border-line px-5 py-3"><h2 class="kicker">Ringkasan</h2></div>
        <dl class="divide-y divide-line text-sm">
            <div class="flex justify-between px-5 py-3"><dt>Paket</dt><dd class="font-semibold">{{ $plan['name'] }}</dd></div>
            <div class="flex justify-between px-5 py-3"><dt>Kuota</dt><dd class="mono">{{ number_format($plan['monthly_quota']) }} /bln</dd></div>
            <div class="flex justify-between px-5 py-3"><dt>Harga</dt><dd class="mono">{{ $rp($plan['price']) }} /bln</dd></div>
            <div class="flex justify-between px-5 py-3"><dt>Durasi</dt><dd class="mono" x-text="months + ' bulan'"></dd></div>
            <div class="flex justify-between px-5 py-4 text-base font-semibold"><dt>Total</dt><dd class="mono" x-text="fmt(price * months)"></dd></div>
        </dl>
    </aside>
</div>
@endsection
