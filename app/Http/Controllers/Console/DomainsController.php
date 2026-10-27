<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Api\V1\DomainController as ApiDomain;
use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Services\DnsAuditorService;
use App\Services\DomainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DomainsController extends Controller
{
    public function index()
    {
        return view('console.domains.index', ['domains' => Domain::query()->with('records')->latest()->get()]);
    }

    public function store(Request $request, DomainService $svc): RedirectResponse
    {
        $data = $request->validate(['domain' => ApiDomain::nameRules()]);
        $name = strtolower($data['domain']);
        if (Domain::withTrashed()->withoutGlobalScopes()->where('domain_name', $name)->exists()) {
            return back()->withInput()->withErrors(['domain' => 'Domain ini sudah terdaftar.']);
        }
        $domain = $svc->create(app('org.current'), $name);

        return redirect()->route('console.domains.show', $domain)->with('status', 'Domain ditambahkan. Pasang record DNS di bawah, lalu klik Verifikasi.');
    }

    public function show(string $id)
    {
        return view('console.domains.show', ['domain' => Domain::query()->with('records')->findOrFail($id)]);
    }

    public function verify(Request $request, string $id, DnsAuditorService $auditor): JsonResponse|RedirectResponse
    {
        $domain = Domain::query()->with('records')->findOrFail($id);
        // Cooldown 10 detik per domain agar DoH tidak dibanjiri.
        if (! Cache::add("postivo:verify:{$domain->id}", 1, 10)) {
            return $request->expectsJson()
                ? response()->json(['error' => 'Tunggu beberapa detik sebelum verifikasi ulang.'], 429)
                : back()->with('error', 'Tunggu beberapa detik sebelum verifikasi ulang.');
        }
        $domain = $auditor->audit($domain);

        if ($request->expectsJson()) {
            return response()->json([
                'status' => $domain->status,
                'records' => $domain->records->map(fn ($r) => ['purpose' => $r->purpose, 'verified' => $r->is_verified, 'seen' => $r->last_seen_value]),
            ]);
        }

        return back()->with('status', $domain->isActive() ? 'Domain aktif — siap mengirim.' : 'Beberapa record belum terdeteksi. DNS bisa butuh waktu propagasi.');
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $domain = Domain::query()->findOrFail($id);
        $domain->update([
            'track_opens_default' => $request->boolean('track_opens_default'),
            'track_clicks_default' => $request->boolean('track_clicks_default'),
        ]);

        return back()->with('status', 'Pengaturan domain disimpan.');
    }

    public function destroy(string $id): RedirectResponse
    {
        Domain::query()->findOrFail($id)->delete();

        return redirect()->route('console.domains.index')->with('status', 'Domain dihapus.');
    }
}
