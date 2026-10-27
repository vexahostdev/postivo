<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Suppression;
use App\Services\SuppressionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SuppressionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $page = Suppression::query()->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate(min(100, (int) $request->query('limit', 25)));

        return response()->json(['data' => $page->items(), 'next_cursor' => $page->nextCursor()?->encode()]);
    }

    public function store(Request $request, SuppressionService $svc): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc'], 'note' => ['nullable', 'string', 'max:255']]);

        return response()->json($svc->add($request->attributes->get('org')->id, $data['email'], 'MANUAL', null, $data['note'] ?? null), 201);
    }

    public function destroy(string $id): JsonResponse
    {
        Suppression::query()->findOrFail($id)->delete();

        return response()->json(null, 204);
    }
}
