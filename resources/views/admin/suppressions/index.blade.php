@extends('layouts.admin')
@section('title', 'Suppression')
@section('content')
@php $can = auth()->user()->staffCan('blocklist.manage'); @endphp
<x-page-header title="Suppression (lintas org)" sub="Pencarian untuk membantu pelanggan. Menghapus entri wajib beralasan." />
<form method="GET" class="mb-4 flex flex-wrap gap-2">
    <input class="input max-w-sm" name="q" value="{{ request('q') }}" placeholder="Cari alamat email" aria-label="Cari">
    <select class="input w-auto" name="reason" onchange="this.form.submit()" aria-label="Alasan"><option value="">Semua alasan</option>@foreach(['HARD_BOUNCE','SOFT_REPEATED','COMPLAINT','UNSUBSCRIBE','MANUAL'] as $r)<option value="{{ $r }}" @selected(request('reason') === $r)>{{ $r }}</option>@endforeach</select>
    <button class="btn-outline">Cari</button>
</form>
<div class="card overflow-x-auto">
    <table class="w-full min-w-[820px]">
        <thead><tr><th class="th">Alamat</th><th class="th">Organisasi</th><th class="th">Alasan</th><th class="th">Sejak</th><th class="th"></th></tr></thead>
        <tbody>@forelse($items as $s)
            <tr><td class="td mono">{{ $s->email }}</td>
                <td class="td"><a class="underline-offset-2 hover:underline" href="{{ route('admin.organizations.show', $s->organization_id) }}">{{ $orgs[$s->organization_id]->name ?? '—' }}</a></td>
                <td class="td mono text-muted">{{ $s->reason }}</td><td class="td mono text-muted">{{ $s->created_at?->diffForHumans() }}</td>
                <td class="td">@if($can)<form method="POST" action="{{ route('admin.suppressions.destroy', $s->id) }}" class="flex justify-end gap-1" onsubmit="return confirm('Hapus entri ini?')">@csrf @method('DELETE')
                    <input class="input w-40 py-1 text-xs" name="reason" required minlength="5" placeholder="Alasan" aria-label="Alasan"><button class="btn-danger btn-sm">Hapus</button></form>@endif</td></tr>
        @empty<tr><td class="td text-muted" colspan="5">Tidak ada hasil.</td></tr>@endforelse</tbody>
    </table>
</div>
<div class="mt-4">{{ $items->links() }}</div>
@endsection
