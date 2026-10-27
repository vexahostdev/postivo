<?php

namespace App\Http\Controllers\Admin;

use App\Models\Organization;
use App\Models\Plan;
use App\Services\AdminAudit;
use App\Support\Plans;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PlanController extends AdminController
{
    public function index()
    {
        $counts = Organization::selectRaw('plan, count(*) c')->groupBy('plan')->pluck('c', 'plan');

        return view('admin.plans.index', ['plans' => Plans::all(), 'counts' => $counts]);
    }

    public function save(Request $request, string $key): RedirectResponse
    {
        $this->need('plans.manage');
        abort_unless(Plans::get($key), 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'price' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'monthly_quota' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'daily_limit' => ['nullable', 'integer', 'min:1', 'max:1000000000'],
            'features' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        abort_if($key === 'free' && ($data['price'] ?? 0) > 0, 422, 'Plan Free harus gratis.');
        abort_if($key === 'free' && ! $request->boolean('is_active'), 422, 'Plan Free tidak boleh dinonaktifkan.');

        $before = Plans::get($key);
        Plan::updateOrCreate(['key' => $key], [
            'name' => $data['name'], 'price' => $data['price'], 'monthly_quota' => $data['monthly_quota'],
            'daily_limit' => $data['daily_limit'] ?? null,
            'features' => array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($data['features'] ?? ''))))),
            'is_active' => $request->boolean('is_active'), 'sort' => $data['sort'] ?? 0,
        ]);
        Plans::flush();
        AdminAudit::log('plan.save', 'plan', $key, null, $data['reason'], ['before' => collect($before)->only(['price', 'monthly_quota', 'daily_limit', 'is_active'])->all(), 'after' => collect($data)->only(['price', 'monthly_quota', 'daily_limit'])->all()]);

        return back()->with('status', "Plan {$data['name']} disimpan. Org yang sudah berlangganan tidak berubah sampai perpanjangan.");
    }
}
