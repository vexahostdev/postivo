<?php

use App\Http\Middleware\EnsureValidImpersonation;
use App\Http\Middleware\RequireRole;
use App\Http\Middleware\SetCurrentOrganization;
use App\Http\Middleware\SetRequestId;
use App\Http\Middleware\ValidateApiKey;
use App\Http\Middleware\VerifyInternalSecret;
use App\Support\ApiError;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use App\Exceptions\IngestException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'v1',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'api.key' => ValidateApiKey::class,
            'org.set' => SetCurrentOrganization::class,
            'role' => RequireRole::class,
            'internal' => VerifyInternalSecret::class,
            'staff' => \App\Http\Middleware\EnsureStaff::class,
        ]);
        $middleware->api(prepend: [SetRequestId::class]);
        $middleware->authenticateSessions();
        $middleware->web(append: [EnsureValidImpersonation::class, \App\Http\Middleware\SecurityHeaders::class]);
        $middleware->appendToPriorityList(\Illuminate\Session\Middleware\StartSession::class, EnsureValidImpersonation::class);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('console.dashboard'));
        $middleware->trustProxies(at: '*');
        $middleware->validateCsrfTokens(except: ['u/*', 'internal/*', 'api/payment/*']);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $isApi = fn (Request $r) => $r->is('v1/*') || $r->is('v1');
        // Penolakan bisnis yang diharapkan (domain belum terverifikasi, kuota, dst) bukan error server.
        $exceptions->dontReport(IngestException::class);
        $exceptions->shouldRenderJsonWhen(fn (Request $r, Throwable $e) => $isApi($r) || $r->expectsJson());

        // Sesi/CSRF kedaluwarsa di halaman web (mis. setelah login lama atau reset DB dev): arahkan ramah, bukan layar "419".
        $exceptions->render(function (\Illuminate\Session\TokenMismatchException $e, Request $r) use ($isApi) {
            if (! $isApi($r) && ! $r->expectsJson()) {
                return redirect()->guest(route('login'))->with('error', 'Sesi Anda sudah kedaluwarsa. Silakan masuk lagi.');
            }
        });
        $exceptions->render(function (IngestException $e, Request $r) use ($isApi) {
            if ($isApi($r)) {
                $h = $e->httpStatus === 429 ? ['Retry-After' => 60] : [];

                return ApiError::make($e->errorCode, $e->getMessage(), $e->httpStatus, $e->details, $h);
            }
        });
        $exceptions->render(function (ValidationException $e, Request $r) use ($isApi) {
            if ($isApi($r)) {
                return ApiError::make('validation_failed', 'The given data was invalid.', 422, $e->errors());
            }
        });
        $exceptions->render(function (AuthenticationException $e, Request $r) use ($isApi) {
            if ($isApi($r)) {
                return ApiError::make('unauthenticated', 'Invalid or missing API key.', 401);
            }
        });
        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $r) use ($isApi) {
            if ($isApi($r)) {
                return ApiError::make('not_found', 'Resource not found.', 404);
            }
        });
        $exceptions->render(function (HttpExceptionInterface $e, Request $r) use ($isApi) {
            if ($isApi($r)) {
                $code = match ($e->getStatusCode()) {
                    400 => 'bad_request', 403 => 'forbidden', 409 => 'conflict', 413 => 'payload_too_large', 429 => 'rate_limited', default => 'internal_error',
                };

                return ApiError::make($code, $e->getMessage() ?: 'Error', $e->getStatusCode());
            }
        });
    })->create();
