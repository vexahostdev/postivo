@extends('layouts.admin')
@section('title', 'Abuse & reputasi')
@section('content')
@php $tone = ['OK' => 'border-ok text-ok', 'WARN' => 'border-warn text-warn', 'DANGER' => 'border-red-500 text-red-500']; $s = \App\Services\PlatformSettings::all(); @endphp
<x-page-header title="Abuse & reputasi" :sub="'Per organisasi, '.$days.' hari terakhir. Dinilai bila volume ≥ '.$s['abuse_min_volume'].' email.'">
    @foreach([1 => '24 jam', 7 => '7 hari', 30 => '30 hari'] as $d => $l)<a href="{{ route('admin.abuse', ['days' => $d] + request()->only('level')) }}" @class(['btn-sm', 'btn-primary' => $days === $d, 'btn-outline' => $days !== $d])>{{ $l }}</a>@endforeach
</x-page-header>
<div class="mb-4 flex flex-wrap gap-2 text-sm">
    @foreach(['' => 'Semua', 'DANGER' => 'Bahaya', 'WARN' => 'Waspada', 'OK' => 'OK'] as $k => $l)<a href="{{ route('admin.abuse', array_filter(['days' => $days, 'level' => $k])) }}" @class(['btn-sm', 'btn-ghost underline' => request('level', '') !== $k, 'btn-outline' => request('level', '') === $k])>{{ $l }}</a>@endforeach
    <span class="hint self-center">Ambang: bounce waspada ≥ {{ $s['abuse_bounce_warn'] }}% / bahaya &gt; {{ $s['abuse_bounce_danger'] }}% · komplain waspada ≥ {{ $s['abuse_complaint_warn'] }}% / bahaya &gt; {{ $s['abuse_complaint_danger'] }}% · auto-suspend: {{ $s['abuse_auto_suspend'] ? 'AKTIF' : 'mati' }}</span>
</div>
<div class="card overflow-x-auto">
    <table class="w-full min-w-[820px]">
        <thead><tr><th class="th">Organisasi</th><th class="th">Level</th><th class="th">Volume</th><th class="th">Bounce</th><th class="th">Komplain</th><th class="th">Status</th></tr></thead>
        <tbody>@forelse($rows as $r)
            <tr class="hover:bg-paper">
                <td class="td"><a class="font-semibold underline-offset-2 hover:underline" href="{{ route('admin.organizations.show', $r['org']->id) }}">{{ $r['org']->name }}</a><p class="mono text-muted">{{ $r['org']->plan }}</p></td>
                <td class="td"><span class="mono border px-1.5 py-0.5 text-[11px] uppercase {{ $tone[$r['level']] }}">{{ $r['level'] }}</span></td>
                <td class="td mono tabular-nums">{{ number_format($r['volume']) }}</td>
                <td class="td mono tabular-nums">{{ $r['bounce_rate'] }}% <span class="text-muted">({{ $r['bounced'] }})</span></td>
                <td class="td mono tabular-nums">{{ $r['complaint_rate'] }}% <span class="text-muted">({{ $r['complained'] }})</span></td>
                <td class="td"><x-status-pill :status="$r['org']->isSuspended() ? 'SUSPENDED' : 'ACTIVE'" /></td>
            </tr>
        @empty<tr><td class="td text-muted" colspan="6">Belum ada data pengiriman pada rentang ini.</td></tr>@endforelse</tbody>
    </table>
</div>
@endsection
