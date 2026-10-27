<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Api\V1\WebhookController as ApiWebhook;
use App\Http\Controllers\Controller;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\WebhookDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WebhooksController extends Controller
{
    public function index()
    {
        $endpoints = WebhookEndpoint::query()->latest()->get();
        $deliveries = WebhookDelivery::query()->whereIn('endpoint_id', $endpoints->pluck('id'))->latest()->limit(15)->get();

        return view('console.webhooks.index', ['endpoints' => $endpoints, 'deliveries' => $deliveries, 'events' => ApiWebhook::EVENTS]);
    }

    public function store(Request $request, WebhookDispatcher $wh): RedirectResponse
    {
        $data = $request->validate([
            'url' => ['required', 'url:https,http', 'max:2048'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['in:'.implode(',', ApiWebhook::EVENTS)],
        ]);
        if ($wh->resolveSafeIp($data['url']) === null) {
            return back()->withInput()->withErrors(['url' => 'URL harus mengarah ke alamat publik (bukan localhost/jaringan privat).']);
        }
        $secret = 'whsec_'.Str::random(40);
        WebhookEndpoint::create(['url' => $data['url'], 'events' => $data['events'], 'secret' => $secret]);

        return back()->with('status', 'Webhook dibuat.')->with('reveal', $secret);
    }

    public function toggle(string $id): RedirectResponse
    {
        $e = WebhookEndpoint::query()->findOrFail($id);
        $e->update($e->is_active ? ['is_active' => false] : ['is_active' => true, 'consecutive_failures' => 0, 'disabled_at' => null]);

        return back()->with('status', $e->is_active ? 'Webhook diaktifkan.' : 'Webhook dinonaktifkan.');
    }

    public function destroy(string $id): RedirectResponse
    {
        WebhookEndpoint::query()->findOrFail($id)->delete();

        return back()->with('status', 'Webhook dihapus.');
    }
}
