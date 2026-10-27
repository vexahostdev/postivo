@props(['type' => 'info', 'title' => null])
@php $c = ['info' => 'border-ink bg-paper', 'warn' => 'border-warn bg-white', 'danger' => 'border-red-500 bg-red-50'][$type] ?? 'border-ink bg-paper'; @endphp
<div {{ $attributes->class(["my-4 border-l-4 px-4 py-3 text-sm", $c]) }}>
    @if($title)<p class="mb-1 font-semibold">{{ $title }}</p>@endif
    <div class="space-y-1 leading-relaxed">{{ $slot }}</div>
</div>
