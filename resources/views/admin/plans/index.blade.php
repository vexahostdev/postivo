@extends('layouts.admin')
@section('title', 'Plan & harga')
@section('content')
@php $can = auth()->user()->staffCan('plans.manage'); @endphp
<x-page-header title="Plan & harga" sub="Perubahan berlaku langsung di checkout, landing, dan docs. Org yang sudah berlangganan tidak berubah sampai perpanjangan." />
<div class="space-y-4">
@foreach($plans as $key => $p)
    <form method="POST" action="{{ route('admin.plans.save', $key) }}" class="card grid gap-4 p-5 lg:grid-cols-12">@csrf
        <div class="lg:col-span-12 flex items-center justify-between"><h2 class="font-mono text-sm font-bold uppercase">{{ $key }}</h2><span class="hint">{{ $counts[$key] ?? 0 }} organisasi memakai plan ini</span></div>
        <div class="lg:col-span-3"><label class="label">Nama</label><input class="input" name="name" value="{{ $p['name'] }}" required @disabled(!$can)></div>
        <div class="lg:col-span-2"><label class="label">Harga / bulan (Rp)</label><input class="input" type="number" name="price" value="{{ $p['price'] }}" min="0" required @readonly($key === 'free') @disabled(!$can)></div>
        <div class="lg:col-span-2"><label class="label">Kuota / bulan</label><input class="input" type="number" name="monthly_quota" value="{{ $p['monthly_quota'] }}" min="1" required @disabled(!$can)></div>
        <div class="lg:col-span-2"><label class="label">Batas harian</label><input class="input" type="number" name="daily_limit" value="{{ $p['daily_limit'] }}" min="1" placeholder="tanpa batas" @disabled(!$can)></div>
        <div class="lg:col-span-1"><label class="label">Urutan</label><input class="input" type="number" name="sort" value="{{ $p['sort'] }}" min="0" @disabled(!$can)></div>
        <div class="lg:col-span-2 flex items-end">@if($key === 'free')<input type="hidden" name="is_active" value="1">@endif
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" @if($key !== 'free') name="is_active" value="1" @endif class="accent-red-500" @checked($p['is_active']) @disabled(!$can || $key === 'free')> Aktif (dijual)</label></div>
        <div class="lg:col-span-12"><label class="label">Fitur (satu per baris)</label><textarea class="input" name="features" rows="4" @disabled(!$can)>{{ implode("\n", $p['features'] ?? []) }}</textarea></div>
        @if($can)
        <div class="lg:col-span-9"><input class="input" name="reason" required minlength="5" placeholder="Alasan perubahan (wajib, masuk audit)" aria-label="Alasan"></div>
        <div class="lg:col-span-3"><button class="btn-primary w-full">Simpan {{ $p['name'] }}</button></div>
        @endif
    </form>
@endforeach
</div>
@endsection
