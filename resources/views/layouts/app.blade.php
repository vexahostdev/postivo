<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">
    <title>@yield('title', 'Console') — Postivo</title>
    <link rel="icon" type="image/png" href="{{ \App\Support\Brand::favicon() }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {!! \App\Support\Brand::headStyle() !!}
</head>
<body x-data="{ nav: false }" @keydown.escape.window="nav = false">
@php
    $links = [
        ['console.dashboard', 'Dashboard', 'home', 'console.dashboard'],
        ['console.emails.index', 'Emails', 'mail', 'console.emails.*'],
        ['console.domains.index', 'Domains', 'globe', 'console.domains.*'],
        ['console.suppressions.index', 'Suppressions', 'ban', 'console.suppressions.*'],
        ['console.webhooks.index', 'Webhooks', 'webhook', 'console.webhooks.*'],
        ['console.api-keys.index', 'API Keys', 'key', 'console.api-keys.*'],
        ['console.billing.index', 'Billing', 'card', 'console.billing.*'],
        ['console.settings', 'Settings', 'settings', 'console.settings*'],
    ];
@endphp
<a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-[60] focus:border focus:border-red-500 focus:bg-white focus:px-3 focus:py-2">Lewati ke konten</a>

<div class="min-h-screen lg:grid lg:grid-cols-[232px_1fr]">
    {{-- Overlay mobile --}}
    <div x-cloak x-show="nav" @click="nav = false" class="fixed inset-0 z-30 bg-ink/40 lg:hidden"></div>

    <aside :class="nav ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
           class="fixed inset-y-0 left-0 z-40 flex w-[232px] flex-col border-r border-line bg-white transition-transform lg:sticky lg:top-0 lg:h-screen">
        <a href="{{ route('console.dashboard') }}" class="flex h-16 items-center border-b border-line px-5">
            <img src="{{ \App\Support\Brand::logo() }}" alt="{{ \App\Support\Brand::name() }}" class="h-7 w-auto">
        </a>
        <nav class="flex-1 space-y-0.5 overflow-y-auto py-4" aria-label="Utama">
            @foreach($links as [$route, $label, $icon, $pattern])
                <a href="{{ route($route) }}" @class(['nav-link', 'nav-link-active' => request()->routeIs($pattern)]) @if(request()->routeIs($pattern)) aria-current="page" @endif>
                    <x-icon :name="$icon" />{{ $label }}
                </a>
            @endforeach
        </nav>
        <div class="border-t border-line p-4">
            <p class="truncate text-sm font-semibold">{{ $currentOrg->name }}</p>
            <p class="kicker mt-0.5">{{ $currentOrg->plan }} · {{ $currentRole->value }}</p>
            <div class="mt-3 flex items-center justify-between">
                <form method="POST" action="{{ route('logout') }}">@csrf
                    <button class="btn-ghost btn-sm -ml-2.5 text-muted"><x-icon name="logout" class="size-3.5" />Keluar</button>
                </form>
                <a href="{{ route('docs') }}" class="btn-ghost btn-sm text-muted"><x-icon name="book" class="size-3.5" />Docs</a>
                @if(auth()->user()->isStaff() && ! session('impersonator_id'))<a href="{{ route('admin.dashboard') }}" class="btn-ghost btn-sm text-red-500">Admin</a>@endif
            </div>
            <p class="mt-3 text-[10px] leading-snug text-muted">{{ \App\Support\Brand::footer() }}</p>
        </div>
    </aside>

    <div class="min-w-0">
        <header class="flex h-16 items-center justify-between border-b border-line px-4 sm:px-6 lg:hidden">
            <button @click="nav = true" class="btn-ghost -ml-2 px-2" aria-label="Buka menu"><x-icon name="menu" class="size-5" /></button>
            <img src="{{ \App\Support\Brand::logo() }}" alt="{{ \App\Support\Brand::name() }}" class="h-6 w-auto">
            <span class="w-9"></span>
        </header>
        <main id="main" class="mx-auto max-w-[1280px] px-4 py-6 sm:px-6">
            @if(session('impersonator_id'))
                <div class="mb-5 flex flex-wrap items-center justify-between gap-3 border-2 border-red-500 bg-red-50 px-4 py-3 text-sm text-red-600" role="alert">
                    <span><b>Mode impersonasi (hanya-baca)</b> — Anda melihat console sebagai {{ auth()->user()->email }}. Semua aksi tercatat di audit.</span>
                    <form method="POST" action="{{ route('impersonate.stop') }}">@csrf<button class="btn-primary btn-sm">Keluar impersonasi</button></form>
                </div>
            @endif
            @if(($ann = \App\Services\PlatformSettings::get('announcement')) !== '')
                <div class="mb-5 border border-ink bg-paper px-4 py-3 text-sm" role="status"><span class="kicker mr-2">Pengumuman</span>{{ $ann }}</div>
            @endif
            @unless(auth()->user()->hasVerifiedEmail())
                <div class="mb-5 flex flex-wrap items-center justify-between gap-3 border border-warn px-4 py-3 text-sm text-warn" role="alert">
                    <span>Email <b>{{ auth()->user()->email }}</b> belum diverifikasi. API key live memerlukan verifikasi.</span>
                    <form method="POST" action="{{ route('verification.send') }}">@csrf<button class="btn-outline btn-sm">Kirim ulang email verifikasi</button></form>
                </div>
            @endunless
            <x-flash />
            @yield('content')
        </main>
    </div>
</div>
</body>
</html>
