@extends('layouts.admin')
@section('title', $order->external_id)
@section('content')
@php $can = auth()->user()->staffCan('billing.manage'); $rp = fn ($n) => 'Rp '.number_format($n, 0, ',', '.'); @endphp
<a href="{{ route('admin.billing.index') }}" class="mono text-muted hover:underline">← Order</a>
<x-page-header :title="$order->external_id" :sub="($org->name ?? '—').' · '.$order->planName().' '.$order->months.' bulan'"><span class="mono border border-ink px-1.5 py-0.5 uppercase">{{ $order->status }}</span></x-page-header>
<div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <section class="card"><div class="border-b border-line px-5 py-3"><h2 class="kicker">Rincian</h2></div>
            <dl class="grid grid-cols-1 gap-px bg-line text-sm sm:grid-cols-2">
                @foreach([['Jumlah', $rp($order->amount)], ['Metode', $order->payment_method ?? '—'], ['Invoice Xendit', $order->xendit_invoice_id ?? '—'], ['Dibuat', $order->created_at->format('d M Y H:i')], ['Kedaluwarsa', $order->expires_at?->format('d M Y H:i') ?? '—'], ['Dibayar', $order->paid_at?->format('d M Y H:i') ?? '—']] as [$k, $v])
                    <div class="bg-white p-4"><dt class="kicker">{{ $k }}</dt><dd class="mono mt-1 break-all">{{ $v }}</dd></div>
                @endforeach
            </dl>
        </section>
        <section class="card"><div class="border-b border-line px-5 py-3"><h2 class="kicker">Event webhook terkait</h2></div>
            @forelse($events as $e)<details class="border-b border-line px-5 py-2.5 text-sm"><summary class="mono cursor-pointer">{{ $e->event_type }} · {{ $e->processing_status }} · {{ $e->created_at->diffForHumans() }}</summary><pre class="mono mt-2 overflow-auto bg-paper p-3">{{ json_encode($e->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details>@empty<p class="px-5 py-6 text-sm text-muted">Belum ada event.</p>@endforelse
        </section>
    </div>
    @if($can && $order->status !== 'PAID')
    <aside class="card h-fit space-y-4 p-5">
        <h2 class="kicker">Tindakan</h2>
        <form method="POST" action="{{ route('admin.billing.mark-paid', $order->id) }}" class="space-y-2">@csrf
            <label class="label" for="mp">Tandai lunas manual</label>
            <input class="input" id="mp" name="reason" required minlength="10" placeholder="Bukti pembayaran (min. 10 karakter)">
            <button class="btn-primary w-full" data-confirm="Aktifkan plan tanpa konfirmasi Xendit?">Tandai lunas &amp; aktifkan plan</button>
        </form>
        @if($order->status === 'PENDING')
        <form method="POST" action="{{ route('admin.billing.expire', $order->id) }}" class="space-y-2 border-t border-line pt-3">@csrf
            <input class="input" name="reason" required minlength="5" placeholder="Alasan" aria-label="Alasan">
            <button class="btn-danger w-full">Kedaluwarsakan order</button>
        </form>
        @endif
    </aside>
    @endif
</div>
@endsection
