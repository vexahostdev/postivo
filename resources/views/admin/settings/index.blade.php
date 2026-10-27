@extends('layouts.admin')
@section('title', 'Pengaturan platform')
@section('content')
@php $can = auth()->user()->staffCan('settings.manage'); @endphp
<x-page-header title="Pengaturan platform" sub="Perubahan berlaku ≤ 60 detik dan tercatat di audit." />
<form method="POST" action="{{ route('admin.settings.update') }}" class="space-y-6">@csrf
    <section class="card space-y-4 p-5">
        <h2 class="kicker">Umum</h2>
        <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="signups_open" value="1" class="mt-1 accent-red-500" @checked($s['signups_open']) @disabled(!$can)><span><b>Pendaftaran terbuka</b> — matikan untuk menutup pendaftaran akun baru.</span></label>
        <div><label class="label" for="an">Banner pengumuman di console</label><input class="input" id="an" name="announcement" maxlength="300" value="{{ old('announcement', $s['announcement']) }}" placeholder="Kosong = tidak tampil" @disabled(!$can)></div>
    </section>
    <section class="card space-y-4 p-5">
        <h2 class="kicker">Abuse &amp; reputasi</h2>
        <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="abuse_auto_suspend" value="1" class="mt-1 accent-red-500" @checked($s['abuse_auto_suspend']) @disabled(!$can)><span><b>Auto-suspend</b> organisasi level BAHAYA (hasil <code class="mono">postivo:abuse-scan</code>, tiap jam). Mati = hanya peringatan ke support.</span></label>
        <div class="grid gap-4 sm:grid-cols-3 lg:grid-cols-5">
            @foreach([['abuse_min_volume', 'Volume minimum', '1'], ['abuse_bounce_warn', 'Bounce waspada %', '0.1'], ['abuse_bounce_danger', 'Bounce bahaya %', '0.1'], ['abuse_complaint_warn', 'Komplain waspada %', '0.01'], ['abuse_complaint_danger', 'Komplain bahaya %', '0.01']] as [$k, $l, $step])
                <div><label class="label" for="{{ $k }}">{{ $l }}</label><input class="input" id="{{ $k }}" type="number" step="{{ $step }}" min="0" name="{{ $k }}" value="{{ old($k, $s[$k]) }}" required @disabled(!$can)>@error($k)<p class="err">{{ $message }}</p>@enderror</div>
            @endforeach
        </div>
    </section>
    @if($can)
    <div class="flex flex-wrap items-end gap-3"><div class="min-w-64 flex-1"><label class="label" for="rs">Alasan perubahan</label><input class="input" id="rs" name="reason" required minlength="5" value="{{ old('reason') }}">@error('reason')<p class="err">{{ $message }}</p>@enderror</div><button class="btn-primary">Simpan pengaturan</button></div>
    @endif
</form>
@endsection
