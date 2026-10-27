<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Services\ApiKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiKeyController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => ApiKey::query()->whereNull('revoked_at')->latest()->get()]);
    }

    public function store(Request $request, ApiKeyService $keys): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'environment' => ['sometimes', 'in:live,test'],
            'scopes' => ['sometimes', 'array', 'min:1'],
            'scopes.*' => ['in:'.implode(',', ApiKeyService::ALL_SCOPES)],
        ]);
        [$key, $plain] = $keys->create($request->attributes->get('org'), $data['name'], $data['environment'] ?? 'live', $data['scopes'] ?? ['emails:send']);

        return response()->json($key->toArray() + ['key' => $plain], 201);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $key = ApiKey::query()->findOrFail($id);
        // Jangan biarkan key mencabut dirinya sendiri secara tak sengaja.
        abort_if($request->attributes->get('api_key')?->id === $key->id, 409, 'A key cannot revoke itself.');
        $key->update(['revoked_at' => now()]);

        return response()->json(null, 204);
    }
}
