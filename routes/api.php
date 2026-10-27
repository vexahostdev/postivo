<?php

use App\Http\Controllers\Api\V1\ApiKeyController;
use App\Http\Controllers\Api\V1\DomainController;
use App\Http\Controllers\Api\V1\EmailController;
use App\Http\Controllers\Api\V1\SuppressionController;
use App\Http\Controllers\Api\V1\WebhookController;
use Illuminate\Support\Facades\Route;

// Prefix /v1 dipasang di bootstrap/app.php.
Route::get('/ping', fn () => response()->json(['ok' => true, 'service' => 'postivo']));

Route::middleware('api.key:emails:send')->group(function () {
    Route::post('/emails', [EmailController::class, 'store']);
    Route::post('/emails/batch', [EmailController::class, 'batch']);
});

Route::middleware('api.key:emails:read')->group(function () {
    Route::get('/emails', [EmailController::class, 'index']);
    Route::get('/emails/{id}', [EmailController::class, 'show']);
    Route::get('/emails/{id}/events', [EmailController::class, 'events']);
});
Route::post('/emails/{id}/cancel', [EmailController::class, 'cancel'])->middleware('api.key:emails:send');

Route::middleware('api.key:domains:manage')->group(function () {
    Route::get('/domains', [DomainController::class, 'index']);
    Route::post('/domains', [DomainController::class, 'store']);
    Route::get('/domains/{id}', [DomainController::class, 'show']);
    Route::post('/domains/{id}/verify', [DomainController::class, 'verify']);
    Route::patch('/domains/{id}', [DomainController::class, 'update']);
    Route::delete('/domains/{id}', [DomainController::class, 'destroy']);
});

Route::middleware('api.key:suppressions:manage')->group(function () {
    Route::get('/suppressions', [SuppressionController::class, 'index']);
    Route::post('/suppressions', [SuppressionController::class, 'store']);
    Route::delete('/suppressions/{id}', [SuppressionController::class, 'destroy']);
});

Route::middleware('api.key:webhooks:manage')->group(function () {
    Route::get('/webhooks', [WebhookController::class, 'index']);
    Route::post('/webhooks', [WebhookController::class, 'store']);
    Route::patch('/webhooks/{id}', [WebhookController::class, 'update']);
    Route::delete('/webhooks/{id}', [WebhookController::class, 'destroy']);
});

Route::middleware('api.key:api_keys:manage')->group(function () {
    Route::get('/api-keys', [ApiKeyController::class, 'index']);
    Route::post('/api-keys', [ApiKeyController::class, 'store']);
    Route::delete('/api-keys/{id}', [ApiKeyController::class, 'destroy']);
});
