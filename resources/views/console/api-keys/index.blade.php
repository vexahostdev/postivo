@extends('layouts.app')
@section('title', 'API Keys')
@section('content')
@php $canWrite = $currentRole->atLeast(\App\Enums\MemberRole::DEVELOPER); $isAdmin = $currentRole->atLeast(\App\Enums\MemberRole::ADMIN); @endphp
<x-page-header title="API Keys" sub="Key hanya ditampilkan sekali saat dibuat. Simpan di secret manager, bukan di kode." />

@if($canWrite)
<form method="POST" action="{{ route('console.api-keys.store') }}" class="card mb-6 space-y-4 p-5">
    @csrf
    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label class="label" for="name">Nama</label>
            <input class="input" id="name" name="name" value="{{ old('name') }}" placeholder="mis. Cravacloud production" required>
            @error('name')<p class="err">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="label" for="environment">Lingkungan</label>
            <select class="input" id="environment" name="environment">
                <option value="live">Live — mengirim email sungguhan</option>
                <option value="test">Test — sandbox, tidak mengirim</option>
            </select>
        </div>
    </div>
    <fieldset>
        <legend class="label">Scope</legend>
        <div class="flex flex-wrap gap-x-5 gap-y-2">
            @foreach($scopes as $s)
                <label class="flex items-center gap-1.5 text-sm"><input type="checkbox" name="scopes[]" value="{{ $s }}" class="accent-red-500" @checked(in_array($s, old('scopes', ['emails:send'])))><span class="mono">{{ $s }}</span></label>
            @endforeach
        </div>
        <p class="hint">Beri hanya scope yang dibutuhkan (least privilege).</p>
    </fieldset>
    <button class="btn-primary"><x-icon name="plus" />Buat API key</button>
</form>
@endif

@if($keys->isEmpty())
    <x-empty-state icon="key" title="Belum ada API key" text="Buat key untuk mulai memanggil POST /v1/emails atau login SMTP." />
@else
    <div class="card overflow-x-auto">
        <table class="w-full min-w-[720px]">
            <thead><tr><th class="th">Nama</th><th class="th">Key</th><th class="th">Env</th><th class="th">Scope</th><th class="th">Terakhir dipakai</th><th class="th"></th></tr></thead>
            <tbody>
            @foreach($keys as $k)
                <tr>
                    <td class="td font-medium">{{ $k->name }}</td>
                    <td class="td mono">{{ $k->prefix }}…</td>
                    <td class="td mono {{ $k->environment === 'live' ? 'text-red-500' : 'text-muted' }}">{{ $k->environment }}</td>
                    <td class="td mono text-muted">{{ implode(' ', $k->scopes) }}</td>
                    <td class="td mono text-muted">{{ $k->last_used_at?->diffForHumans() ?? 'belum pernah' }}</td>
                    <td class="td text-right">
                        @if($isAdmin)<form method="POST" action="{{ route('console.api-keys.destroy', $k->id) }}" onsubmit="return confirm('Cabut key {{ $k->name }}? Aplikasi yang memakainya akan langsung ditolak.')">@csrf @method('DELETE')
                            <button class="btn-danger btn-sm">Cabut</button></form>@endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
@endsection
