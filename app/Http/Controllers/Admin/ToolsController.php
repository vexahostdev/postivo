<?php

namespace App\Http\Controllers\Admin;

use App\Services\AdminAudit;
use App\Services\PlatformDeliverability;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Alat diagnostik pengiriman platform: uji koneksi relay, email uji, cek DNS. */
class ToolsController extends AdminController
{
    private const RELAYS = ['STALWART', 'BREVO', 'SYSTEM'];

    public function index(PlatformDeliverability $tools)
    {
        return view('admin.tools.index', [
            'relays' => $tools->relays(),
            'driver' => config('postivo.relay.driver'),
            'result' => session('tool_result'),
            'dns' => session('dns_result'),
        ]);
    }

    public function connect(Request $request, PlatformDeliverability $tools): RedirectResponse
    {
        $this->need('system.manage');
        $d = $request->validate(['relay' => ['required', 'in:'.implode(',', self::RELAYS)]]);
        $res = $tools->testConnection($d['relay']);
        AdminAudit::log('tools.relay_connect', 'relay', $d['relay'], null, null, ['ok' => $res['ok']]);

        return back()->with('tool_result', ['kind' => 'connect', 'relay' => $d['relay']] + $res);
    }

    public function send(Request $request, PlatformDeliverability $tools): RedirectResponse
    {
        $this->need('system.manage');
        $d = $request->validate([
            'relay' => ['required', 'in:'.implode(',', self::RELAYS)],
            'to' => ['required', 'email:rfc', 'max:190'],
        ]);
        $res = $tools->sendTest($d['relay'], $d['to'], $request->user()->email);
        AdminAudit::log('tools.relay_send', 'relay', $d['relay'], null, null, ['to' => $d['to'], 'ok' => $res['ok']]);

        return back()->with('tool_result', ['kind' => 'send', 'relay' => $d['relay'], 'to' => $d['to']] + $res);
    }

    public function dns(PlatformDeliverability $tools): RedirectResponse
    {
        $this->need('system.manage');
        AdminAudit::log('tools.dns_check', 'platform');

        return back()->with('dns_result', ['at' => now()->toDateTimeString(), 'rows' => $tools->dnsChecks()]);
    }
}
