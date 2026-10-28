<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MemberRole;
use App\Models\AdminAuditLog;
use App\Models\Domain;
use App\Models\Member;
use App\Models\Order;
use App\Models\Organization;
use App\Services\AdminAudit;
use App\Services\ReputationService;
use App\Support\Plans;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OrganizationController extends AdminController
{
    public function index(Request $request)
    {
        $q = Organization::query()->withCount('members')->latest();
        if ($s = trim((string) $request->query('q'))) {
            $like = '%'.addcslashes($s, '%_').'%';
            $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('slug', 'like', $like)
                ->orWhereIn('id', Member::whereHas('user', fn ($u) => $u->where('email', 'like', $like))->pluck('organization_id')));
        }
        foreach (['plan', 'status'] as $f) {
            if ($v = $request->query($f)) {
                $q->where($f, $v);
            }
        }

        return view('admin.organizations.index', ['orgs' => $q->paginate(25)->withQueryString(), 'plans' => Plans::all()]);
    }

    public function show(string $id, ReputationService $rep)
    {
        $org = Organization::findOrFail($id);
        $period = now()->format('Y-m');

        return view('admin.organizations.show', [
            'org' => $org,
            'members' => Member::with('user')->where('organization_id', $id)->get(),
            'domains' => Domain::withoutGlobalScopes()->where('organization_id', $id)->get(),
            'usage' => DB::table('usage_counters')->where('organization_id', $id)->where('period', $period)->first(),
            'rep' => $rep->forOrg($id),
            'orders' => Order::withoutGlobalScopes()->where('organization_id', $id)->latest()->limit(10)->get(),
            'audit' => AdminAuditLog::with('admin')->where('organization_id', $id)->latest('id')->limit(15)->get(),
            'plans' => Plans::all(),
        ]);
    }

    public function suspend(Request $request, string $id, ReputationService $rep): RedirectResponse
    {
        $this->need('org.suspend');
        $reason = $this->reason($request);
        $org = Organization::findOrFail($id);
        $rep->suspend($org, $reason);
        AdminAudit::log('org.suspend', 'organization', $id, $id, $reason);

        return back()->with('status', 'Organisasi ditangguhkan; owner/admin diberi tahu lewat email.');
    }

    public function unsuspend(Request $request, string $id, ReputationService $rep): RedirectResponse
    {
        $this->need('org.suspend');
        $reason = $this->reason($request);
        $rep->unsuspend(Organization::findOrFail($id));
        AdminAudit::log('org.unsuspend', 'organization', $id, $id, $reason);

        return back()->with('status', 'Penangguhan dicabut.');
    }

    /** Beri/ubah plan secara manual (mis. kompensasi, deal khusus) tanpa pembayaran. */
    public function plan(Request $request, string $id): RedirectResponse
    {
        $this->need('org.plan');
        $data = $request->validate([
            'plan' => ['required', 'string'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'monthly_quota' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'daily_limit' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        $plan = Plans::get($data['plan']);
        abort_unless($plan, 422, 'Plan tidak dikenal.');
        $org = Organization::findOrFail($id);
        $before = $org->only(['plan', 'monthly_quota', 'daily_limit', 'plan_expires_at']);

        $org->update([
            'plan' => $data['plan'],
            'monthly_quota' => $data['monthly_quota'] ?? $plan['monthly_quota'],
            'daily_limit' => array_key_exists('daily_limit', $data) && $data['daily_limit'] !== null ? $data['daily_limit'] : $plan['daily_limit'],
            'plan_expires_at' => $data['plan'] === 'free' ? null : ($data['expires_at'] ?? now()->addMonth()),
        ]);
        AdminAudit::log('org.plan', 'organization', $id, $id, $data['reason'], ['before' => $before, 'after' => $org->only(['plan', 'monthly_quota', 'daily_limit', 'plan_expires_at'])]);

        return back()->with('status', 'Plan diperbarui.');
    }

    public function note(Request $request, string $id): RedirectResponse
    {
        $this->need('org.note');
        $data = $request->validate(['internal_notes' => ['nullable', 'string', 'max:5000']]);
        Organization::findOrFail($id)->update($data);
        AdminAudit::log('org.note', 'organization', $id, $id);

        return back()->with('status', 'Catatan internal disimpan.');
    }

    /** Impersonasi hanya-baca sebagai owner organisasi. */
    public function impersonate(Request $request, string $id): RedirectResponse
    {
        $this->need('impersonate');
        $reason = $this->reason($request);
        $owner = Member::with('user')->where('organization_id', $id)->where('role', MemberRole::OWNER->value)->first()?->user;
        abort_unless($owner && ! $owner->isStaff(), 422, 'Owner organisasi tidak tersedia untuk impersonasi.');

        $adminId = Auth::id();
        $passwordHash = Auth::guard()->hashPasswordForCookie(Auth::user()->getAuthPassword());
        AdminAudit::log('impersonate.start', 'organization', $id, $id, $reason, ['as_user' => $owner->id]);
        Auth::login($owner);
        session(['impersonator_id' => $adminId, 'impersonator_password_hash' => $passwordHash, 'org_id' => $id]);

        return redirect()->route('console.dashboard');
    }
}
