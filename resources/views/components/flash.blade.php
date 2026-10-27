@if(session('status') || session('error') || session('reveal'))
    <div class="mb-5 space-y-3" role="status">
        @if(session('status'))
            <div class="flex items-start gap-2 border border-ok bg-white px-4 py-3 text-sm text-ok"><x-icon name="check" class="mt-0.5 size-4 shrink-0" />{{ session('status') }}</div>
        @endif
        @if(session('error'))
            <div class="flex items-start gap-2 border border-red-500 bg-red-50 px-4 py-3 text-sm text-red-600"><x-icon name="alert" class="mt-0.5 size-4 shrink-0" />{{ session('error') }}</div>
        @endif
        @if(session('reveal'))
            <div class="border border-ink bg-paper p-4" x-data="clipboard(@js(session('reveal')))">
                <p class="kicker mb-2">Tampil sekali — simpan sekarang</p>
                <div class="flex items-center gap-2">
                    <code class="mono flex-1 break-all border border-line bg-white px-3 py-2">{{ session('reveal') }}</code>
                    <button type="button" class="btn-outline btn-sm" @click="copy()"><x-icon name="copy" class="size-3.5" /><span x-text="copied ? 'Tersalin' : 'Salin'"></span></button>
                </div>
            </div>
        @endif
    </div>
@endif
