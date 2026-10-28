@extends('layouts.admin')
@section('title', 'Alat & diagnostik')
@section('content')
@php $can = auth()->user()->staffCan('system.manage'); $labels = ['STALWART' => 'Stalwart (utama)', 'BREVO' => 'Brevo (cadangan)', 'SYSTEM' => 'Email sistem (notifikasi)']; @endphp
<x-page-header title="Alat &amp; diagnostik" sub="Uji jalur kirim dan DNS platform sebelum meluncur. Semua aksi tercatat di audit." />

@if($result)
    <div class="mb-5 border px-4 py-3 text-sm {{ $result['ok'] ? 'border-ok text-ok' : 'border-red-500 text-red-600' }}" role="status">
        <p class="font-semibold">{{ $result['ok'] ? 'Berhasil' : 'Gagal' }} · {{ $labels[$result['relay']] ?? $result['relay'] }} · {{ $result['ms'] }} ms</p>
        <p class="mt-1 break-words">{{ $result['message'] }}</p>
    </div>
@endif

<section class="card mb-6 p-5">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 class="kicker">Relay pengiriman</h2>
        <p class="text-xs text-muted">Driver aktif: <code class="mono">{{ $driver }}</code>@if($driver === 'log') — email pelanggan hanya ditulis ke storage, belum dikirim sungguhan.@endif</p>
    </div>
    <div class="mt-4 divide-y divide-line">
        @foreach($relays as $key => $r)
            <div class="flex flex-wrap items-center justify-between gap-3 py-3">
                <div>
                    <p class="text-sm font-semibold">{{ $labels[$key] }}</p>
                    <p class="mono text-xs text-muted">{{ $r['configured'] ? ($r['target'] ?: '—') : 'belum dikonfigurasi' }}</p>
                </div>
                @if($can)
                    <form method="POST" action="{{ route('admin.tools.connect') }}">@csrf<input type="hidden" name="relay" value="{{ $key }}">
                        <button class="btn-outline btn-sm" @disabled(!$r['configured'])>Uji koneksi</button>
                    </form>
                @endif
            </div>
        @endforeach
    </div>
</section>

@if($can)
<section class="card mb-6 p-5">
    <h2 class="kicker">Kirim email uji</h2>
    <form method="POST" action="{{ route('admin.tools.send') }}" class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end">@csrf
        <div><label class="label" for="tr">Lewat relay</label>
            <select class="input" id="tr" name="relay">@foreach($relays as $key => $r)<option value="{{ $key }}" @disabled(!$r['configured'])>{{ $labels[$key] }}</option>@endforeach</select></div>
        <div><label class="label" for="tt">Alamat tujuan</label><input class="input" id="tt" type="email" name="to" required value="{{ old('to', auth()->user()->email) }}">@error('to')<p class="err">{{ $message }}</p>@enderror</div>
        <button class="btn-primary">Kirim uji</button>
    </form>
    <p class="mt-3 text-xs text-muted">Pengirim: {{ config('mail.from.address') }}. Periksa header di inbox tujuan: SPF, DKIM, dan DMARC harus <b>pass</b>.</p>
</section>
@endif

<section class="card p-5">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 class="kicker">DNS domain pengirim platform</h2>
        @if($can)<form method="POST" action="{{ route('admin.tools.dns') }}">@csrf<button class="btn-outline btn-sm">{{ $dns ? 'Cek ulang' : 'Jalankan pengecekan' }}</button></form>@endif
    </div>
    @if($dns)
        <p class="mt-2 text-xs text-muted">Dicek {{ $dns['at'] }} lewat DoH (Cloudflare, cadangan Google). Status ⚠ opsional tergantung relay yang dipakai.</p>
        <div class="mt-4 overflow-x-auto">
            <table class="w-full min-w-[720px] text-left text-sm">
                <thead><tr class="kicker border-b border-line"><th class="py-2 pr-3">Cek</th><th class="py-2 pr-3">Status</th><th class="py-2 pr-3">Ditemukan</th><th class="py-2">Saran</th></tr></thead>
                <tbody class="divide-y divide-line align-top">
                @foreach($dns['rows'] as $row)
                    <tr>
                        <td class="py-2.5 pr-3"><p class="font-semibold">{{ $row['label'] }}</p><p class="mono text-[11px] text-muted">{{ $row['record'] }}</p></td>
                        <td class="py-2.5 pr-3 whitespace-nowrap font-semibold {{ ['ok' => 'text-ok', 'warn' => 'text-warn', 'fail' => 'text-red-600', 'unknown' => 'text-muted'][$row['status']] }}">{{ ['ok' => '✓ OK', 'warn' => '⚠ Periksa', 'fail' => '✗ Gagal', 'unknown' => '? Tak diketahui'][$row['status']] }}</td>
                        <td class="mono py-2.5 pr-3 text-xs break-all">{{ $row['found'] }}</td>
                        <td class="py-2.5 text-xs text-muted">{{ $row['status'] === 'ok' ? '' : $row['hint'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @else
        <p class="mt-3 text-sm text-muted">Belum dijalankan. Mengecek SPF, DMARC, DKIM, host SMTP, reverse DNS, bounce, dan tracking untuk <span class="mono">{{ config('postivo.hosts.app') }}</span>.</p>
    @endif
</section>
@endsection
