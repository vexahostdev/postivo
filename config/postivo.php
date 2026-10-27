<?php

return [
    // Host publik per lingkungan (diisi lewat env; default = production).
    'hosts' => [
        'app' => env('POSTIVO_APP_HOST', 'postivo.my.id'),
        'api' => env('POSTIVO_API_URL', 'https://api.postivo.my.id'),
        'docs' => env('POSTIVO_DOCS_URL'),
    ],

    // Panel admin platform (ADMIN_PANEL_SPEC.md).
    'admin' => [
        'require_2fa' => (bool) env('ADMIN_REQUIRE_2FA', env('APP_ENV') === 'production'),
        'ip_allowlist' => env('ADMIN_IP_ALLOWLIST', ''),
        'session_minutes' => (int) env('ADMIN_SESSION_MINUTES', 120),
        // Akun superadmin awal, dibuat/diperbarui oleh `php artisan postivo:admin:create`.
        // Nilai asli hanya di file .env* (gitignored) — jangan taruh di kode atau file *.example.
        'bootstrap' => [
            'name' => env('ADMIN_NAME', 'Administrator'),
            'email' => env('ADMIN_EMAIL'),
            'password' => env('ADMIN_PASSWORD'),
        ],
    ],

    'support_email' => env('POSTIVO_SUPPORT_EMAIL', 'support@postivo.my.id'),

    // Harga PLACEHOLDER (keputusan terbuka PRD §14.2) — ubah di sini, checkout & landing otomatis mengikuti.
    // daily_limit null = tanpa batas harian. Harga dalam rupiah per bulan.
    'plans' => [
        'free' => ['name' => 'Free', 'price' => 0, 'monthly_quota' => 3000, 'daily_limit' => 100,
            'features' => ['3.000 email / bulan', 'Batas 100 email / hari', '1 domain pengirim', 'REST API + SMTP', 'Webhook & suppression otomatis']],
        'starter' => ['name' => 'Starter', 'price' => 99000, 'monthly_quota' => 25000, 'daily_limit' => null,
            'features' => ['25.000 email / bulan', 'Tanpa batas harian', 'Domain tanpa batas', 'Open & click tracking', 'Dukungan email']],
        'growth' => ['name' => 'Growth', 'price' => 299000, 'monthly_quota' => 100000, 'daily_limit' => null,
            'features' => ['100.000 email / bulan', 'Tanpa batas harian', 'Domain tanpa batas', 'Open & click tracking', 'Dukungan prioritas']],
        'scale' => ['name' => 'Scale', 'price' => 899000, 'monthly_quota' => 500000, 'daily_limit' => null,
            'features' => ['500.000 email / bulan', 'Tanpa batas harian', 'Domain tanpa batas', 'Open & click tracking', 'Dukungan prioritas + SLA respons']],
    ],

    // Xendit: callback diterima lewat webhook sentral VexaHost (forward berdasarkan prefix external_id).
    'billing' => [
        'external_prefix' => 'PSV-',
        'invoice_duration' => 86400,
        'xendit_secret_key' => env('XENDIT_SECRET_KEY'),
        'xendit_webhook_token' => env('XENDIT_WEBHOOK_TOKEN'),
        // Dikirim VexaHost di header X-Internal-Token saat meneruskan callback; WAJIB diisi untuk menerima forward.
        'internal_secret' => env('INTERNAL_WEBHOOK_SECRET'),
        // Hanya lokal/dev tanpa key Xendit: tombol "simulasikan pembayaran" di halaman order.
        'allow_simulator' => (bool) env('POSTIVO_BILLING_SIMULATOR', false),
    ],

    // Host infrastruktur Postivo yang dirujuk record DNS pelanggan.
    'dns' => [
        'spf_include' => env('POSTIVO_SPF_INCLUDE', 'spf.postivo.my.id'),
        'bounce_target' => env('POSTIVO_BOUNCE_TARGET', 'bounce.postivo.my.id'),
        'tracking_target' => env('POSTIVO_TRACKING_TARGET', 'trk.postivo.my.id'),
        'dmarc_rua' => env('POSTIVO_DMARC_RUA', 'dmarc@postivo.my.id'),
        'doh_primary' => 'https://cloudflare-dns.com/dns-query',
        'doh_fallback' => 'https://dns.google/resolve',
        'dkim_selector' => 'postivo',
        // Lokal tanpa DNS publik: set POSTIVO_DNS_ASSUME_OK=true agar domain bisa langsung ACTIVE.
        'assume_ok' => (bool) env('POSTIVO_DNS_ASSUME_OK', false),
    ],

    // Relay keluar. driver: log (simulasi, tulis .eml ke storage) | smtp (Stalwart + fallback Brevo).
    'relay' => [
        'driver' => env('POSTIVO_RELAY', 'log'),
        'stalwart_dsn' => env('POSTIVO_STALWART_DSN'),
        'brevo_dsn' => env('POSTIVO_BREVO_DSN'),
        'breaker_failures' => 5,
        'breaker_window' => 60,
        'breaker_open_seconds' => 120,
    ],

    // Backoff retry (detik): 1m, 5m, 15m, 1h, 3h, 6h, 12h.
    'retry_backoff' => [60, 300, 900, 3600, 10800, 21600, 43200],

    'limits' => [
        'max_recipients' => 50,
        'max_batch' => 100,
        'max_payload_bytes' => 25 * 1024 * 1024,
    ],

    // Spool bersama dengan smtp-engine (engine menulis, Laravel membaca lalu menghapus).
    'smtp' => [
        'host' => env('POSTIVO_SMTP_HOST', 'smtp.postivo.my.id'),
        'spool' => env('POSTIVO_SPOOL_DIR', storage_path('app/spool')),
    ],

    'tracking' => [
        'url' => env('POSTIVO_TRACKING_URL'),
    ],

    // Secret HMAC untuk endpoint /internal/* (feedback relay & SMTP ingest).
    'internal_secret' => env('POSTIVO_INTERNAL_SECRET'),

    'webhook' => [
        'retry_backoff' => [30, 120, 600, 3600, 21600, 86400],
        'disable_after' => 50,
        'timeout' => 10,
        // true hanya di mesin dev untuk menguji webhook ke localhost.
        'allow_local' => (bool) env('POSTIVO_WEBHOOK_ALLOW_LOCAL', false),
    ],

    'retention_days' => [
        'body' => 30,
        'events' => 90,
    ],
];
