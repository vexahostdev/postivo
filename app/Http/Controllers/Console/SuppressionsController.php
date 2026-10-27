<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Suppression;
use App\Services\SuppressionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SuppressionsController extends Controller
{
    public function index(Request $request)
    {
        $q = Suppression::query()->orderByDesc('created_at');
        if ($s = trim((string) $request->query('q'))) {
            $q->where('email', 'like', '%'.addcslashes(strtolower($s), '%_').'%');
        }

        return view('console.suppressions.index', ['items' => $q->paginate(25)->withQueryString()]);
    }

    public function store(Request $request, SuppressionService $svc): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc'], 'note' => ['nullable', 'string', 'max:255']]);
        $svc->add(app('org.current')->id, $data['email'], 'MANUAL', null, $data['note'] ?? null);

        return back()->with('status', 'Alamat ditambahkan ke suppression list.');
    }

    public function destroy(string $id): RedirectResponse
    {
        Suppression::query()->findOrFail($id)->delete();

        return back()->with('status', 'Alamat dihapus dari suppression list.');
    }
}
