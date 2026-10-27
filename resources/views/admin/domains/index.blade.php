@extends('layouts.admin')
@section('title', 'Domain')
@section('content')
@php $can = auth()->user()->staffCan('domain.manage'); @endphp
<x-page-header title="Domain" sub="Semua domain pengirim lintas organisasi." />
<form method="GET" class="mb-4 flex flex-wrap gap-2">
    <input class="input max-w-xs" name="q" value="{{ request('q') }}" placeholder="Cari domain" aria-label="Cari">
    <select class="input w-auto" name="status" onchange="this.form.submit()" aria-label="Status"><option value="">Semua status</option>@foreach(['PENDING','ACTIVE','FAILED','SUSPENDED'] as $s)<option value="{{ $s }}" @selected(request('status') === $s)>{{ $s }}</option>@endforeach</select>
    <button class="btn-outline">Cari</button>
</form>
<div class="card overflow-x-auto">
    <table class="w-full min-w-[860px]">
        <thead><tr><th class="th">Domain</th><th class="th">Organisasi</th><th class="th">Status</th><th class="th">Record wajib</th><th class="th">Audit</th><th class="th"></th></tr></thead>
        <tbody>@forelse($domains as $d)
            @php $req = $d->records->where('is_required', true); @endphp
            <tr>
                <td class="td mono font-bold">{{ $d->domain_name }}@if($d->trashed()) <span class="text-red-500">(dihapus)</span>@endif</td>
                <td class="td"><a class="underline-offset-2 hover:underline" href="{{ route('admin.organizations.show', $d->organization_id) }}">{{ $orgs[$d->organization_id]->name ?? '—' }}</a></td>
                <td class="td"><x-status-pill :status="$d->status" /></td>
                <td class="td mono text-muted">{{ $req->where('is_verified', true)->count() }}/{{ $req->count() }}</td>
                <td class="td mono text-muted">{{ $d->last_audit_at?->diffForHumans() ?? '—' }}</td>
                <td class="td">
                    @if($can)
                    <div class="flex flex-wrap justify-end gap-1.5">
                        <form method="POST" action="{{ route('admin.domains.reaudit', $d->id) }}">@csrf<button class="btn-outline btn-sm">Audit ulang</button></form>
                        <form method="POST" action="{{ route('admin.domains.suspend', $d->id) }}" class="flex gap-1">@csrf
                            <input class="input w-36 py-1 text-xs" name="reason" required minlength="5" placeholder="Alasan" aria-label="Alasan">
                            <button class="{{ $d->status === 'SUSPENDED' ? 'btn-outline' : 'btn-danger' }} btn-sm">{{ $d->status === 'SUSPENDED' ? 'Aktifkan' : 'Suspend' }}</button>
                        </form>
                    </div>
                    @endif
                </td>
            </tr>
        @empty<tr><td class="td text-muted" colspan="6">Tidak ada domain.</td></tr>@endforelse</tbody>
    </table>
</div>
<div class="mt-4">{{ $domains->links() }}</div>
@endsection
