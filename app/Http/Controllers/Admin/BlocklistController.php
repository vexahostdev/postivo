<?php

namespace App\Http\Controllers\Admin;

use App\Models\Suppression;
use App\Services\AdminAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BlocklistController extends AdminController
{
    public function index(Request $request)
    {
        return view('admin.blocklist.index', ['items' => DB::table('blocked_recipients')->orderByDesc('id')->paginate(25)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->need('blocklist.manage');
        $data = $request->validate(['pattern' => ['required', 'string', 'max:191', 'regex:/^(@[a-z0-9.-]+\.[a-z]{2,}|[^@\s]+@[^@\s]+\.[^@\s]+)$/i'], 'reason' => ['required', 'string', 'min:3', 'max:255']]);
        $pattern = strtolower($data['pattern']);
        DB::table('blocked_recipients')->updateOrInsert(['pattern' => $pattern], ['reason' => $data['reason'], 'created_by' => auth()->id(), 'created_at' => now()]);
        AdminAudit::log('blocklist.add', 'blocklist', $pattern, null, $data['reason']);

        return back()->with('status', 'Ditambahkan ke blocklist global.');
    }

    public function destroy(int $id): RedirectResponse
    {
        $this->need('blocklist.manage');
        $row = DB::table('blocked_recipients')->find($id);
        abort_unless($row, 404);
        DB::table('blocked_recipients')->delete($id);
        AdminAudit::log('blocklist.remove', 'blocklist', $row->pattern);

        return back()->with('status', 'Dihapus dari blocklist.');
    }
}
