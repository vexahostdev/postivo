<?php

namespace App\Http\Controllers\Admin;

use App\Models\EmailDispatch;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class EmailController extends AdminController
{
    public function index(Request $request)
    {
        $q = EmailDispatch::withoutGlobalScopes()->with('email')->latest();
        if ($s = trim((string) $request->query('q'))) {
            $like = '%'.addcslashes(strtolower($s), '%_').'%';
            $q->where(fn ($w) => $w->where('recipient', 'like', $like)->orWhere('message_id', 'like', $like)
                ->orWhere('id', $s)->orWhere('email_id', $s));
        }
        if ($st = strtoupper((string) $request->query('status'))) {
            $q->where('status', $st);
        }
        if ($org = $request->query('org')) {
            $q->where('organization_id', $org);
        }
        $page = $q->paginate(25)->withQueryString();

        return view('admin.emails.index', ['dispatches' => $page, 'orgs' => Organization::whereIn('id', $page->pluck('organization_id'))->get()->keyBy('id')]);
    }

    public function show(string $id)
    {
        $d = EmailDispatch::withoutGlobalScopes()->with(['events'])->findOrFail($id);
        $email = \App\Models\Email::withoutGlobalScopes()->with('domain')->findOrFail($d->email_id);
        $raw = $d->raw_mime_path && Storage::disk('local')->exists($d->raw_mime_path) ? mb_substr(Storage::disk('local')->get($d->raw_mime_path), 0, 100_000) : null;

        return view('admin.emails.show', ['d' => $d, 'email' => $email, 'org' => Organization::find($d->organization_id), 'raw' => $raw]);
    }
}
