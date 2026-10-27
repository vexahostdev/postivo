@extends('layouts.admin')
@section('title', 'Branding')
@section('content')
@php $can = auth()->user()->staffCan('settings.manage'); @endphp
<x-page-header title="Branding" sub="Nama, logo, favicon, footer, dan warna aksen untuk landing, auth, console, dan admin. Kosongkan field untuk kembali ke bawaan." />
<form method="POST" action="{{ route('admin.branding.update') }}" enctype="multipart/form-data" class="space-y-6"
      x-data="{ accent: '{{ old('brand_accent', $s['brand_accent']) ?: \App\Support\Brand::DEFAULT_ACCENT }}' }">@csrf
    <section class="card space-y-4 p-5">
        <h2 class="kicker">Identitas</h2>
        <div class="grid gap-4 sm:grid-cols-2">
            <div><label class="label" for="bn">Nama platform</label><input class="input" id="bn" name="brand_name" maxlength="40" value="{{ old('brand_name', $s['brand_name']) }}" placeholder="Postivo" @disabled(!$can)>@error('brand_name')<p class="err">{{ $message }}</p>@enderror</div>
            <div><label class="label" for="bt">Tagline</label><input class="input" id="bt" name="brand_tagline" maxlength="120" value="{{ old('brand_tagline', $s['brand_tagline']) }}" placeholder="{{ \App\Support\Brand::tagline() }}" @disabled(!$can)>@error('brand_tagline')<p class="err">{{ $message }}</p>@enderror</div>
        </div>
        <div><label class="label" for="bf">Teks footer</label><input class="input" id="bf" name="brand_footer" maxlength="160" value="{{ old('brand_footer', $s['brand_footer']) }}" placeholder="{{ \App\Support\Brand::footer() }}" @disabled(!$can)>@error('brand_footer')<p class="err">{{ $message }}</p>@enderror</div>
    </section>

    <section class="card space-y-4 p-5">
        <h2 class="kicker">Logo &amp; favicon</h2>
        <div class="grid gap-6 sm:grid-cols-2">
            <div>
                <p class="label">Logo (PNG/JPG/WebP, ≤ 1 MB)</p>
                <div class="mb-3 flex h-20 items-center border border-line bg-paper px-4"><img src="{{ \App\Support\Brand::logo() }}" alt="Logo saat ini" class="max-h-12 w-auto"></div>
                <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" class="input" @disabled(!$can)>@error('logo')<p class="err">{{ $message }}</p>@enderror
                @if($s['brand_logo'])<label class="mt-2 flex items-center gap-2 text-sm"><input type="checkbox" name="reset_logo" value="1" class="accent-red-500" @disabled(!$can)>Kembalikan ke logo bawaan</label>@endif
            </div>
            <div>
                <p class="label">Favicon (PNG/ICO/WebP, ≤ 256 KB)</p>
                <div class="mb-3 flex h-20 items-center border border-line bg-paper px-4"><img src="{{ \App\Support\Brand::favicon() }}" alt="Favicon saat ini" class="size-8"></div>
                <input type="file" name="favicon" accept="image/png,image/x-icon,image/webp" class="input" @disabled(!$can)>@error('favicon')<p class="err">{{ $message }}</p>@enderror
                @if($s['brand_favicon'])<label class="mt-2 flex items-center gap-2 text-sm"><input type="checkbox" name="reset_favicon" value="1" class="accent-red-500" @disabled(!$can)>Kembalikan ke favicon bawaan</label>@endif
            </div>
        </div>
    </section>

    <section class="card space-y-4 p-5">
        <h2 class="kicker">Warna aksen</h2>
        <div class="flex flex-wrap items-center gap-4">
            <input type="color" x-model="accent" class="h-10 w-14 border border-line bg-white p-1" aria-label="Pilih warna" @disabled(!$can)>
            <input class="input mono max-w-40" name="brand_accent" x-model="accent" maxlength="7" pattern="#[0-9A-Fa-f]{6}" placeholder="#FE0000" @disabled(!$can)>
            <span class="inline-flex items-center gap-2 text-sm"><span class="inline-block size-5 border border-line" :style="`background:${accent}`"></span>Pratinjau</span>
            <button type="button" class="btn-ghost btn-sm" @click="accent = ''" @disabled(!$can)>Reset</button>
        </div>
        <p class="text-xs text-muted">Menggantikan merah Postivo (tombol, tautan aktif, fokus). Pilih warna dengan kontras cukup terhadap teks putih. Kosong = bawaan {{ \App\Support\Brand::DEFAULT_ACCENT }}.</p>
        @error('brand_accent')<p class="err">Format warna harus #RRGGBB.</p>@enderror
    </section>

    @if($can)
    <div class="flex flex-wrap items-end gap-3"><div class="min-w-64 flex-1"><label class="label" for="rs">Alasan perubahan</label><input class="input" id="rs" name="reason" required minlength="5" value="{{ old('reason') }}">@error('reason')<p class="err">{{ $message }}</p>@enderror</div><button class="btn-primary">Simpan branding</button></div>
    @endif
</form>
@endsection
