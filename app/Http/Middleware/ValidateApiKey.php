<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Services\ApiKeyService;
use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class ValidateApiKey
{
    public function __construct(private ApiKeyService $keys) {}

    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        $key = $this->keys->findByPlain((string) $request->bearerToken());
        if (! $key || $key->isRevoked()) {
            return ApiError::make('unauthenticated', 'Invalid or missing API key.', 401);
        }

        $org = Organization::find($key->organization_id);
        if (! $org || $org->isSuspended()) {
            return ApiError::make('forbidden', 'Organization is suspended.', 403);
        }
        if ($scope && ! $key->can($scope)) {
            return ApiError::make('scope_missing', "This API key lacks the '{$scope}' scope.", 403);
        }

        $limit = $request->isMethod('POST') && $request->is('v1/emails') ? 20 : 10;
        $bucket = "api:{$key->id}:".($limit === 20 ? 'send' : 'other');
        if (RateLimiter::tooManyAttempts($bucket, $limit)) {
            $retry = max(1, RateLimiter::availableIn($bucket));

            return ApiError::make('rate_limited', 'Too many requests.', 429, null, [
                'Retry-After' => $retry, 'X-RateLimit-Limit' => $limit, 'X-RateLimit-Remaining' => 0, 'X-RateLimit-Reset' => time() + $retry,
            ]);
        }
        RateLimiter::hit($bucket, 1);

        app()->instance('org.current', $org);
        $request->attributes->set('api_key', $key);
        $request->attributes->set('org', $org);

        // Tulis last_used paling sering sekali per menit per key.
        if (Cache::add("postivo:keyused:{$key->id}", 1, 60)) {
            $key->forceFill(['last_used_at' => now(), 'last_used_ip' => $request->ip()])->saveQuietly();
        }

        $response = $next($request);
        $response->headers->set('X-RateLimit-Limit', (string) $limit);
        $response->headers->set('X-RateLimit-Remaining', (string) RateLimiter::remaining($bucket, $limit));
        $response->headers->set('X-RateLimit-Reset', (string) (time() + 1));

        return $response;
    }
}
