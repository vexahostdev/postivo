@extends('layouts.admin')
@section('title', 'Blocklist global')
@section('content')
@php $can = auth()->user()->staffCan('blocklist.manage'); @endphp
<x-page-header title="Blocklist global" sub="Penerima yang tidak boleh dikirimi email oleh organisasi mana pun (alamat persis atau @domain)." />
@if($can)
<form method="POST" action="{{ route('admin.blocklist.store') }}" class="card mb-6 flex flex-wrap items-end gap-3 p-5">@csrf
    <div class="min-w-56 flex-1"><label class="label" for="pt">Alamat atau domain</label><input class="input mono" id="pt" name="pattern" value="{{ old('pattern') }}" placeholder="spam@contoh.com atau @domain-buangan.com" required>@error('pattern')<p class="err">{{ $message }}</p>@enderror</div>
    <div class="min-w-56 flex-1"><label class="label" for="rs">Alasan</label><input class="input" id="rs" name="reason" value="{{ old('reason') }}" required minlength="3">@error('reason')<p class="err">{{ $message }}</p>@enderror</div>
    <button class="btn-primary"><x-icon name="plus" />Blokir</button>
</form>
@endif
<div class="card overflow-x-auto">
    <table class="w-full min-w-[640px]">
        <thead><tr><th class="th">Pola</th><th class="th">Alasan</th><th class="th">Ditambahkan</th><th class="th"></th></tr></thead>
        <tbody>@forelse($items as $i)
            <tr><td class="td mono font-bold">{{ $i->pattern }}</td><td class="td">{{ $i->reason }}</td><td class="td mono text-muted">{{ \Illuminate\Support\Carbon::parse($i->created_at)->diffForHumans() }}</td>
                <td class="td text-right">@if($can)<form method="POST" action="{{ route('admin.blocklist.destroy', $i->id) }}" onsubmit="return confirm('Hapus dari blocklist?')">@csrf @method('DELETE')<button class="btn-ghost btn-sm">Hapus</button></form>@endif</td></tr>
        @empty<tr><td class="td text-muted" colspan="4">Blocklist kosong.</td></tr>@endforelse</tbody>
    </table>
</div>
<div class="mt-4">{{ $items->links() }}</div>
@endsection
