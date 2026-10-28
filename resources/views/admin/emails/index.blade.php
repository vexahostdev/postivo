@extends('layouts.admin')
@section('title', 'Email')
@section('content')
<x-page-header title="Email" sub="Pencarian lintas organisasi: penerima, Message-ID, atau ID email/dispatch." />
<form method="GET" class="mb-4 flex flex-wrap gap-2">
    <input class="input max-w-sm" name="q" value="{{ request('q') }}" placeholder="Penerima / Message-ID / ID" aria-label="Cari">
    <select class="input w-auto" name="status" onchange="this.form.submit()" aria-label="Status"><option value="">Semua status</option>@foreach(['QUEUED','SENDING','DEFERRED','SENT','DELIVERED','BOUNCED','COMPLAINED','FAILED','SUPPRESSED'] as $s)<option value="{{ $s }}" @selected(strtoupper(request('status', '')) === $s)>{{ $s }}</option>@endforeach</select>
    @if(request('org'))<input type="hidden" name="org" value="{{ request('org') }}"><span class="mono self-center text-muted">org: {{ \Illuminate\Support\Str::limit(request('org'), 8, '') }}…</span>@endif
    <button class="btn-outline">Cari</button>
</form>
<div class="card overflow-x-auto">
    <table class="w-full min-w-[820px]">
        <thead><tr><th class="th">Penerima</th><th class="th">Subjek</th><th class="th">Organisasi</th><th class="th">Status</th><th class="th">Relay</th><th class="th">Waktu</th></tr></thead>
        <tbody>@forelse($dispatches as $d)
            <tr class="hover:bg-paper">
                <td class="td mono"><a class="underline-offset-2 hover:underline" href="{{ route('admin.emails.show', $d->id) }}">{{ $d->recipient }}</a></td>
                <td class="td"><p class="max-w-xs truncate" title="{{ $d->email?->subject }}">{{ $d->email?->subject }}</p></td>
                <td class="td"><a class="underline-offset-2 hover:underline" href="{{ route('admin.organizations.show', $d->organization_id) }}">{{ $orgs[$d->organization_id]->name ?? '—' }}</a></td>
                <td class="td"><x-status-pill :status="$d->status" /></td>
                <td class="td mono text-muted">{{ $d->relay ?? '—' }}</td>
                <td class="td mono text-muted">{{ $d->created_at->diffForHumans() }}</td>
            </tr>
        @empty<tr><td class="td text-muted" colspan="6">Tidak ada hasil.</td></tr>@endforelse</tbody>
    </table>
</div>
<div class="mt-4">{{ $dispatches->links() }}</div>
@endsection
