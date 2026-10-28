<?php

namespace App\Http\Controllers\Admin;

use App\Services\AdminAudit;
use App\Services\PlatformSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SettingsController extends AdminController
{
    public function index()
    {
        return view('admin.settings.index', ['s' => PlatformSettings::all()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->need('settings.manage');
        $d = $request->validate([
            'announcement' => ['nullable', 'string', 'max:300'],
            'abuse_min_volume' => ['required', 'integer', 'min:10', 'max:100000'],
            'abuse_bounce_warn' => ['required', 'numeric', 'min:0', 'max:100'],
            'abuse_bounce_danger' => ['required', 'numeric', 'min:0', 'max:100', 'gte:abuse_bounce_warn'],
            'abuse_complaint_warn' => ['required', 'numeric', 'min:0', 'max:100'],
            'abuse_complaint_danger' => ['required', 'numeric', 'min:0', 'max:100', 'gte:abuse_complaint_warn'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $before = PlatformSettings::all();
        $new = [
            'signups_open' => $request->boolean('signups_open'),
            'abuse_auto_suspend' => $request->boolean('abuse_auto_suspend'),
            'announcement' => (string) ($d['announcement'] ?? ''),
        ] + collect($d)->only(['abuse_min_volume', 'abuse_bounce_warn', 'abuse_bounce_danger', 'abuse_complaint_warn', 'abuse_complaint_danger'])->map(fn ($v, $k) => $k === 'abuse_min_volume' ? (int) $v : (float) $v)->all();

        $changes = [];
        foreach ($new as $k => $v) {
            if (($before[$k] ?? null) !== $v) {
                $changes[$k] = ['from' => $before[$k] ?? null, 'to' => $v];
                PlatformSettings::set($k, $v);
            }
        }
        AdminAudit::log('settings.update', 'settings', null, null, $d['reason'], $changes);

        return back()->with('status', 'Pengaturan platform disimpan.');
    }

    /** Toggle cepat untuk membuka/mengunci pendaftaran akun baru dari admin panel. */
    public function toggleSignups(Request $request): RedirectResponse
    {
        $this->need('settings.manage');
        $reason = $request->input('reason') ? trim((string) $request->input('reason')) : 'Toggle status pendaftaran oleh superadmin';
        $current = (bool) PlatformSettings::get('signups_open', true);
        $new = ! $current;

        PlatformSettings::set('signups_open', $new);
        AdminAudit::log(
            $new ? 'settings.signups_opened' : 'settings.signups_locked',
            'settings',
            null,
            null,
            $reason,
            ['signups_open' => ['from' => $current, 'to' => $new]]
        );

        $msg = $new
            ? 'Pendaftaran akun baru telah DIBUKA kembali.'
            : 'Pendaftaran akun baru telah DIKUNCI. Pengguna luar tidak bisa mendaftar akun baru.';

        return back()->with('status', $msg);
    }
}
