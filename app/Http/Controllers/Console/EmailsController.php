<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\EmailDispatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class EmailsController extends Controller
{
    public function index(Request $request)
    {
        $q = EmailDispatch::query()->with('email')->latest();

        if ($status = strtoupper((string) $request->query('status'))) {
            $q->where('status', $status);
        }
        if ($search = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('recipient', 'like', '%'.addcslashes(strtolower($search), '%_').'%')
                ->orWhereHas('email', fn ($e) => $e->where('subject', 'like', '%'.addcslashes($search, '%_').'%')));
        }

        return view('console.emails.index', ['dispatches' => $q->paginate(25)->withQueryString()]);
    }

    public function show(string $id)
    {
        $d = EmailDispatch::query()->with(['email.domain', 'events'])->findOrFail($id);
        $raw = $d->raw_mime_path && Storage::disk('local')->exists($d->raw_mime_path)
            ? mb_substr(Storage::disk('local')->get($d->raw_mime_path), 0, 200_000) : null;

        return view('console.emails.show', ['d' => $d, 'email' => $d->email, 'raw' => $raw]);
    }
}
