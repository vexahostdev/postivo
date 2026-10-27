@extends('layouts.admin')
@section('title', 'Pengguna')
@section('content')
<x-page-header title="Pengguna & staf" sub="Semua akun di platform." />
<form method="GET" class="mb-4 flex flex-wrap gap-2">
    <input class="input max-w-xs" name="q" value="{{ request('q') }}" placeholder="Email atau nama" aria-label="Cari">
    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="staff" value="1" @checked(request('staff')) onchange="this.form.submit()" class="accent-red-500"> Hanya staf</label>
    <button class="btn-outline">Cari</button>
</form>
<div class="card overflow-x-auto">
    <table class="w-full min-w-[720px]">
        <thead><tr><th class="th">Pengguna</th><th class="th">Verifikasi</th><th class="th">Staf</th><th class="th">Status</th><th class="th">Daftar</th></tr></thead>
        <tbody>@forelse($users as $u)
            <tr class="hover:bg-paper">
                <td class="td"><a class="font-semibold underline-offset-2 hover:underline" href="{{ route('admin.users.show', $u->id) }}">{{ $u->name }}</a><p class="mono text-muted">{{ $u->email }}</p></td>
                <td class="td mono {{ $u->hasVerifiedEmail() ? 'text-ok' : 'text-warn' }}">{{ $u->hasVerifiedEmail() ? '✓ ya' : '… belum' }}</td>
                <td class="td mono">{{ $u->platform_role?->value ?? '—' }}</td>
                <td class="td mono {{ $u->disabled_at ? 'text-red-500' : 'text-ok' }}">{{ $u->disabled_at ? 'nonaktif' : 'aktif' }}</td>
                <td class="td mono text-muted">{{ $u->created_at->diffForHumans() }}</td>
            </tr>
        @empty<tr><td class="td text-muted" colspan="5">Tidak ada hasil.</td></tr>@endforelse</tbody>
    </table>
</div>
<div class="mt-4">{{ $users->links() }}</div>
@endsection
