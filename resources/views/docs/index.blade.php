<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dokumentasi — Postivo</title>
    <meta name="description" content="Dokumentasi teknis Postivo: REST API, SMTP relay, autentikasi domain, webhook, dan penagihan.">
    <link rel="icon" type="image/png" href="{{ asset('img/favicon.png') }}">
    @vite(['resources/css/app.css', 'resources/js/docs.js'])
</head>
@php
    $nav = [
        'Mulai Cepat' => [['pengenalan', 'Pengenalan'], ['quickstart', 'Quickstart 5 menit'], ['autentikasi', 'Autentikasi & API key']],
        'Domain & DNS' => [['tambah-domain', 'Menambah domain'], ['record-dns', 'Lima record DNS'], ['verifikasi-dns', 'Verifikasi & status'], ['troubleshoot-dns', 'Troubleshooting DNS']],
        'Mengirim Email' => [['kirim-email', 'POST /v1/emails'], ['batch', 'Pengiriman batch'], ['lampiran', 'Lampiran'], ['idempotency', 'Idempotency'], ['jadwal-tag', 'Jadwal, tag, metadata'], ['tracking', 'Open & click tracking']],
        'SMTP Relay' => [['smtp-kredensial', 'Kredensial & port'], ['smtp-kode', 'Kode balasan SMTP'], ['smtp-contoh', 'Contoh koneksi']],
        'Status & Event' => [['status-email', 'Siklus status'], ['bounce', 'Bounce & komplain'], ['suppression', 'Suppression list']],
        'Webhook' => [['webhook-event', 'Event & payload'], ['webhook-signature', 'Verifikasi tanda tangan'], ['webhook-retry', 'Retry & auto-disable']],
        'Referensi API' => [['api-endpoint', 'Daftar endpoint'], ['api-error', 'Format & kode error'], ['api-rate', 'Rate limit & kuota']],
        'Paket & Penagihan' => [['paket', 'Paket & batas'], ['penagihan', 'Checkout & pembayaran'], ['notifikasi', 'Email dari Postivo']],
        'Operasional' => [['keamanan', 'Keamanan & kepatuhan'], ['faq', 'FAQ']],
    ];
@endphp
<body x-data="docs()" @keydown.ctrl.k.window.prevent="openSearch()" @keydown.meta.k.window.prevent="openSearch()" @keydown.escape.window="search = false; nav = false">
<a href="#konten" class="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-[60] focus:border focus:border-red-500 focus:bg-white focus:px-3 focus:py-2">Lewati ke konten</a>

{{-- TOP BAR --}}
<header class="sticky top-0 z-40 border-b border-line bg-white/95 backdrop-blur">
    <div class="mx-auto flex h-14 max-w-[1400px] items-center gap-4 px-4 sm:px-6">
        <button class="btn-ghost -ml-2 px-2 lg:hidden" @click="nav = !nav" aria-label="Menu dokumentasi"><x-icon name="menu" class="size-5" /></button>
        <a href="{{ route('landing') }}" class="flex items-center gap-3"><img src="{{ asset('img/logo.png') }}" alt="Postivo" class="h-6 w-auto"><span class="hidden border-l border-line pl-3 font-mono text-[11px] uppercase tracking-wider text-muted sm:inline">Docs · v1</span></a>
        <button @click="openSearch()" class="ml-auto flex w-full max-w-sm items-center gap-2 border border-line px-3 py-1.5 text-left text-sm text-muted hover:border-ink sm:ml-6">
            <x-icon name="search" class="size-4" /><span class="flex-1 truncate">Cari dokumentasi atau endpoint API…</span><kbd class="hidden border border-line px-1.5 font-mono text-[10px] sm:inline">Ctrl K</kbd>
        </button>
        <nav class="hidden items-center gap-5 text-sm lg:flex" aria-label="Situs">
            <a class="text-muted hover:text-ink" href="{{ route('landing') }}#work">Produk</a>
            <a class="text-muted hover:text-ink" href="{{ route('landing') }}#harga">Harga</a>
            @auth<a class="btn-primary btn-sm" href="{{ route('console.dashboard') }}">Console</a>
            @else<a class="text-muted hover:text-ink" href="{{ route('login') }}">Masuk</a><a class="btn-primary btn-sm" href="{{ route('register') }}">Buat Akun</a>@endauth
        </nav>
    </div>
</header>

<div class="mx-auto flex max-w-[1400px]">
    <div x-cloak x-show="nav" @click="nav = false" class="fixed inset-0 z-30 bg-ink/40 lg:hidden"></div>
    {{-- SIDEBAR --}}
    <aside :class="nav ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'" class="fixed inset-y-0 left-0 z-40 w-72 overflow-y-auto border-r border-line bg-white px-5 pb-10 pt-20 transition-transform lg:sticky lg:top-14 lg:z-0 lg:h-[calc(100vh-3.5rem)] lg:w-72 lg:shrink-0 lg:pt-6">
        <p class="kicker mb-4">Dokumentasi Teknis · v1.0</p>
        @foreach($nav as $group => $items)
            <div class="mb-6">
                <h2 class="mb-1.5 text-xs font-bold uppercase tracking-wider">{{ $group }}</h2>
                <ul>@foreach($items as [$id, $label])
                    <li><a href="#{{ $id }}" @click="nav = false" :class="active === '{{ $id }}' ? 'border-red-500 bg-red-50 font-semibold text-ink' : 'border-transparent text-muted hover:text-ink'" class="block border-l-2 px-3 py-1.5 text-sm">{{ $label }}</a></li>
                @endforeach</ul>
            </div>
        @endforeach
        <div class="border border-line p-4 text-sm">
            <p class="font-semibold">Butuh bantuan teknis?</p>
            <p class="mt-1 text-muted">Tim kami membalas lewat email.</p>
            <a href="mailto:{{ config('postivo.support_email') }}" class="btn-outline btn-sm mt-3 w-full">Hubungi support</a>
        </div>
    </aside>

    {{-- KONTEN --}}
    <main id="konten" class="min-w-0 flex-1 px-4 py-10 sm:px-8 lg:px-12">
        <article class="mx-auto max-w-3xl [&_h1]:text-3xl [&_h1]:font-semibold [&_h1]:tracking-tight [&_h2]:mt-10 [&_h2]:scroll-mt-24 [&_h2]:border-b [&_h2]:border-dashed [&_h2]:border-line [&_h2]:pb-2 [&_h2]:text-xl [&_h2]:font-semibold [&_h2]:tracking-tight [&_h3]:mt-6 [&_h3]:font-semibold [&_p]:mt-3 [&_p]:leading-relaxed [&_ul]:mt-3 [&_ul]:list-disc [&_ul]:space-y-1 [&_ul]:pl-5 [&_ol]:mt-3 [&_ol]:list-decimal [&_ol]:space-y-1 [&_ol]:pl-5 [&_table]:mt-4 [&_table]:w-full [&_table]:text-sm [&_th]:border-b [&_th]:border-line [&_th]:bg-paper [&_th]:px-3 [&_th]:py-2 [&_th]:text-left [&_th]:font-mono [&_th]:text-[11px] [&_th]:uppercase [&_th]:tracking-wider [&_td]:border-b [&_td]:border-line [&_td]:px-3 [&_td]:py-2 [&_td]:align-top [&_:not(pre)>code]:border [&_:not(pre)>code]:border-line [&_:not(pre)>code]:bg-paper [&_:not(pre)>code]:px-1 [&_:not(pre)>code]:font-mono [&_:not(pre)>code]:text-[12px] [&_a]:text-red-600 [&_a]:underline">
            <p class="kicker">Dokumentasi Teknis</p>
            <h1 class="mt-2">Postivo API &amp; SMTP Relay</h1>
            <p class="text-muted">Kirim email transaksional lewat satu endpoint REST atau satu login SMTP. Halaman ini mencakup semua yang dibutuhkan developer — dari domain pertama sampai webhook di produksi.</p>

            <div class="mt-8 grid gap-px border border-line bg-line sm:grid-cols-2">
                @foreach([['quickstart', 'Mulai Cepat', 'Kirim email pertama dalam 5 menit.'], ['kirim-email', 'REST API', 'Endpoint kirim, batch, lampiran, idempotency.'], ['smtp-kredensial', 'SMTP Relay', 'Host, port, dan kode balasan.'], ['webhook-event', 'Webhook', 'Event bertanda tangan HMAC.'], ['record-dns', 'Domain & DNS', 'SPF, DKIM, DMARC, Return-Path.'], ['paket', 'Paket & Penagihan', 'Kuota, harga, checkout Xendit.']] as [$id, $t, $d])
                    <a href="#{{ $id }}" class="block bg-white p-5 no-underline hover:bg-red-50"><p class="font-semibold text-ink">{{ $t }}</p><p class="mt-1 text-sm text-muted">{{ $d }}</p></a>
                @endforeach
            </div>

            @include('docs.sections.start')
            @include('docs.sections.domain')
            @include('docs.sections.send')
            @include('docs.sections.smtp')
            @include('docs.sections.events')
            @include('docs.sections.reference')
            @include('docs.sections.billing')

            <p class="mt-12"><a href="#konten">↑ Kembali ke atas</a></p>
        </article>
    </main>
</div>

<footer class="border-t border-line">
    <div class="mx-auto flex max-w-[1400px] flex-wrap items-center justify-between gap-3 px-4 py-6 text-xs text-muted sm:px-6">
        <p>© {{ date('Y') }} postivo. All rights reserved. Created by postivo</p>
        <p class="font-mono">Ctrl + K cari · ESC tutup</p>
    </div>
</footer>

{{-- SEARCH MODAL --}}
<div x-cloak x-show="search" x-transition.opacity class="fixed inset-0 z-50 grid place-items-start bg-ink/40 p-4 pt-[12vh]" @click.self="search = false" role="dialog" aria-modal="true" aria-label="Cari dokumentasi">
    <div class="mx-auto w-full max-w-xl border border-ink bg-white" x-trap.noscroll="search">
        <div class="flex items-center gap-2 border-b border-line px-4"><x-icon name="search" class="size-4 text-muted" />
            <input x-ref="q" x-model="q" @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)" @keydown.enter.prevent="go()" class="w-full border-0 bg-transparent py-3 text-sm outline-none" placeholder="Cari dokumentasi atau endpoint API…" aria-label="Kata kunci"></div>
        <ul class="max-h-80 overflow-y-auto" role="listbox">
            <template x-for="(r, i) in results" :key="r.id">
                <li role="option" :aria-selected="i === idx"><a :href="'#' + r.id" @click="search = false" @mouseenter="idx = i" class="block border-b border-line px-4 py-2.5 text-sm" :class="i === idx ? 'bg-red-50' : ''"><span class="font-semibold" x-text="r.title"></span><span class="ml-2 text-xs text-muted" x-text="r.group"></span></a></li>
            </template>
            <li x-show="results.length === 0" class="px-4 py-8 text-center text-sm text-muted">Tidak ada topik yang cocok. Coba kata kunci lain, mis. “webhook” atau “DKIM”.</li>
        </ul>
        <p class="border-t border-line px-4 py-2 font-mono text-[11px] text-muted">↑↓ pilih · Enter buka · ESC tutup</p>
    </div>
</div>
</body>
</html>
