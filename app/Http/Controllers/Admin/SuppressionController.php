<?php

namespace App\Http\Controllers\Admin;

use App\Models\Organization;
use App\Models\Suppression;
use App\Services\AdminAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SuppressionController extends AdminController
{
    public function index(Request $request)
    {
        $q = Suppression::withoutGlobalScopes()->orderByDesc('created_at');
        if ($s = trim((string) $request->query('q'))) {
            $q->where('email', 'like', '%'.addcslashes(strtolower($s), '%_').'%');
        }
        if ($r = $request->query('reason')) {
            $q->where('reason', $r);
        }
        $page = $q->paginate(25)->withQueryString();

        return view('admin.suppressions.index', ['items' => $page, 'orgs' => Organization::whereIn('id', $page->pluck('organization_id'))->get()->keyBy('id')]);
    }

    public function destroy(Request $request, string $id): RedirectResponse
    {
        $this->need('blocklist.manage');
        $reason = $this->reason($request);
        $s = Suppression::withoutGlobalScopes()->findOrFail($id);
        $s->delete();
        AdminAudit::log('suppression.remove', 'suppression', $id, $s->organization_id, $reason, ['email_hash' => $s->email_hash, 'was' => $s->reason]);

        return back()->with('status', 'Entri suppression dihapus.');
    }
}
