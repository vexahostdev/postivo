<?php

namespace App\Http\Controllers\Admin;

use App\Models\Domain;
use App\Models\Organization;
use App\Services\AdminAudit;
use App\Services\DnsAuditorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DomainController extends AdminController
{
    public function index(Request $request)
    {
        $q = Domain::withoutGlobalScopes()->with('records')->latest();
        if ($s = trim((string) $request->query('q'))) {
            $q->where('domain_name', 'like', '%'.addcslashes(strtolower($s), '%_').'%');
        }
        if ($st = $request->query('status')) {
            $q->where('status', $st);
        }
        $page = $q->paginate(25)->withQueryString();
        $orgs = Organization::whereIn('id', $page->pluck('organization_id'))->get()->keyBy('id');

        return view('admin.domains.index', ['domains' => $page, 'orgs' => $orgs]);
    }

    public function reaudit(string $id, DnsAuditorService $auditor): RedirectResponse
    {
        $this->need('domain.manage');
        $d = $auditor->audit(Domain::withoutGlobalScopes()->with('records')->findOrFail($id));
        AdminAudit::log('domain.reaudit', 'domain', $id, $d->organization_id, null, ['status' => $d->status]);

        return back()->with('status', "Audit selesai — status {$d->status}.");
    }

    /** Suspend domain (mis. phishing) atau aktifkan kembali lewat audit DNS. */
    public function toggleSuspend(Request $request, string $id, DnsAuditorService $auditor): RedirectResponse
    {
        $this->need('domain.manage');
        $reason = $this->reason($request);
        $d = Domain::withoutGlobalScopes()->with('records')->findOrFail($id);

        if ($d->status === 'SUSPENDED') {
            $d->update(['status' => 'PENDING']);
            $auditor->audit($d->refresh());
            $action = 'domain.unsuspend';
        } else {
            $d->update(['status' => 'SUSPENDED']);
            $action = 'domain.suspend';
        }
        AdminAudit::log($action, 'domain', $id, $d->organization_id, $reason);

        return back()->with('status', 'Status domain diperbarui.');
    }
}
