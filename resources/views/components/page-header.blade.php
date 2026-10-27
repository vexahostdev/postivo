@props(['title', 'sub' => null])
<div class="mb-6 flex flex-wrap items-end justify-between gap-3 border-b border-line pb-4">
    <div>
        <h1 class="text-xl font-semibold tracking-tight sm:text-2xl">{{ $title }}</h1>
        @if($sub)<p class="mt-1 text-sm text-muted">{{ $sub }}</p>@endif
    </div>
    <div class="flex items-center gap-2">{{ $slot }}</div>
</div>
