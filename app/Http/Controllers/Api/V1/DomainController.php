<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\Email;
use App\Services\DnsAuditorService;
use App\Services\DomainService;
use App\Support\ApiError;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DomainController extends Controller
{
    public static function nameRules(): array
    {
        // 183 = 191 - 8 agar host turunan "bounces.<domain>" tetap muat di kolom.
        return ['required', 'string', 'max:183', 'regex:/^(?=.{4,183}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i'];
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => Domain::query()->with('records')->latest()->get()->map(fn ($d) => Present::domain($d))]);
    }

    public function store(Request $request, DomainService $domains): JsonResponse
    {
        $data = $request->validate(['domain' => self::nameRules()]);
        $name = strtolower($data['domain']);
        if (Domain::withTrashed()->withoutGlobalScopes()->where('domain_name', $name)->exists()) {
            return ApiError::make('conflict', 'Domain is already registered.', 409);
        }

        return response()->json(Present::domain($domains->create($request->attributes->get('org'), $name)), 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(Present::domain(Domain::query()->findOrFail($id)));
    }

    public function verify(string $id, DnsAuditorService $auditor): JsonResponse
    {
        return response()->json(Present::domain($auditor->audit(Domain::query()->with('records')->findOrFail($id))));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'track_opens_default' => ['sometimes', 'boolean'],
            'track_clicks_default' => ['sometimes', 'boolean'],
            'status' => [Rule::prohibitedIf(true)],
        ]);
        $domain = Domain::query()->findOrFail($id);
        $domain->update($data);

        return response()->json(Present::domain($domain));
    }

    public function destroy(string $id): JsonResponse
    {
        $domain = Domain::query()->findOrFail($id);
        $pending = Email::query()->where('domain_id', $domain->id)->whereNotNull('scheduled_at')->where('scheduled_at', '>', now())->whereNull('canceled_at')->exists();
        if ($pending) {
            return ApiError::make('conflict', 'Domain has scheduled emails. Cancel them first.', 409);
        }
        $domain->delete();

        return response()->json(null, 204);
    }
}
