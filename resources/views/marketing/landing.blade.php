<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Postivo — Transactional Email API &amp; SMTP Relay</title>
    <meta name="description" content="Send, track and debug every transactional email. REST API and SMTP relay with domain authentication, suppression and signed webhooks.">
    <meta property="og:title" content="Postivo — Transactional Email API & SMTP Relay">
    <meta property="og:description" content="Send, track and debug every transactional email.">
    <meta property="og:image" content="{{ asset('img/logo.png') }}">
    <meta name="theme-color" content="#FE0000">
    <link rel="icon" type="image/png" href="{{ asset('img/favicon.png') }}">
    @vite(['resources/css/landing.css', 'resources/js/landing.js'])
</head>
<body x-data="landing()" @keydown.escape.window="menu = false; modal = null">

<a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-[60] focus:border focus:border-brand focus:bg-white focus:px-3 focus:py-2 focus:text-brand">Skip to content</a>

{{-- HEADER --}}
<header class="flex w-full items-center justify-between px-3 pb-2 pt-3.5 sm:px-5">
    <a href="#top" aria-label="Postivo home"><img src="{{ asset('img/logo.png') }}" alt="Postivo" class="h-6 w-auto"></a>
    <div class="flex items-center gap-2">
        <a href="{{ route('docs') }}" class="hidden text-[10px] font-semibold text-brand hover:underline sm:inline">Docs</a>
        <a href="{{ route('login') }}" class="hidden text-[10px] font-semibold text-brand hover:underline sm:inline">Sign in</a>
        <button @click="menu = true" aria-haspopup="dialog" :aria-expanded="menu"
                class="rounded-full border border-brand px-[13px] py-[3px] text-[10px] font-bold text-brand transition-colors hover:bg-brand hover:text-white">Menu</button>
    </div>
</header>

<main id="main">
    {{-- HERO --}}
    <section id="top" class="grid w-full grid-cols-1 px-3 pt-2 sm:px-5 md:grid-cols-[44px_1fr_44px] lg:grid-cols-[68px_1fr_68px]">
        <div class="hidden flex-col justify-between md:flex" aria-hidden="true">
            <span class="rail-l pt-28 text-[8px] font-medium text-brand">Transactional email infrastructure</span>
            <span class="text-[8px] font-medium leading-[1.08] text-brand">SMTP<br>Relay</span>
        </div>

        <div class="hairline w-full border-b pb-5">
            <svg viewBox="0 0 1000 240" class="block h-auto w-full" role="img" aria-label="POSTIVO">
                <text x="500" y="190" text-anchor="middle" fill="#FE0000" font-size="210" font-weight="900" font-family="Inter Variable, Inter, sans-serif" letter-spacing="-13.6">POSTIVO</text>
            </svg>

            <div class="mt-4 grid gap-6 sm:mt-5 lg:grid-cols-12 lg:gap-8">
                <div class="lg:col-span-5">
                    <h1 class="text-[17px] font-semibold leading-[1.2] tracking-[-0.025em] text-brand sm:text-[19px]">
                        Transactional email API and SMTP relay for developers — send, track and debug every message.
                    </h1>
                    <p class="mt-3 max-w-[420px] text-[13px] leading-relaxed text-neutral-600">
                        One REST endpoint or one SMTP login. Guided SPF/DKIM/DMARC setup, automatic suppression, and a timeline for every email.
                    </p>
                    <div class="mt-5 flex flex-wrap items-center gap-2">
                        <a href="{{ route('register') }}" class="rounded-full bg-brand px-4 py-2 text-[11px] font-semibold text-white transition-colors hover:bg-brand-dark">Get started</a>
                        <a href="#work" class="rounded-full border border-brand px-4 py-2 text-[11px] font-semibold text-brand transition-colors hover:bg-brand hover:text-white">See how it works</a>
                    </div>
                </div>

                <div class="hairline border lg:col-span-7">
                    <div class="hairline flex items-center justify-between border-b px-3 py-2">
                        <div class="flex gap-1" role="tablist" aria-label="Code example language">
                            <template x-for="t in ['curl','node','php']" :key="t">
                                <button role="tab" :aria-selected="tab === t" @click="tab = t"
                                        class="px-2 py-0.5 font-mono text-[10px] uppercase tracking-wide"
                                        :class="tab === t ? 'bg-brand text-white' : 'text-brand hover:bg-brand-50'" x-text="t"></button>
                            </template>
                        </div>
                        <button @click="copyCode()" class="font-mono text-[10px] uppercase tracking-wide text-brand hover:underline" x-text="copied ? 'Copied' : 'Copy'"></button>
                    </div>
                    <pre class="overflow-x-auto p-4 font-mono text-[11px] leading-[1.6] text-neutral-900" x-text="snippets[tab]" aria-label="Code example"></pre>
                    <div class="hairline border-t bg-brand-50 px-4 py-2 font-mono text-[11px] text-brand">
                        <span class="font-bold">202 Accepted</span> · {"id":"0192f3a0-…","status":"queued"}
                    </div>
                </div>
            </div>

            <div class="mt-6"><span class="text-[9px] font-bold uppercase tracking-[0.04em] text-brand">Core infrastructure capabilities</span></div>
        </div>

        <div class="hidden flex-col items-end justify-between md:flex">
            <span class="rail-r pt-28 text-[8px] font-medium text-brand" aria-hidden="true">REST · SMTP · Webhooks</span>
            <div class="flex flex-col items-center gap-[5px]">
                <a href="#work" aria-label="Capabilities" class="grid size-[19px] place-items-center rounded-full border border-brand font-mono text-[8px] font-black text-brand transition-colors hover:bg-brand hover:text-white">&gt;_</a>
            </div>
        </div>
    </section>

    {{-- CAPABILITIES (artefak produk, bukan stock art) --}}
    <section id="work" aria-label="Capabilities">
        <template x-for="c in caps" :key="c.id">
            <div class="grid w-full grid-cols-1 px-3 sm:px-5 md:grid-cols-[44px_1fr_44px] lg:grid-cols-[68px_1fr_68px]">
                <div class="hidden md:block" aria-hidden="true"></div>
                <div class="hairline border-b py-[18px]">
                    <div class="grid items-stretch gap-5 lg:grid-cols-12 lg:gap-6">
                        <button type="button" @click="modal = c" class="hairline group relative aspect-[16/9.15] w-full overflow-hidden border bg-white text-left lg:col-span-8" :aria-label="`Explore ${c.title}`">
                            <div x-show="c.id === 'relay'" class="absolute inset-0 grid place-items-center p-6">
                                <div class="w-full max-w-[520px] font-mono text-[11px] text-neutral-900">
                                    <template x-for="(s, j) in pipeline" :key="j">
                                        <div class="hairline flex items-center gap-3 border-b py-2.5 last:border-b-0">
                                            <span class="grid size-5 place-items-center border border-brand text-[9px] font-bold text-brand" x-text="j + 1"></span>
                                            <span x-text="s"></span>
                                        </div>
                                    </template>
                                </div>
                            </div>
                            <div x-show="c.id === 'auth'" class="absolute inset-0 grid place-items-center p-6">
                                <table class="w-full max-w-[560px] text-left font-mono text-[10.5px]">
                                    <thead><tr class="hairline border-b text-brand"><th class="py-1.5 pr-3">TYPE</th><th class="pr-3">HOST</th><th>STATUS</th></tr></thead>
                                    <tbody>
                                        <template x-for="r in dns" :key="r[1]">
                                            <tr class="hairline border-b border-opacity-30"><td class="py-2 pr-3 font-bold" x-text="r[0]"></td><td class="pr-3" x-text="r[1]"></td>
                                                <td :class="r[2] === 'verified' ? 'text-ok' : 'text-warn'" x-text="(r[2] === 'verified' ? '✓ ' : '… ') + r[2]"></td></tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                            <div x-show="c.id === 'events'" class="absolute inset-0 grid place-items-center p-6">
                                <ol class="w-full max-w-[520px] font-mono text-[11px]">
                                    <template x-for="(e, j) in events" :key="j">
                                        <li class="hairline relative flex gap-4 border-l pb-4 pl-5 last:pb-0">
                                            <span class="absolute -left-[4px] top-1 size-[7px] rounded-full bg-brand"></span>
                                            <span class="w-[74px] shrink-0 text-neutral-500" x-text="e[0]"></span>
                                            <span><b x-text="e[1]"></b> <span class="text-neutral-600" x-text="e[2]"></span></span>
                                        </li>
                                    </template>
                                </ol>
                            </div>
                            <span class="absolute bottom-3 right-3 rounded-full bg-brand px-3 py-1 text-[10px] font-semibold text-white opacity-0 transition-opacity group-hover:opacity-100 group-focus-visible:opacity-100">Explore ↗</span>
                        </button>

                        <div class="flex min-h-[140px] flex-col justify-between lg:col-span-4 lg:min-h-0">
                            <div>
                                <h2 class="text-[22px] font-bold leading-[1.08] tracking-[-0.03em] text-brand sm:text-[25px]" x-text="c.title"></h2>
                                <p class="mt-0.5 text-[10.5px] font-semibold text-brand" x-text="c.subtitle"></p>
                            </div>
                            <div class="flex items-end justify-between gap-4 pt-6">
                                <ul class="space-y-px text-[10px] font-semibold leading-[1.22] text-brand"><template x-for="s in c.services" :key="s"><li x-text="s"></li></template></ul>
                                <button @click="modal = c" class="shrink-0 rounded-full bg-brand px-[15px] py-[4.5px] text-[10px] font-semibold text-white transition-colors hover:bg-brand-dark">Explore</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="hidden md:block" aria-hidden="true"></div>
            </div>
        </template>
    </section>

    {{-- INFRASTRUCTURE --}}
    <section id="infra" class="grid w-full grid-cols-1 px-3 sm:px-5 md:grid-cols-[44px_1fr_44px] lg:grid-cols-[68px_1fr_68px]" aria-label="Infrastructure">
        <div class="hidden md:block" aria-hidden="true"></div>
        <div>
            <div class="hairline flex items-baseline justify-between gap-4 border-b py-3 sm:py-4">
                <h2 class="text-[40px] font-bold leading-[1.02] tracking-[-0.035em] text-brand sm:text-[56px] md:text-[64px]">Infrastructure</h2>
                <span class="text-[11px] font-semibold text-brand">Jakarta · primary</span>
            </div>
            <dl class="grid gap-px sm:grid-cols-2 lg:grid-cols-4">
                <template x-for="f in facts" :key="f[0]">
                    <div class="hairline border-b py-4 sm:pr-4">
                        <dt class="text-[9px] font-bold uppercase tracking-wider text-brand" x-text="f[0]"></dt>
                        <dd class="mt-1 text-[12px] font-semibold leading-snug" x-text="f[1]"></dd>
                    </div>
                </template>
            </dl>
        </div>
        <div class="hidden md:block" aria-hidden="true"></div>
    </section>

    {{-- PRICING (harga dari config/postivo.php) --}}
    <section id="harga" class="grid w-full grid-cols-1 px-3 sm:px-5 md:grid-cols-[44px_1fr_44px] lg:grid-cols-[68px_1fr_68px]" aria-label="Pricing">
        <div class="hidden md:block" aria-hidden="true"></div>
        <div>
            <div class="hairline flex items-baseline justify-between gap-4 border-b py-3 sm:py-4">
                <h2 class="text-[40px] font-bold leading-[1.02] tracking-[-0.035em] text-brand sm:text-[56px] md:text-[64px]">Pricing</h2>
                <span class="text-[11px] font-semibold text-brand">IDR · per bulan</span>
            </div>
            <div class="grid sm:grid-cols-2 lg:grid-cols-4">
                @foreach(\App\Support\Plans::active() as $key => $p)
                    <div class="hairline flex flex-col border-b py-5 sm:pr-5">
                        <h3 class="text-[22px] font-bold tracking-[-0.03em] text-brand">{{ $p['name'] }}</h3>
                        <p class="mt-2 text-[28px] font-bold tracking-[-0.03em]">{{ $p['price'] ? 'Rp '.number_format($p['price'], 0, ',', '.') : 'Gratis' }}</p>
                        <ul class="mt-3 flex-1 space-y-1 text-[12px] font-semibold">
                            @foreach($p['features'] as $f)<li class="flex gap-1.5"><span class="text-brand">→</span>{{ $f }}</li>@endforeach
                        </ul>
                        <a href="{{ route('register') }}" class="mt-5 rounded-full {{ $p['price'] ? 'bg-brand text-white hover:bg-brand-dark' : 'border border-brand text-brand hover:bg-brand hover:text-white' }} px-4 py-2 text-center text-[11px] font-semibold transition-colors">{{ $p['price'] ? 'Mulai '.$p['name'] : 'Mulai gratis' }}</a>
                    </div>
                @endforeach
            </div>
            <p class="py-3 text-[10px] text-brand">Pembayaran via Xendit: QRIS, virtual account, minimarket, e-wallet.</p>
        </div>
        <div class="hidden md:block" aria-hidden="true"></div>
    </section>
</main>

{{-- FOOTER --}}
<footer class="w-full px-3 pb-4 pt-4 sm:px-5">
    <div class="grid grid-cols-1 md:grid-cols-[44px_1fr_44px] lg:grid-cols-[68px_1fr_68px]">
        <div class="hidden md:block"></div>
        <div>
            <nav class="flex flex-wrap items-center gap-x-5 gap-y-2 py-3 text-[10px] tracking-[-0.01em] text-brand" aria-label="Footer">
                <span class="font-extrabold uppercase">Explore</span>
                <a class="font-medium hover:underline" href="#top">Overview</a>
                <a class="font-medium hover:underline" href="#work">Capabilities</a>
                <a class="font-medium hover:underline" href="#infra">Infrastructure</a>
                <a class="font-medium hover:underline" href="#harga">Pricing</a>
                <a class="font-medium hover:underline" href="{{ route('docs') }}">Docs</a>
                <a class="font-medium hover:underline" href="{{ route('login') }}">Sign in</a>
                <a class="font-medium hover:underline" href="{{ route('console.dashboard') }}">Console ↗</a>
            </nav>
            <div class="relative select-none overflow-hidden" aria-hidden="true">
                <svg viewBox="0 0 1000 245" class="block h-auto w-full"><text x="-6" y="236" fill="#FE0000" font-size="260" font-weight="900" font-family="Inter Variable, Inter, sans-serif" letter-spacing="-14.3">POSTIVO</text></svg>
                <span class="absolute bottom-[9%] left-[1.1%] rounded-full border border-brand/30 bg-white/70 px-3 py-[3px] font-mono text-[10px] text-brand/60 sm:text-[13px]">250 OK</span>
            </div>
            <p class="mt-2 text-[10px] text-brand">© {{ date('Y') }} postivo. All rights reserved. Created by postivo · <a class="underline" href="mailto:{{ config('postivo.support_email') }}">{{ config('postivo.support_email') }}</a></p>
        </div>
        <div class="hidden md:block"></div>
    </div>
</footer>

{{-- MODAL: EXPLORE --}}
<div x-cloak x-show="modal" x-transition.opacity @click.self="modal = null" class="fixed inset-0 z-50 grid place-items-center bg-brand/20 p-4 backdrop-blur-sm sm:p-6">
    <div x-trap.noscroll="modal" role="dialog" aria-modal="true" :aria-label="modal && modal.title" class="hairline w-full max-w-3xl border bg-white">
        <template x-if="modal">
            <div>
                <div class="hairline flex items-center justify-between border-b px-6 py-4">
                    <span class="text-[10px] font-bold uppercase tracking-[0.06em] text-brand" x-text="modal.kicker"></span>
                    <button @click="modal = null" class="rounded-full border border-brand px-3.5 py-1 text-[10px] font-bold text-brand transition-colors hover:bg-brand hover:text-white">Close ✕</button>
                </div>
                <div class="grid gap-6 p-6 sm:p-8 md:grid-cols-12">
                    <div class="md:col-span-8">
                        <h3 class="text-2xl font-bold leading-none tracking-[-0.03em] text-brand sm:text-3xl" x-text="modal.title"></h3>
                        <p class="mt-1 text-xs font-semibold text-brand" x-text="modal.subtitle"></p>
                        <p class="mt-4 text-[13px] leading-relaxed text-neutral-700" x-text="modal.summary"></p>
                        <ul class="mt-5 grid gap-1.5 border-t border-brand/20 pt-4 text-[11px] font-semibold sm:grid-cols-2">
                            <template x-for="d in modal.deliverables" :key="d"><li class="flex gap-1.5"><span class="text-brand">→</span><span x-text="d"></span></li></template>
                        </ul>
                    </div>
                    <div class="hairline border bg-brand-50 p-4 md:col-span-4">
                        <span class="text-[9px] font-bold uppercase tracking-wider text-brand">Includes</span>
                        <ul class="mt-1.5 space-y-0.5 text-[11px] font-bold"><template x-for="s in modal.services" :key="s"><li x-text="s"></li></template></ul>
                        <a href="{{ route('register') }}" class="mt-6 block rounded-full bg-brand py-2 text-center text-[10.5px] font-semibold text-white transition-colors hover:bg-brand-dark">Get started</a>
                    </div>
                </div>
            </div>
        </template>
    </div>
</div>

{{-- MENU OVERLAY --}}
<div x-cloak x-show="menu" x-transition:enter="transition duration-500" x-transition:enter-start="-translate-y-full opacity-0" x-transition:enter-end="translate-y-0 opacity-100"
     x-transition:leave="transition duration-300" x-transition:leave-start="translate-y-0 opacity-100" x-transition:leave-end="-translate-y-full opacity-0"
     class="fixed inset-0 z-50 flex flex-col justify-between bg-brand p-5 text-white sm:p-10" role="dialog" aria-modal="true" aria-label="Site menu" x-trap.noscroll="menu">
    <div class="flex items-center justify-between">
        <span class="text-xs font-bold tracking-tight">POSTIVO</span>
        <button @click="menu = false" class="rounded-full border border-white px-4 py-1 text-[10px] font-bold transition-colors hover:bg-white hover:text-brand">Close</button>
    </div>
    <nav class="grid items-end gap-8 lg:grid-cols-12" aria-label="Menu">
        <ul class="space-y-2 lg:col-span-7">
            @foreach([['Home', '#top'], ['Capabilities', '#work'], ['Infrastructure', '#infra'], ['Pricing', '#harga'], ['Docs', route('docs')], ['Sign in', route('login')], ['Console', route('console.dashboard')]] as [$l, $h])
                <li><a href="{{ $h }}" @click="menu = false" class="block text-4xl font-bold tracking-[-0.035em] transition-opacity hover:opacity-70 sm:text-6xl">{{ $l }}</a></li>
            @endforeach
        </ul>
        <div class="space-y-4 border-t border-white/25 pt-4 text-xs sm:text-sm lg:col-span-5 lg:border-l lg:border-t-0 lg:pl-8 lg:pt-0">
            <p class="font-semibold leading-relaxed opacity-90">Transactional email API and SMTP relay for developers. Domain authentication, suppression and signed webhooks built in.</p>
            <a class="text-[11px] underline opacity-90" href="mailto:support@postivo.my.id">support@postivo.my.id</a>
        </div>
    </nav>
    <div class="flex justify-between border-t border-white/20 pt-4 text-[10px] opacity-75"><span>© {{ date('Y') }} postivo. All rights reserved. Created by postivo</span><span>Jakarta, Indonesia</span></div>
</div>
</body>
</html>
