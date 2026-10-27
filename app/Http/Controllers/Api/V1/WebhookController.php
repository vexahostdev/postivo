<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\WebhookEndpoint;
use App\Services\WebhookDispatcher;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WebhookController extends Controller
{
    public const EVENTS = ['*', 'email.queued', 'email.sent', 'email.delivered', 'email.deferred', 'email.bounced', 'email.complained', 'email.failed', 'email.suppressed', 'email.opened', 'email.clicked', 'email.unsubscribed'];

    public function index(): JsonResponse
    {
        return response()->json(['data' => WebhookEndpoint::query()->latest()->get()]);
    }

    public function store(Request $request, WebhookDispatcher $wh): JsonResponse
    {
        $data = $request->validate([
            'url' => ['required', 'url:https,http', 'max:2048'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['in:'.implode(',', self::EVENTS)],
        ]);
        if ($wh->resolveSafeIp($data['url']) === null) {
            return ApiError::make('validation_failed', 'URL must resolve to a publicly routable address.', 422, ['url' => ['Target is not publicly routable.']]);
        }
        $secret = 'whsec_'.Str::random(40);
        $endpoint = WebhookEndpoint::create(['url' => $data['url'], 'events' => $data['events'], 'secret' => $secret]);

        // Secret hanya ditampilkan sekali.
        return response()->json($endpoint->toArray() + ['secret' => $secret], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['events' => ['sometimes', 'array', 'min:1'], 'events.*' => ['in:'.implode(',', self::EVENTS)], 'is_active' => ['sometimes', 'boolean']]);
        $endpoint = WebhookEndpoint::query()->findOrFail($id);
        if (($data['is_active'] ?? false) === true) {
            $data += ['consecutive_failures' => 0, 'disabled_at' => null];
        }
        $endpoint->update($data);

        return response()->json($endpoint);
    }

    public function destroy(string $id): JsonResponse
    {
        WebhookEndpoint::query()->findOrFail($id)->delete();

        return response()->json(null, 204);
    }
}
