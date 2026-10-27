@extends('layouts.app')
@section('title', 'Suppressions')
@section('content')
@php $canWrite = $currentRole->atLeast(\App\Enums\MemberRole::DEVELOPER); @endphp
<x-page-header title="Suppressions" sub="Alamat yang tidak akan menerima email dari organisasi ini." />

<div class="mb-6 grid gap-4 lg:grid-cols-2">
    <form method="GET" class="flex gap-2">
        <input class="input" name="q" value="{{ request('q') }}" placeholder="Cari alamat email" aria-label="Cari">
        <button class="btn-outline">Cari</button>
    </form>
    @if($canWrite)
    <form method="POST" action="{{ route('console.suppressions.store') }}" class="flex gap-2">
        @csrf
        <input class="input" type="email" name="email" placeholder="Tambah alamat manual" required aria-label="Alamat email">
        <button class="btn-primary"><x-icon name="plus" />Tambah</button>
    </form>
    @endif
</div>
@error('email')<p class="err mb-3">{{ $message }}</p>@enderror

@if($items->isEmpty())
    <x-empty-state icon="ban" title="Suppression list kosong" text="Hard bounce, komplain, dan unsubscribe akan masuk otomatis ke sini." />
@else
    <div class="card overflow-x-auto">
        <table class="w-full min-w-[640px]">
            <thead><tr><th class="th">Alamat</th><th class="th">Alasan</th><th class="th">Ditambahkan</th><th class="th"></th></tr></thead>
            <tbody>
            @foreach($items as $s)
                <tr>
                    <td class="td mono">{{ $s->email }}</td>
                    <td class="td mono text-muted">{{ str_replace('_', ' ', $s->reason) }}</td>
                    <td class="td mono text-muted">{{ $s->created_at?->diffForHumans() }}</td>
                    <td class="td text-right">
                        @if($canWrite)
                        <form method="POST" action="{{ route('console.suppressions.destroy', $s->id) }}" onsubmit="return confirm('Hapus {{ $s->email }} dari suppression list?')">@csrf @method('DELETE')
                            <button class="btn-ghost btn-sm" aria-label="Hapus"><x-icon name="trash" class="size-3.5" />Hapus</button></form>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $items->links() }}</div>
@endif
@endsection
