@extends('layouts.app')
@section('title', $domain->domain_name)
@section('content')
@php
    $canWrite = $currentRole->atLeast(\App\Enums\MemberRole::DEVELOPER);
    $labels = ['dkim' => 'DKIM', 'spf' => 'SPF', 'return_path' => 'Return-Path', 'dmarc' => 'DMARC', 'tracking' => 'Tracking'];
    $initial = [
        'status' => $domain->status,
        'records' => $domain->records->map(fn ($r) => [
            'purpose' => $r->purpose, 'label' => $labels[$r->purpose] ?? $r->purpose, 'type' => $r->type, 'name' => $r->name, 'value' => $r->value,
            'required' => $r->is_required, 'verified' => $r->is_verified, 'seen' => $r->last_seen_value,
        ])->values(),
    ];
@endphp
<a href="{{ route('console.domains.index') }}" class="mono text-muted hover:underline">← Domains</a>

<div x-data="dnsAuditor('{{ route('console.domains.verify', $domain->id) }}', @js($initial))">
    <x-page-header :title="$domain->domain_name" sub="Pasang record berikut di penyedia DNS domain Anda.">
        <span x-show="false"></span>
        @if($canWrite)
            <button type="button" class="btn-primary" @click="verify()" :disabled="busy || cooldown > 0">
                <x-icon name="refresh" x-bind:class="busy && 'animate-spin'" />
                <span x-text="busy ? 'Memeriksa…' : (cooldown > 0 ? `Tunggu ${cooldown}s` : 'Verifikasi sekarang')"></span>
            </button>
        @endif
    </x-page-header>

    <div class="mb-4 flex flex-wrap items-center gap-3" aria-live="polite">
        <span class="kicker">Status</span>
        <span class="mono border px-1.5 py-0.5 uppercase"
              :class="{ 'border-ok text-ok': status === 'ACTIVE', 'border-warn text-warn': status === 'PENDING', 'border-red-500 text-red-500': status === 'FAILED' || status === 'SUSPENDED' }" x-text="status"></span>
        <span class="text-sm text-muted" x-text="message"></span>
    </div>

    <p class="mb-4 text-sm text-muted">Domain menjadi <b class="text-ink">Active</b> bila DKIM, SPF, dan Return-Path lolos. DMARC dan Tracking opsional tetapi disarankan. Propagasi DNS dapat memakan waktu hingga beberapa jam.</p>

    <div class="space-y-3">
        <template x-for="r in records" :key="r.purpose">
            <div class="card" x-data="clipboard()">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-5 py-2.5">
                    <div class="flex items-center gap-2">
                        <span class="mono font-bold" x-text="r.label"></span>
                        <span class="mono text-muted" x-text="r.type"></span>
                        <span class="kicker" x-text="r.required ? 'wajib' : 'opsional'"></span>
                    </div>
                    <span class="mono inline-flex items-center gap-1 border px-1.5 py-0.5 text-[11px] uppercase"
                          :class="r.verified ? 'border-ok text-ok' : (r.required ? 'border-warn text-warn' : 'border-line text-muted')"
                          x-text="r.verified ? '✓ Terverifikasi' : '… Belum terdeteksi'"></span>
                </div>
                <div class="grid gap-3 p-5 sm:grid-cols-[88px_1fr]">
                    <span class="kicker pt-1">Host</span>
                    <div class="flex items-center gap-2"><code class="mono flex-1 break-all border border-line bg-paper px-2.5 py-1.5" x-text="r.name"></code>
                        <button type="button" class="btn-outline btn-sm" @click="copy(r.name)" aria-label="Salin host"><x-icon name="copy" class="size-3.5" /></button></div>
                    <span class="kicker pt-1">Value</span>
                    <div class="flex items-center gap-2"><code class="mono flex-1 break-all border border-line bg-paper px-2.5 py-1.5" x-text="r.value"></code>
                        <button type="button" class="btn-outline btn-sm" @click="copy(r.value)" aria-label="Salin value"><x-icon name="copy" class="size-3.5" /></button></div>
                    <template x-if="r.seen && !r.verified">
                        <span class="kicker pt-1">Terdeteksi</span>
                    </template>
                    <template x-if="r.seen && !r.verified"><code class="mono break-all text-warn" x-text="r.seen"></code></template>
                </div>
            </div>
        </template>
    </div>
</div>

@if($canWrite)
<section class="card mt-8">
    <div class="border-b border-line px-5 py-3"><h2 class="kicker">Pengaturan tracking bawaan</h2></div>
    <form method="POST" action="{{ route('console.domains.update', $domain->id) }}" class="space-y-3 p-5">
        @csrf @method('PATCH')
        <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="track_opens_default" value="1" class="mt-1 accent-red-500" @checked($domain->track_opens_default)>
            <span><b>Lacak pembukaan</b> — menyisipkan piksel 1×1.</span></label>
        <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="track_clicks_default" value="1" class="mt-1 accent-red-500" @checked($domain->track_clicks_default)>
            <span><b>Lacak klik</b> — menulis ulang link. <span class="text-red-600">Jangan aktifkan untuk email OTP / reset password.</span></span></label>
        <button class="btn-outline btn-sm">Simpan</button>
    </form>
</section>
@endif

@if($currentRole->atLeast(\App\Enums\MemberRole::ADMIN))
<form method="POST" action="{{ route('console.domains.destroy', $domain->id) }}" class="mt-6" onsubmit="return confirm('Hapus domain {{ $domain->domain_name }}? Email baru dari domain ini akan ditolak.')">
    @csrf @method('DELETE')
    <button class="btn-danger btn-sm"><x-icon name="trash" class="size-3.5" />Hapus domain</button>
</form>
@endif
@endsection
