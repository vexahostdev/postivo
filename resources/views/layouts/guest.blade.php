<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title') — Postivo</title>
    <link rel="icon" type="image/png" href="{{ asset('img/favicon.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<div class="grid min-h-screen lg:grid-cols-2">
    <div class="hidden flex-col justify-between border-r border-line p-10 lg:flex">
        <a href="{{ route('landing') }}"><img src="{{ asset('img/logo.png') }}" alt="Postivo" class="h-9 w-auto"></a>
        <div>
            <p class="max-w-md text-3xl font-semibold leading-tight tracking-tight">Kirim, lacak, dan debug setiap email transaksional.</p>
            <p class="mt-3 max-w-md text-sm text-muted">Satu endpoint REST atau satu login SMTP. Autentikasi domain terpandu, suppression otomatis, dan timeline untuk setiap email.</p>
        </div>
        <p class="kicker">PT Destinara · Jakarta</p>
    </div>
    <div class="flex items-center justify-center px-4 py-10 sm:px-8">
        <div class="w-full max-w-sm">
            <a href="{{ route('landing') }}" class="mb-8 block lg:hidden"><img src="{{ asset('img/logo.png') }}" alt="Postivo" class="h-8 w-auto"></a>
            @yield('content')
            <p class="mt-10 text-[11px] text-muted">© {{ date('Y') }} postivo. All rights reserved. Created by postivo</p>
        </div>
    </div>
</div>
</body>
</html>
