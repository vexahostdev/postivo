@extends('layouts.admin')
@section('title', 'Organisasi')
@section('content')
<x-page-header title="Organisasi" sub="Semua tenant di platform." />
<form method="GET" class="mb-4 flex flex-wrap gap-2">
    <input class="input max-w-xs" name="q" value="{{ request('q') }}" placeholder="Nama, slug, atau email anggota" aria-label="Cari">
    <select class="input w-auto" name="plan" onchange="this.form.submit()" aria-label="Plan"><option value="">Semua plan</option>@foreach($plans as $k => $p)<option value="{{ $k }}" @selected(request('plan') === $k)>{{ $p['name'] }}</option>@endforeach</select>
    <select class="input w-auto" name="status" onchange="this.form.submit()" aria-label="Status"><option value="">Semua status</option>@foreach(['ACTIVE', 'SUSPENDED'] as $s)<option value="{{ $s }}" @selected(request('status') === $s)>{{ $s }}</option>@endforeach</select>
    <button class="btn-outline">Cari</button>
</form>
<div class="card overflow-x-auto">
    <table class="w-full min-w-[760px]">
        <thead><tr><th class="th">Organisasi</th><th class="th">Plan</th><th class="th">Berakhir</th><th class="th">Anggota</th><th class="th">Status</th><th class="th">Dibuat</th></tr></thead>
        <tbody>
        @forelse($orgs as $o)
            <tr class="hover:bg-paper">
                <td class="td"><a class="font-semibold underline-offset-2 hover:underline" href="{{ route('admin.organizations.show', $o->id) }}">{{ $o->name }}</a><p class="mono text-muted">{{ $o->slug }}</p></td>
                <td class="td mono">{{ $o->plan }}</td>
                <td class="td mono text-muted">{{ $o->plan_expires_at?->format('d M Y') ?? '—' }}</td>
                <td class="td mono">{{ $o->members_count }}</td>
                <td class="td"><x-status-pill :status="$o->status === 'SUSPENDED' ? 'SUSPENDED' : 'ACTIVE'" /></td>
                <td class="td mono text-muted">{{ $o->created_at->diffForHumans() }}</td>
            </tr>
        @empty<tr><td class="td text-muted" colspan="6">Tidak ada hasil.</td></tr>@endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $orgs->links() }}</div>
@endsection
