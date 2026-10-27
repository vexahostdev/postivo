@extends('layouts.admin')
@section('title', 'Log audit')
@section('content')
<x-page-header title="Log audit" sub="Catatan append-only seluruh aksi admin dan sistem." />
<form method="GET" class="mb-4 flex flex-wrap items-end gap-2">
    <div><label class="label" for="a">Aksi (awalan)</label><input class="input" id="a" name="action" list="acts" value="{{ request('action') }}" placeholder="mis. org."><datalist id="acts">@foreach($actions as $a)<option value="{{ $a }}">@endforeach</datalist></div>
    <div><label class="label" for="ad">Admin</label><select class="input" id="ad" name="admin"><option value="">Semua</option><option value="system" @selected(request('admin') === 'system')>Sistem</option>@foreach($admins as $u)<option value="{{ $u->id }}" @selected(request('admin') === $u->id)>{{ $u->name }}</option>@endforeach</select></div>
    <div><label class="label" for="f">Dari</label><input class="input" id="f" type="date" name="from" value="{{ request('from') }}"></div>
    <div><label class="label" for="t">Sampai</label><input class="input" id="t" type="date" name="to" value="{{ request('to') }}"></div>
    @if(request('org'))<input type="hidden" name="org" value="{{ request('org') }}">@endif
    <button class="btn-outline">Filter</button>
</form>
<div class="card overflow-x-auto">
    <table class="w-full min-w-[900px]">
        <thead><tr><th class="th">Waktu</th><th class="th">Admin</th><th class="th">Aksi</th><th class="th">Target</th><th class="th">Alasan / detail</th><th class="th">IP</th></tr></thead>
        <tbody>@forelse($logs as $l)
            <tr class="align-top">
                <td class="td mono text-muted whitespace-nowrap" title="{{ $l->created_at }}">{{ $l->created_at->format('d M H:i:s') }}</td>
                <td class="td">{{ $l->admin?->name ?? 'sistem' }}</td>
                <td class="td mono font-bold">{{ $l->action }}</td>
                <td class="td mono text-muted">{{ $l->target_type }} {{ \Illuminate\Support\Str::limit($l->target_id, 13, '…') }}@if($l->organization)<br><a class="underline" href="{{ route('admin.organizations.show', $l->organization_id) }}">{{ $l->organization->name }}</a>@endif</td>
                <td class="td text-sm">{{ $l->reason }}@if($l->meta)<details><summary class="mono cursor-pointer text-muted">meta</summary><pre class="mono mt-1 overflow-auto bg-paper p-2">{{ json_encode($l->meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details>@endif</td>
                <td class="td mono text-muted">{{ $l->ip }}</td>
            </tr>
        @empty<tr><td class="td text-muted" colspan="6">Belum ada catatan.</td></tr>@endforelse</tbody>
    </table>
</div>
<div class="mt-4">{{ $logs->links() }}</div>
@endsection
