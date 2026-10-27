@props(['title', 'text' => null, 'icon' => 'mail'])
<div class="border border-dashed border-line px-6 py-12 text-center">
    <x-icon :name="$icon" class="mx-auto size-6 text-muted" />
    <p class="mt-3 text-sm font-semibold">{{ $title }}</p>
    @if($text)<p class="mx-auto mt-1 max-w-md text-sm text-muted">{{ $text }}</p>@endif
    <div class="mt-4 flex justify-center">{{ $slot }}</div>
</div>
