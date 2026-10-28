@extends('layouts.app')
@section('title', 'Webhooks')
@section('content')
@php $canWrite = $currentRole->atLeast(\App\Enums\MemberRole::DEVELOPER); $isAdmin = $currentRole->atLeast(\App\Enums\MemberRole::ADMIN); @endphp
<x-page-header title="Webhooks" sub="Terima event email sebagai HTTP POST bertanda tangan HMAC-SHA256." />

@if($canWrite)
<form method="POST" action="{{ route('console.webhooks.store') }}" class="card mb-6 space-y-4 p-5">
    @csrf
    <div>
        <label class="label" for="url">URL endpoint (HTTPS)</label>
        <input class="input mono" id="url" name="url" type="url" value="{{ old('url') }}" placeholder="https://contoh.com/webhooks/postivo" required>
        @error('url')<p class="err">{{ $message }}</p>@enderror
    </div>
    <fieldset>
        <legend class="label">Event</legend>
        <div class="flex flex-wrap gap-x-5 gap-y-2">
            @foreach($events as $e)
                <label class="flex items-center gap-1.5 text-sm"><input type="checkbox" name="events[]" value="{{ $e }}" class="accent-red-500" @checked(in_array($e, old('events', ['*'])))><span class="mono">{{ $e === '*' ? 'semua event' : $e }}</span></label>
            @endforeach
        </div>
        @error('events')<p class="err">{{ $message }}</p>@enderror
    </fieldset>
    <button class="btn-primary"><x-icon name="plus" />Buat webhook</button>
</form>
@endif

@if($endpoints->isEmpty())
    <x-empty-state icon="webhook" title="Belum ada webhook" text="Buat endpoint untuk menerima email.delivered, email.bounced, dan event lain." />
@else
    <div class="space-y-3">
        @foreach($endpoints as $e)
            <div class="card flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                <div class="min-w-0">
                    <p class="mono break-all font-bold">{{ $e->url }}</p>
                    <p class="mono mt-1 text-muted">{{ implode(', ', $e->events) }}@if($e->consecutive_failures) · <span class="text-red-500">{{ $e->consecutive_failures }} gagal beruntun</span>@endif</p>
                </div>
                <div class="flex items-center gap-2">
                    <span @class(['mono border px-1.5 py-0.5 text-[11px] uppercase', 'border-ok text-ok' => $e->is_active, 'border-red-500 text-red-500' => !$e->is_active])>{{ $e->is_active ? 'Aktif' : 'Nonaktif' }}</span>
                    @if($canWrite)<form method="POST" action="{{ route('console.webhooks.toggle', $e->id) }}">@csrf<button class="btn-outline btn-sm">{{ $e->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button></form>@endif
                    @if($isAdmin)<form method="POST" action="{{ route('console.webhooks.destroy', $e->id) }}" data-confirm="Hapus webhook ini?">@csrf @method('DELETE')<button class="btn-danger btn-sm" aria-label="Hapus"><x-icon name="trash" class="size-3.5" /></button></form>@endif
                </div>
            </div>
        @endforeach
    </div>

    <section class="card mt-8">
        <div class="border-b border-line px-5 py-3"><h2 class="kicker">Pengiriman terakhir</h2></div>
        @forelse($deliveries as $dl)
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-5 py-2.5 last:border-b-0">
                <span class="mono">evt_{{ \Illuminate\Support\Str::limit($dl->event_public_id, 13, '') }} · percobaan {{ $dl->attempt }}</span>
                <span class="mono {{ $dl->status === 'SUCCESS' ? 'text-ok' : ($dl->status === 'FAILED' ? 'text-red-500' : 'text-warn') }}">{{ $dl->status }} {{ $dl->http_status }} {{ $dl->duration_ms ? $dl->duration_ms.'ms' : '' }}</span>
            </div>
        @empty
            <p class="px-5 py-6 text-sm text-muted">Belum ada pengiriman.</p>
        @endforelse
    </section>
@endif

<details class="card mt-8">
    <summary class="cursor-pointer px-5 py-3 text-sm font-semibold">Cara memverifikasi tanda tangan</summary>
    <div class="space-y-2 border-t border-line p-5 text-sm">
        <p>Header <code class="mono">Postivo-Signature: t=&lt;unix&gt;,v1=&lt;hex&gt;</code> dengan <code class="mono">v1 = HMAC_SHA256(secret, "{t}.{raw_body}")</code>. Tolak bila <code class="mono">|now − t| &gt; 5 menit</code>. Pengiriman at-least-once — jadikan handler idempoten pada <code class="mono">Postivo-Event-Id</code>.</p>
    </div>
</details>
@endsection
