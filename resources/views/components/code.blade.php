@props(['lang' => 'text', 'title' => null])
<div class="my-4 border border-line bg-paper" x-data="{ copied: false, async copy() { try { await navigator.clipboard.writeText(this.$refs.src.innerText.trim()); this.copied = true; setTimeout(() => this.copied = false, 1500); } catch (e) {} } }">
    <div class="flex items-center justify-between border-b border-line px-3 py-1.5">
        <span class="font-mono text-[11px] uppercase tracking-wider text-muted">{{ $title ?? $lang }}</span>
        <button type="button" @click="copy()" class="font-mono text-[11px] uppercase tracking-wider text-red-500 hover:underline" x-text="copied ? 'Tersalin' : 'Salin'">Salin</button>
    </div>
    <pre class="overflow-x-auto p-4 font-mono text-[12px] leading-relaxed"><code x-ref="src">{{ trim((string) $slot) }}</code></pre>
</div>
