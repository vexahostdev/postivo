<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Berhenti berlangganan — Postivo</title>
    @vite(['resources/css/app.css'])
</head>
<body class="grid grid-cols-1 min-h-screen place-items-center px-4">
    <main class="w-full max-w-md border border-line p-6 sm:p-8">
        <img src="{{ \App\Support\Brand::logo() }}" alt="Postivo" class="mb-6 h-8 w-auto">
        @if($done)
            <h1 class="text-xl font-semibold">Anda telah berhenti berlangganan.</h1>
            <p class="mt-2 text-sm text-muted">Alamat ini tidak akan menerima email lagi dari pengirim ini.</p>
        @else
            <h1 class="text-xl font-semibold">Berhenti berlangganan?</h1>
            <p class="mt-2 text-sm text-muted">Hentikan email ke <span class="mono break-all text-ink">{{ $recipient }}</span> dari pengirim ini.</p>
            <form method="POST" action="{{ url('/u/'.$token) }}" class="mt-6">@csrf<button class="btn-primary w-full">Ya, berhenti berlangganan</button></form>
        @endif
    </main>
</body>
</html>
