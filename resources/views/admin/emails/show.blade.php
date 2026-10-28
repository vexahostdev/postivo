@extends('layouts.admin')
@section('title', $email->subject)
@section('content')
<a href="{{ route('admin.emails.index') }}" class="mono text-muted hover:underline">← Email</a>
<x-page-header :title="$email->subject" :sub="'Ke '.$d->recipient.' · '.($org->name ?? '—')"><x-status-pill :status="$d->status" /></x-page-header>
<div class="grid grid-cols-1 gap-6 lg:grid-cols-3" x-data="{ tab: 'text' }">
    <div class="space-y-6 lg:col-span-2">
        <section class="card">
            <div class="flex gap-1 border-b border-line px-3 py-2">@foreach(['text' => 'Teks', 'raw' => 'Raw MIME'] as $k => $l)<button @click="tab = '{{ $k }}'" class="mono px-2.5 py-1 uppercase tracking-wide" :class="tab === '{{ $k }}' ? 'bg-red-500 text-white' : 'text-muted'">{{ $l }}</button>@endforeach</div>
            <div x-show="tab === 'text'"><pre class="mono max-h-96 overflow-auto whitespace-pre-wrap p-4">{{ $email->text_body ?? ($email->html_body ? '(hanya HTML — pratinjau dinonaktifkan di admin demi keamanan)' : 'Isi sudah dihapus oleh retensi.') }}</pre></div>
            <div x-cloak x-show="tab === 'raw'"><pre class="mono max-h-96 overflow-auto whitespace-pre-wrap break-all p-4">{{ $raw ?? 'MIME mentah tidak tersedia.' }}</pre></div>
        </section>
        <section class="card">
            <div class="border-b border-line px-5 py-3"><h2 class="kicker">Timeline</h2></div>
            <ol class="p-5">@forelse($d->events as $ev)<li class="flex gap-4 border-l border-line pb-4 pl-5 last:pb-0"><time class="mono w-24 shrink-0 text-muted">{{ $ev->occurred_at->format('H:i:s.v') }}</time><div><p class="text-sm font-semibold capitalize">{{ $ev->type }}</p>@if($ev->data)<p class="mono break-all text-muted">{{ json_encode($ev->data) }}</p>@endif</div></li>@empty<li class="text-sm text-muted">Belum ada event.</li>@endforelse</ol>
        </section>
    </div>
    <aside class="card h-fit"><dl class="divide-y divide-line text-sm">
        @foreach([['Organisasi', $org->name ?? '—'], ['Dari', $email->from_address], ['Domain', $email->domain?->domain_name], ['Sumber', $email->source], ['Relay', $d->relay ?? '—'], ['Percobaan', $d->attempts], ['Message-ID', $d->message_id ?? '—'], ['Respons SMTP', $d->smtp_response ?? '—'], ['Email ID', $email->id], ['Dispatch ID', $d->id]] as [$k, $v])
            <div class="px-5 py-2.5"><dt class="kicker">{{ $k }}</dt><dd class="mono mt-0.5 break-all">{{ $v }}</dd></div>
        @endforeach
    </dl></aside>
</div>
@endsection
