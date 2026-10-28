@extends('layouts.admin')
@section('title', 'Order & pembayaran')
@section('content')
@php
    $rp = fn ($n) => 'Rp '.number_format($n, 0, ',', '.');
    $tone = ['PAID' => 'border-ok text-ok', 'PENDING' => 'border-warn text-warn', 'EXPIRED' => 'border-line text-muted', 'FAILED' => 'border-red-500 text-red-500'];
    $can = auth()->user()->staffCan('billing.manage');
@endphp
<x-page-header title="Order & pembayaran" sub="Semua tagihan lintas organisasi dan log webhook Xendit." />
<div class="grid grid-cols-1 gap-px border border-line bg-line sm:grid-cols-3">
    <div class="bg-white p-5"><p class="kicker">Pendapatan bulan ini</p><p class="mt-2 text-2xl font-semibold tabular-nums">{{ $rp($revMonth) }}</p></div>
    <div class="bg-white p-5"><p class="kicker">Pendapatan 30 hari</p><p class="mt-2 text-2xl font-semibold tabular-nums">{{ $rp($rev30) }}</p></div>
    <div class="bg-white p-5"><p class="kicker">Order pending</p><p class="mt-2 text-2xl font-semibold tabular-nums">{{ $pending }}</p></div>
</div>

<form method="GET" class="my-4 flex flex-wrap gap-2">
    <input class="input min-w-0 flex-1 sm:max-w-sm" name="q" value="{{ request('q') }}" placeholder="Referensi PSV-…, invoice Xendit, atau nama org" aria-label="Cari">
    <select class="input w-auto" name="status" onchange="this.form.submit()" aria-label="Status"><option value="">Semua status</option>@foreach(['PENDING','PAID','EXPIRED','FAILED'] as $s)<option value="{{ $s }}" @selected(request('status') === $s)>{{ $s }}</option>@endforeach</select>
    <button class="btn-outline">Cari</button>
</form>
<div class="card overflow-x-auto">
    <table class="w-full min-w-[820px]">
        <thead><tr><th class="th">Referensi</th><th class="th">Organisasi</th><th class="th">Plan</th><th class="th">Jumlah</th><th class="th">Status</th><th class="th">Dibuat</th></tr></thead>
        <tbody>@forelse($orders as $o)
            <tr class="hover:bg-paper">
                <td class="td mono"><a class="underline-offset-2 hover:underline" href="{{ route('admin.billing.show', $o->id) }}">{{ $o->external_id }}</a></td>
                <td class="td"><a class="underline-offset-2 hover:underline" href="{{ route('admin.organizations.show', $o->organization_id) }}">{{ $orgs[$o->organization_id]->name ?? '—' }}</a></td>
                <td class="td">{{ $o->planName() }} · {{ $o->months }}bln</td>
                <td class="td mono">{{ $rp($o->amount) }}</td>
                <td class="td"><span class="mono border px-1.5 py-0.5 text-[11px] uppercase {{ $tone[$o->status] ?? '' }}">{{ $o->status }}</span></td>
                <td class="td mono text-muted">{{ $o->created_at->diffForHumans() }}</td>
            </tr>
        @empty<tr><td class="td text-muted" colspan="6">Tidak ada order.</td></tr>@endforelse</tbody>
    </table>
</div>
<div class="mt-4">{{ $orders->links() }}</div>

<section class="card mt-8">
    <div class="border-b border-line px-5 py-3"><h2 class="kicker">Log webhook Xendit (terbaru)</h2></div>
    <div class="overflow-x-auto">
    <table class="w-full min-w-[860px]">
        <thead><tr><th class="th">Event ID</th><th class="th">Tipe</th><th class="th">Status</th><th class="th">Diterima</th><th class="th"></th></tr></thead>
        <tbody>@forelse($events as $e)
            <tr>
                <td class="td mono break-all">{{ $e->event_id }}</td><td class="td mono break-all">{{ $e->event_type }}</td>
                <td class="td mono {{ $e->processing_status === 'failed' ? 'text-red-500' : ($e->processing_status === 'processed' ? 'text-ok' : 'text-muted') }}">{{ $e->processing_status }}@if($e->processing_error) <span class="text-muted">— {{ $e->processing_error }}</span>@endif</td>
                <td class="td mono whitespace-nowrap text-muted">{{ $e->created_at->diffForHumans() }}</td>
                <td class="td text-right">@if($can && $e->processing_status === 'failed')<form method="POST" action="{{ route('admin.billing.reprocess', $e->id) }}">@csrf<button class="btn-outline btn-sm">Proses ulang</button></form>@endif</td>
            </tr>
        @empty<tr><td class="td text-muted" colspan="5">Belum ada event.</td></tr>@endforelse</tbody>
    </table>
    </div>
</section>
@endsection
