@extends('layouts.app')
@section('title', $email->subject)
@section('content')
<a href="{{ route('console.emails.index') }}" class="mono text-muted hover:underline">← Emails</a>
<x-page-header :title="$email->subject" :sub="'Ke '.$d->recipient">
    <x-status-pill :status="$d->status" />
</x-page-header>

<div class="grid grid-cols-1 gap-6 lg:grid-cols-3" x-data="{ tab: 'preview' }">
    <div class="space-y-6 lg:col-span-2">
        <section class="card">
            <div class="flex gap-1 border-b border-line px-3 py-2" role="tablist">
                @foreach(['preview' => 'Preview', 'text' => 'Teks', 'raw' => 'Raw MIME'] as $k => $l)
                    <button role="tab" :aria-selected="tab === '{{ $k }}'" @click="tab = '{{ $k }}'" class="mono px-2.5 py-1 uppercase tracking-wide"
                            :class="tab === '{{ $k }}' ? 'bg-red-500 text-white' : 'text-muted hover:text-ink'">{{ $l }}</button>
                @endforeach
            </div>
            {{-- HTML pengirim dirender di iframe sandbox tanpa izin apa pun → skrip & tracking tidak jalan. --}}
            <div x-cloak x-show="tab === 'preview'">
                @if($email->html_body)
                    <iframe sandbox="" referrerpolicy="no-referrer" srcdoc="{{ $email->html_body }}" class="h-96 w-full bg-white" title="Preview email"></iframe>
                @else
                    <p class="p-6 text-sm text-muted">Body HTML tidak tersedia (tidak dikirim, atau sudah dihapus oleh kebijakan retensi {{ config('postivo.retention_days.body') }} hari).</p>
                @endif
            </div>
            <div x-cloak x-show="tab === 'text'"><pre class="mono max-h-96 overflow-auto whitespace-pre-wrap p-4">{{ $email->text_body ?? 'Tidak ada versi teks.' }}</pre></div>
            <div x-cloak x-show="tab === 'raw'">
                @if($raw)<pre class="mono max-h-96 overflow-auto whitespace-pre-wrap break-all p-4">{{ $raw }}</pre>
                @else<p class="p-6 text-sm text-muted">MIME mentah belum tersedia (email belum terkirim ke relay).</p>@endif
            </div>
        </section>

        <section class="card">
            <div class="border-b border-line px-5 py-3"><h2 class="kicker">Timeline</h2></div>
            <ol class="p-5">
                @forelse($d->events as $ev)
                    <li class="relative flex gap-4 border-l border-line pb-5 pl-5 last:pb-0">
                        <span @class(['absolute -left-[4px] top-1.5 size-[7px] rounded-full', 'bg-red-500' => in_array($ev->type, ['bounced','failed','complained']), 'bg-ink' => !in_array($ev->type, ['bounced','failed','complained'])])></span>
                        <time class="mono w-20 shrink-0 text-muted" datetime="{{ $ev->occurred_at->toIso8601String() }}">{{ $ev->occurred_at->format('H:i:s') }}</time>
                        <div class="min-w-0">
                            <p class="text-sm font-semibold capitalize">{{ $ev->type }}@if($ev->is_bot) <span class="mono font-normal text-muted">(bot / prefetch)</span>@endif</p>
                            @if($ev->data)<p class="mono break-all text-muted">{{ collect($ev->data)->map(fn ($v, $k) => $k.': '.(is_scalar($v) ? $v : json_encode($v)))->implode(' · ') }}</p>@endif
                        </div>
                    </li>
                @empty
                    <li class="text-sm text-muted">Belum ada event.</li>
                @endforelse
            </ol>
        </section>
    </div>

    <aside class="card h-fit">
        <div class="border-b border-line px-5 py-3"><h2 class="kicker">Detail</h2></div>
        <dl class="divide-y divide-line text-sm">
            @foreach([
                ['Dari', $email->from_name ? $email->from_name.' <'.$email->from_address.'>' : $email->from_address],
                ['Penerima', $d->recipient.' ('.$d->recipient_type.')'],
                ['Domain', $email->domain?->domain_name],
                ['Sumber', $email->source],
                ['Relay', $d->relay ?? '—'],
                ['Percobaan', $d->attempts],
                ['Message-ID', $d->message_id ? '<'.$d->message_id.'>' : '—'],
                ['Respons SMTP', $d->smtp_response ?? '—'],
                ['Tag', collect($email->tags)->implode(', ') ?: '—'],
                ['Tracking', ($email->track_opens ? 'open ' : '').($email->track_clicks ? 'click' : '') ?: 'mati'],
            ] as [$k, $v])
                <div class="px-5 py-2.5"><dt class="kicker">{{ $k }}</dt><dd class="mono mt-0.5 break-all text-ink">{{ $v }}</dd></div>
            @endforeach
        </dl>
    </aside>
</div>
@endsection
