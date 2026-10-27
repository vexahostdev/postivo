@extends('layouts.app')
@section('title', 'Emails')
@section('content')
@php $statuses = ['QUEUED', 'SENT', 'DELIVERED', 'DEFERRED', 'BOUNCED', 'COMPLAINED', 'FAILED', 'SUPPRESSED']; @endphp
<x-page-header title="Emails" sub="Log semua email — satu baris per penerima." />

<form method="GET" class="mb-4 flex flex-wrap gap-2">
    <input class="input max-w-xs" name="q" value="{{ request('q') }}" placeholder="Cari penerima atau subjek" aria-label="Cari">
    <select class="input w-auto" name="status" aria-label="Filter status" onchange="this.form.submit()">
        <option value="">Semua status</option>
        @foreach($statuses as $s)<option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst(strtolower($s)) }}</option>@endforeach
    </select>
    <button class="btn-outline">Cari</button>
    @if(request()->hasAny(['q', 'status']))<a href="{{ route('console.emails.index') }}" class="btn-ghost">Reset</a>@endif
</form>

@if($dispatches->isEmpty())
    <x-empty-state :title="request()->hasAny(['q','status']) ? 'Tidak ada hasil' : 'Belum ada email'" :text="request()->hasAny(['q','status']) ? 'Ubah kata kunci atau filter.' : 'Kirim email pertama lewat POST /v1/emails.'" />
@else
    <div class="card overflow-x-auto">
        <table class="w-full min-w-[720px]">
            <thead><tr><th class="th">Penerima</th><th class="th">Subjek</th><th class="th">Status</th><th class="th">Relay</th><th class="th">Waktu</th></tr></thead>
            <tbody>
            @foreach($dispatches as $d)
                <tr class="hover:bg-paper">
                    <td class="td mono"><a class="underline-offset-2 hover:underline" href="{{ route('console.emails.show', $d->id) }}">{{ $d->recipient }}</a></td>
                    <td class="td max-w-xs truncate">{{ $d->email->subject }}</td>
                    <td class="td"><x-status-pill :status="$d->status" /></td>
                    <td class="td mono text-muted">{{ $d->relay ?? '—' }}</td>
                    <td class="td mono text-muted" title="{{ $d->created_at }}">{{ $d->created_at->diffForHumans() }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $dispatches->links() }}</div>
@endif
@endsection
