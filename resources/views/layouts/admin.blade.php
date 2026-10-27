<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex,nofollow">
    <title>@yield('title', 'Admin') — Postivo Admin</title>
    <link rel="icon" type="image/png" href="{{ \App\Support\Brand::favicon() }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {!! \App\Support\Brand::headStyle() !!}
</head>
<body x-data="{ nav: false }" @keydown.escape.window="nav = false">
@php
    $me = auth()->user();
    $groups = [
        'Ikhtisar' => [['admin.dashboard', 'Dashboard', 'home', 'admin.dashboard'], ['admin.abuse', 'Abuse & reputasi', 'alert', 'admin.abuse']],
        'Pelanggan' => [['admin.organizations.index', 'Organisasi', 'globe', 'admin.organizations.*'], ['admin.users.index', 'Pengguna & staf', 'key', 'admin.users.*'], ['admin.domains.index', 'Domain', 'globe', 'admin.domains.*'], ['admin.emails.index', 'Email', 'mail', 'admin.emails.*']],
        'Keuangan' => [['admin.billing.index', 'Order & pembayaran', 'card', 'admin.billing.*'], ['admin.plans.index', 'Plan & harga', 'card', 'admin.plans.*']],
        'Kontrol' => [['admin.blocklist.index', 'Blocklist global', 'ban', 'admin.blocklist.*'], ['admin.suppressions.index', 'Suppression', 'ban', 'admin.suppressions.*']],
        'Sistem' => [['admin.system.index', 'Kesehatan sistem', 'refresh', 'admin.system.*'], ['admin.tools.index', 'Alat & diagnostik', 'refresh', 'admin.tools.*'], ['admin.audit', 'Log audit', 'book', 'admin.audit'], ['admin.branding.index', 'Branding', 'settings', 'admin.branding.*'], ['admin.settings.index', 'Pengaturan', 'settings', 'admin.settings.*'], ['admin.security', 'Keamanan saya', 'key', 'admin.security*']],
    ];
@endphp
<a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-[60] focus:border focus:border-red-500 focus:bg-white focus:px-3 focus:py-2">Lewati ke konten</a>

<div class="min-h-screen lg:grid lg:grid-cols-[232px_1fr]">
    <div x-cloak x-show="nav" @click="nav = false" class="fixed inset-0 z-30 bg-ink/40 lg:hidden"></div>

    {{-- Sidebar: gaya sama dengan console pelanggan --}}
    <aside :class="nav ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
           class="fixed inset-y-0 left-0 z-40 flex w-[232px] flex-col border-r border-line bg-white transition-transform lg:sticky lg:top-0 lg:h-screen">
        <a href="{{ route('admin.dashboard') }}" class="flex h-16 items-center gap-3 border-b border-line px-5">
            <img src="{{ \App\Support\Brand::logo() }}" alt="{{ \App\Support\Brand::name() }}" class="h-7 w-auto">
            <span class="border border-red-500 px-1.5 py-0.5 font-mono text-[10px] font-bold uppercase tracking-widest text-red-500">Admin</span>
        </a>
        <nav class="flex-1 overflow-y-auto py-3" aria-label="Admin">
            @foreach($groups as $g => $items)
                <p class="kicker px-5 pb-1 pt-4">{{ $g }}</p>
                @foreach($items as [$route, $label, $icon, $pattern])
                    <a href="{{ route($route) }}" @class(['nav-link', 'nav-link-active' => request()->routeIs($pattern)]) @if(request()->routeIs($pattern)) aria-current="page" @endif>
                        <x-icon :name="$icon" />{{ $label }}
                    </a>
                @endforeach
            @endforeach
        </nav>
        <div class="border-t border-line p-4">
            <p class="truncate text-sm font-semibold">{{ $me->name }}</p>
            <p class="kicker mt-0.5">{{ $me->platform_role->label() }}</p>
            <a href="{{ route('console.dashboard') }}" class="btn-outline btn-sm mt-3 w-full">Buka Console →</a>
            <div class="mt-3 flex items-center justify-between">
                <form method="POST" action="{{ route('logout') }}">@csrf
                    <button class="btn-ghost btn-sm -ml-2.5 text-muted"><x-icon name="logout" class="size-3.5" />Keluar</button>
                </form>
                <a href="{{ route('docs') }}" class="btn-ghost btn-sm text-muted"><x-icon name="book" class="size-3.5" />Docs</a>
            </div>
            <p class="mt-3 text-[10px] leading-snug text-muted">{{ \App\Support\Brand::footer() }}</p>
        </div>
    </aside>

    <div class="min-w-0">
        <header class="flex h-16 items-center justify-between border-b border-line px-4 sm:px-6 lg:hidden">
            <button @click="nav = true" class="btn-ghost -ml-2 px-2" aria-label="Buka menu"><x-icon name="menu" class="size-5" /></button>
            <span class="flex items-center gap-2"><img src="{{ \App\Support\Brand::logo() }}" alt="{{ \App\Support\Brand::name() }}" class="h-6 w-auto"><span class="font-mono text-[10px] font-bold uppercase tracking-widest text-red-500">Admin</span></span>
            <span class="w-9"></span>
        </header>
        <main id="main" class="mx-auto max-w-[1320px] px-4 py-6 sm:px-6">
            @if(! config('postivo.admin.require_2fa') && ! $me->hasTwoFactor())
                <div class="mb-5 border border-warn px-4 py-3 text-sm text-warn">2FA belum aktif. Di production 2FA wajib. <a class="underline" href="{{ route('admin.security') }}">Aktifkan sekarang</a>.</div>
            @endif
            <x-flash />
            @yield('content')
        </main>
    </div>
</div>
</body>
</html>
