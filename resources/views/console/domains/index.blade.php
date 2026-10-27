@extends('layouts.app')
@section('title', 'Domains')
@section('content')
@php $canWrite = $currentRole->atLeast(\App\Enums\MemberRole::DEVELOPER); @endphp
<x-page-header title="Domains" sub="Domain pengirim harus terverifikasi sebelum dapat mengirim email." />

@if($canWrite)
<form method="POST" action="{{ route('console.domains.store') }}" class="card mb-6 flex flex-wrap items-end gap-3 p-5">
    @csrf
    <div class="min-w-64 flex-1">
        <label class="label" for="domain">Tambah domain</label>
        <input class="input mono" id="domain" name="domain" value="{{ old('domain') }}" placeholder="mail.contoh.com" required>
        @error('domain')<p class="err">{{ $message }}</p>@else<p class="hint">Disarankan subdomain khusus, mis. <span class="mono">mail.contoh.com</span>.</p>@enderror
    </div>
    <button class="btn-primary"><x-icon name="plus" />Tambah</button>
</form>
@endif

@if($domains->isEmpty())
    <x-empty-state icon="globe" title="Belum ada domain" text="Tambahkan domain, pasang lima record DNS, lalu klik Verifikasi." />
@else
    <div class="card overflow-x-auto">
        <table class="w-full min-w-[640px]">
            <thead><tr><th class="th">Domain</th><th class="th">Status</th><th class="th">Record</th><th class="th">Audit terakhir</th></tr></thead>
            <tbody>
            @foreach($domains as $d)
                @php $req = $d->records->where('is_required', true); @endphp
                <tr class="hover:bg-paper">
                    <td class="td mono"><a class="font-bold underline-offset-2 hover:underline" href="{{ route('console.domains.show', $d->id) }}">{{ $d->domain_name }}</a></td>
                    <td class="td"><x-status-pill :status="$d->status" /></td>
                    <td class="td mono text-muted">{{ $req->where('is_verified', true)->count() }}/{{ $req->count() }} wajib</td>
                    <td class="td mono text-muted">{{ $d->last_audit_at?->diffForHumans() ?? 'belum pernah' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
@endsection
