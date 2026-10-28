<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') — {{ \App\Support\Brand::name() }}</title>
    <link rel="icon" type="image/png" href="{{ \App\Support\Brand::favicon() }}">
    @vite(['resources/css/app.css'])
    {!! \App\Support\Brand::headStyle() !!}
</head>
<body class="bg-white text-ink">
<header class="border-b border-line">
    <div class="mx-auto flex h-16 max-w-3xl items-center justify-between px-4 sm:px-6">
        <a href="{{ route('landing') }}"><img src="{{ \App\Support\Brand::logo() }}" alt="{{ \App\Support\Brand::name() }}" class="h-7 w-auto"></a>
        <a href="{{ route('login') }}" class="inline-flex min-h-9 items-center text-sm text-muted hover:text-ink">Masuk</a>
    </div>
</header>
<main class="mx-auto max-w-3xl px-4 py-10 sm:px-6">
    <p class="kicker">Dokumen legal</p>
    <h1 class="mt-1 text-3xl font-bold tracking-tight">@yield('title')</h1>
    <p class="mt-2 text-sm text-muted">Berlaku sejak 1 November 2026 · Versi 1.0</p>
    <div class="legal mt-8 space-y-4 text-[15px] leading-relaxed [&_h2]:mt-8 [&_h2]:text-lg [&_h2]:font-bold [&_ul]:ml-5 [&_ul]:list-disc [&_ul]:space-y-1.5 [&_a]:underline">
        @yield('content')
    </div>
</main>
<footer class="border-t border-line">
    <div class="mx-auto flex max-w-3xl flex-wrap items-center justify-between gap-3 px-4 py-6 text-xs text-muted sm:px-6">
        <p>{{ \App\Support\Brand::footer() }}</p>
        <nav class="flex flex-wrap gap-x-4 gap-y-1" aria-label="Legal">
            <a class="inline-flex min-h-8 items-center hover:text-ink" href="{{ route('legal.terms') }}">Syarat Layanan</a>
            <a class="inline-flex min-h-8 items-center hover:text-ink" href="{{ route('legal.privacy') }}">Kebijakan Privasi</a>
            <a class="inline-flex min-h-8 items-center hover:text-ink" href="{{ route('legal.aup') }}">Penggunaan yang Dapat Diterima</a>
        </nav>
    </div>
</footer>
</body>
</html>
