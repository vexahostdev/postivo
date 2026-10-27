<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex,nofollow">
    <title>@yield('title', 'Admin') — Postivo Admin</title>
    <link rel="icon" type="image/png" href="{{ asset('img/favicon.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body x-data="{ nav: false }" @keydown.escape.window="nav = false">
@php
    $me = auth()->user();
    $groups = [
        'Ikhtisar' => [['admin.dashboard', 'Dashboard', 'home', 'admin.dashboard'], ['admin.abuse', 'Abuse & reputasi', 'alert', 'admin.abuse']],
        'Pelanggan' => [['admin.organizations.index', 'Organisasi', 'globe', 'admin.organizations.*'], ['admin.users.index', 'Pengguna & staf', 'key', 'admin.users.*'], ['admin.domains.index', 'Domain', 'globe', 'admin.domains.*'], ['admin.emails.index', 'Email', 'mail', 'admin.emails.*']],
        'Keuangan' => [['admin.billing.index', 'Order & pembayaran', 'card', 'admin.billing.*'], ['admin.plans.index', 'Plan & harga', 'card', 'admin.plans.*']],
        'Kontrol' => [['admin.blocklist.index', 'Blocklist global', 'ban', 'admin.blocklist.*'], ['admin.suppressions.index', 'Suppression', 'ban', 'admin.suppressions.*']],
        'Sistem' => [['admin.system.index', 'Kesehatan sistem', 'refresh', 'admin.system.*'], ['admin.audit', 'Log audit', 'book', 'admin.audit'], ['admin.settings.index', 'Pengaturan', 'settings', 'admin.settings.*'], ['admin.security', 'Keamanan saya', 'key', 'admin.security*']],
    ];
@endphp
<div class="min-h-screen lg:grid lg:grid-cols-[248px_1fr]">
    <div x-cloak x-show="nav" @click="nav = false" class="fixed inset-0 z-30 bg-ink/40 lg:hidden"></div>
    <aside :class="nav ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'" class="fixed inset-y-0 left-0 z-40 flex w-[248px] flex-col bg-ink text-white transition-transform lg:sticky lg:top-0 lg:h-screen">
        <a href="{{ route('admin.dashboard') }}" class="flex h-16 items-center gap-3 border-b border-white/15 px-5">
            <span class="bg-white p-1"><img src="{{ asset('img/logo.png') }}" alt="Postivo" class="h-5 w-auto"></span>
            <span class="font-mono text-[11px] font-bold uppercase tracking-widest text-red-500">Admin</span>
        </a>
        <nav class="flex-1 overflow-y-auto py-3" aria-label="Admin">
            @foreach($groups as $g => $items)
                <p class="px-5 pb-1 pt-4 font-mono text-[10px] uppercase tracking-widest text-white/50">{{ $g }}</p>
                @foreach($items as [$route, $label, $icon, $pattern])
                    <a href="{{ route($route) }}" @class(['flex items-center gap-2.5 border-l-2 px-5 py-2 text-sm', 'border-red-500 bg-white/10 font-semibold' => request()->routeIs($pattern), 'border-transparent text-white/70 hover:bg-white/5 hover:text-white' => !request()->routeIs($pattern)])>
                        <x-icon :name="$icon" class="size-4" />{{ $label }}
                    </a>
                @endforeach
            @endforeach
        </nav>
        <div class="border-t border-white/15 p-4 text-xs">
            <p class="truncate font-semibold">{{ $me->name }}</p>
            <p class="font-mono uppercase tracking-wider text-red-500">{{ $me->platform_role->label() }}</p>
            <div class="mt-3 flex items-center justify-between">
                <a href="{{ route('console.dashboard') }}" class="text-white/60 hover:text-white">Console →</a>
                <form method="POST" action="{{ route('logout') }}">@csrf<button class="text-white/60 hover:text-white">Keluar</button></form>
            </div>
        </div>
    </aside>

    <div class="min-w-0">
        <header class="flex h-14 items-center justify-between border-b border-line px-4 lg:hidden">
            <button @click="nav = true" class="btn-ghost -ml-2 px-2" aria-label="Menu"><x-icon name="menu" class="size-5" /></button>
            <span class="font-mono text-[11px] font-bold uppercase tracking-widest text-red-500">Postivo Admin</span><span class="w-9"></span>
        </header>
        <main class="mx-auto max-w-[1320px] px-4 py-6 sm:px-6">
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
