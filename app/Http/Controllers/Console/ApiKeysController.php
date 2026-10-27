<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Services\ApiKeyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ApiKeysController extends Controller
{
    public function index()
    {
        return view('console.api-keys.index', [
            'keys' => ApiKey::query()->whereNull('revoked_at')->latest()->get(),
            'scopes' => ApiKeyService::ALL_SCOPES,
        ]);
    }

    public function store(Request $request, ApiKeyService $svc): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'environment' => ['required', 'in:live,test'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['in:'.implode(',', ApiKeyService::ALL_SCOPES)],
        ]);
        if ($data['environment'] === 'live' && ! $request->user()->hasVerifiedEmail()) {
            return back()->withInput()->with('error', 'Verifikasi email Anda dulu untuk membuat API key live. Key test (sandbox) tetap bisa dibuat.');
        }
        [$key, $plain] = $svc->create(app('org.current'), $data['name'], $data['environment'], $data['scopes'], null, $request->user());
        $request->user()->notify(new \App\Notifications\ApiKeyCreatedNotification($key));

        return back()->with('status', 'API key dibuat. Salin sekarang — tidak akan ditampilkan lagi.')->with('reveal', $plain);
    }

    public function destroy(string $id): RedirectResponse
    {
        ApiKey::query()->findOrFail($id)->update(['revoked_at' => now()]);

        return back()->with('status', 'API key dicabut.');
    }
}
