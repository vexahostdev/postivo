<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Console\ApiKeysController;
use App\Http\Controllers\Console\DashboardController;
use App\Http\Controllers\Console\DomainsController;
use App\Http\Controllers\Console\EmailsController;
use App\Http\Controllers\Console\SettingsController;
use App\Http\Controllers\Console\SuppressionsController;
use App\Http\Controllers\Console\WebhooksController;
use App\Http\Controllers\Internal\RelayEventController;
use App\Http\Controllers\TrackingController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'marketing.landing')->name('landing');

// --- Auth -----------------------------------------------------------------
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1')->name('login.attempt');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1')->name('register.attempt');
});
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// --- Console --------------------------------------------------------------
Route::middleware(['auth', 'org.set'])->prefix('console')->name('console.')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/chart', [DashboardController::class, 'chart'])->name('chart');

    Route::get('/emails', [EmailsController::class, 'index'])->name('emails.index');
    Route::get('/emails/{id}', [EmailsController::class, 'show'])->name('emails.show');

    Route::get('/domains', [DomainsController::class, 'index'])->name('domains.index');
    Route::get('/domains/{id}', [DomainsController::class, 'show'])->name('domains.show');
    Route::get('/suppressions', [SuppressionsController::class, 'index'])->name('suppressions.index');
    Route::get('/webhooks', [WebhooksController::class, 'index'])->name('webhooks.index');
    Route::get('/api-keys', [ApiKeysController::class, 'index'])->name('api-keys.index');
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
    Route::get('/billing', [\App\Http\Controllers\Console\BillingController::class, 'index'])->name('billing.index');
    Route::get('/billing/orders/{id}', [\App\Http\Controllers\Console\BillingController::class, 'show'])->name('billing.orders.show');

    // Tulis: developer ke atas.
    Route::middleware('role:developer')->group(function () {
        Route::post('/domains', [DomainsController::class, 'store'])->name('domains.store');
        Route::post('/domains/{id}/verify', [DomainsController::class, 'verify'])->name('domains.verify');
        Route::patch('/domains/{id}', [DomainsController::class, 'update'])->name('domains.update');
        Route::post('/suppressions', [SuppressionsController::class, 'store'])->name('suppressions.store');
        Route::delete('/suppressions/{id}', [SuppressionsController::class, 'destroy'])->name('suppressions.destroy');
        Route::post('/webhooks', [WebhooksController::class, 'store'])->name('webhooks.store');
        Route::post('/webhooks/{id}/toggle', [WebhooksController::class, 'toggle'])->name('webhooks.toggle');
        Route::post('/api-keys', [ApiKeysController::class, 'store'])->name('api-keys.store');
    });

    // Destruktif / administratif: admin ke atas.
    Route::middleware('role:admin')->group(function () {
        Route::delete('/domains/{id}', [DomainsController::class, 'destroy'])->name('domains.destroy');
        Route::delete('/webhooks/{id}', [WebhooksController::class, 'destroy'])->name('webhooks.destroy');
        Route::delete('/api-keys/{id}', [ApiKeysController::class, 'destroy'])->name('api-keys.destroy');
        Route::patch('/settings', [SettingsController::class, 'update'])->name('settings.update');
        Route::post('/settings/invitations', [SettingsController::class, 'invite'])->name('settings.invite');
        Route::get('/billing/checkout/{plan}', [\App\Http\Controllers\Console\BillingController::class, 'checkout'])->name('billing.checkout');
        Route::post('/billing/checkout/{plan}', [\App\Http\Controllers\Console\BillingController::class, 'store'])->name('billing.store');
        Route::post('/billing/orders/{id}/pay', [\App\Http\Controllers\Console\BillingController::class, 'pay'])->name('billing.orders.pay');
        Route::post('/billing/orders/{id}/simulate', [\App\Http\Controllers\Console\BillingController::class, 'simulate'])->name('billing.orders.simulate');
    });
});

// --- Tracking publik --------------------------------------------------------
Route::get('/o/{token}.gif', [TrackingController::class, 'open'])->where('token', '[A-Za-z0-9]{22}');
Route::get('/c/{token}', [TrackingController::class, 'click'])->where('token', '[A-Za-z0-9]{22}');
Route::match(['get', 'post'], '/u/{token}', [TrackingController::class, 'unsubscribe'])->where('token', '[A-Za-z0-9]{22}');

// --- Internal (HMAC) --------------------------------------------------------
Route::post('/internal/relay/events/{relay}', RelayEventController::class)->middleware('internal');
Route::post('/internal/smtp/auth', [\App\Http\Controllers\Internal\SmtpController::class, 'auth'])->middleware('internal');
Route::post('/internal/smtp/ingest', [\App\Http\Controllers\Internal\SmtpController::class, 'ingest'])->middleware('internal');

// --- Email verification, reset password, undangan ------------------------------------
Route::get('/verify-email/{id}/{hash}', [AuthController::class, 'verifyEmail'])->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
Route::post('/verify-email/resend', [AuthController::class, 'resendVerification'])->middleware(['auth', 'throttle:3,1'])->name('verification.send');
Route::middleware('guest')->group(function () {
    Route::get('/forgot-password', [AuthController::class, 'showForgot'])->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'sendReset'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [AuthController::class, 'showReset'])->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'reset'])->middleware('throttle:5,1')->name('password.update');
});
Route::get('/invitations/{token}', [\App\Http\Controllers\InvitationController::class, 'show'])->name('invitation.accept');
Route::post('/invitations/{token}', [\App\Http\Controllers\InvitationController::class, 'accept'])->middleware('auth')->name('invitation.accept.confirm');

// --- Webhook pembayaran Xendit (diteruskan webhook sentral VexaHost) -------------------
Route::match(['get', 'post'], '/api/payment/xendit/callback', \App\Http\Controllers\Webhooks\XenditWebhookController::class);

// --- Dokumentasi publik ------------------------------------------------------------------
Route::view('/docs', 'docs.index')->name('docs');
