@props(['lang' => 'text', 'title' => null])
<div class="my-4 border border-line bg-paper" x-data="{ copied: false, failed: false, async copy() { if (await toClipboard(this.$refs.src.innerText.trim())) { this.copied = true; setTimeout(() => this.copied = false, 1500); } else { this.failed = true; setTimeout(() => this.failed = false, 2500); } } }">
    <div class="flex items-center justify-between border-b border-line px-3 py-1.5">
        <span class="font-mono text-[11px] uppercase tracking-wider text-muted">{{ $title ?? $lang }}</span>
        <button type="button" @click="copy()" class="min-h-8 p-1 font-mono text-[11px] uppercase tracking-wider text-red-500 hover:underline"><span x-show="!copied && !failed">Salin</span><span x-cloak x-show="copied">Tersalin</span><span x-cloak x-show="failed">Select &amp; salin manual</span></button>
    </div>
    <pre class="overflow-x-auto p-4 font-mono text-[12px] leading-relaxed"><code x-ref="src">{{ trim((string) $slot) }}</code></pre>
</div>
