<?php

namespace App\Http\Controllers\Admin;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuditController extends AdminController
{
    public function __invoke(Request $request)
    {
        $q = AdminAuditLog::with(['admin', 'organization'])->latest('id');
        if ($a = $request->query('action')) {
            $q->where('action', 'like', addcslashes($a, '%_').'%');
        }
        if ($adm = $request->query('admin')) {
            $adm === 'system' ? $q->whereNull('admin_id') : $q->where('admin_id', $adm);
        }
        if ($org = $request->query('org')) {
            $q->where('organization_id', $org);
        }
        if ($from = $request->query('from')) {
            $q->where('created_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $q->where('created_at', '<=', $to.' 23:59:59');
        }

        return view('admin.audit.index', [
            'logs' => $q->paginate(30)->withQueryString(),
            'admins' => User::whereNotNull('platform_role')->get(['id', 'name']),
            'actions' => AdminAuditLog::select('action')->distinct()->orderBy('action')->pluck('action'),
        ]);
    }
}
