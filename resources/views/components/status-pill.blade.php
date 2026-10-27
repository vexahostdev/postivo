@props(['status'])
@php
    $s = $status instanceof \BackedEnum ? $status->value : strtoupper((string) $status);
    // [teks, border, latar, ikon] — status tidak pernah hanya berupa warna (ikon + label).
    $map = [
        'QUEUED' => ['text-muted', 'border-line', '', 'clock'],
        'SENDING' => ['text-warn', 'border-warn', '', 'refresh'],
        'DEFERRED' => ['text-warn', 'border-warn', '', 'clock'],
        'SENT' => ['text-ink', 'border-ink', '', 'mail'],
        'DELIVERED' => ['text-ok', 'border-ok', '', 'check'],
        'BOUNCED' => ['text-red-500', 'border-red-500', '', 'x'],
        'FAILED' => ['text-red-500', 'border-red-500', '', 'x'],
        'COMPLAINED' => ['text-red-600', 'border-red-500', 'bg-red-50', 'alert'],
        'SUPPRESSED' => ['text-muted line-through', 'border-line', '', 'ban'],
        'PENDING' => ['text-warn', 'border-warn', '', 'clock'],
        'ACTIVE' => ['text-ok', 'border-ok', '', 'check'],
        'SUSPENDED' => ['text-red-600', 'border-red-500', 'bg-red-50', 'alert'],
    ];
    [$text, $border, $bg, $icon] = $map[$s] ?? ['text-muted', 'border-line', '', 'clock'];
@endphp
<span {{ $attributes->class(["inline-flex items-center gap-1 border px-1.5 py-0.5 font-mono text-[11px] uppercase tracking-wide", $text, $border, $bg]) }}>
    <x-icon :name="$icon" class="size-3" />{{ ucfirst(strtolower($s)) }}
</span>
