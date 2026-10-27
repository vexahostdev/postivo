# postivo

Transactional email REST API + SMTP relay — **postivo.my.id** (Laravel 12, Blade, Alpine, Tailwind 4, Vite, Node SMTP ingress).
Rancangan: [PRD](PRD_POSTIVO_FULL.md) · [Backend](BACKEND_SPEC.md) · [Frontend](FRONTEND_SPEC.md) · Docs publik: `/docs`.

## Menjalankan (development)

```bash
composer install
npm install && npm --prefix smtp-engine install
cp .env.development.example .env && php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed     # mencetak API key demo (live + sandbox)
npm run all                    # Laravel :8050 + Vite
```

- Console `http://localhost:8050/console` — login `demo@postivo.test` / `password-demo-123`
- `npm run all:full` = + SMTP engine + queue worker + scheduler (set `QUEUE_CONNECTION=database`/`redis`).
- `php artisan test` — suite lengkap.

## Lingkungan & domain

| | development | staging | production |
|---|---|---|---|
| File contoh | `.env.development.example` | `.env.staging.example` | `.env.production.example` |
| App | `localhost:8050` | `staging.postivo.my.id` | `postivo.my.id` |
| API | `localhost:8050/v1` | `api-staging.postivo.my.id` | `api.postivo.my.id` |
| SMTP | `localhost:2525` | `smtp-staging.postivo.my.id` | `smtp.postivo.my.id` |
| Email sistem | log | Brevo | Brevo |
| Relay pelanggan | log | smtp | smtp |
| DB / queue | SQLite / sync | MySQL / Redis | MySQL / Redis |

Salin file contoh yang sesuai menjadi `.env` di server. **`.env*` (tanpa `.example`) di-gitignore** — jangan commit secret.
DNS pelanggan menunjuk ke `spf.`, `bounce.`, `trk.postivo.my.id` (production).

## Email sistem (Brevo)

Pengirim `noreply@postivo.my.id`, balasan ke `support@postivo.my.id`. Isi `BREVO_SMTP_LOGIN` dan `BREVO_SMTP_KEY`
setelah akun Brevo dibuat (kosong = pengiriman gagal di staging/production). Notifikasi yang aktif: selamat datang,
verifikasi email, reset password, undangan tim, API key baru, domain terverifikasi/bermasalah, kuota 80%/100%,
webhook dinonaktifkan, tagihan dibuat, pembayaran diterima, paket akan berakhir/berakhir. Antrean: `postivo_default`.
Domain `postivo.my.id` perlu SPF/DKIM/DMARC untuk Brevo agar email sistem tidak masuk spam.

## Pembayaran (Xendit via webhook VexaHost)

Checkout di `/console/billing`. Invoice dibuat dengan `external_id` berawalan **`PSV-`**. Callback Xendit diterima
webhook sentral VexaHost, lalu **diteruskan** ke `POST {APP_URL}/api/payment/xendit/callback` dengan header
`X-Internal-Token` (= `INTERNAL_WEBHOOK_SECRET`, sama dengan milik VexaHost). Langkah di VexaHost: tambahkan rute prefix
`PSV-` di `handleXendit()` seperti `BC-`/`WAG-`:

```php
if (str_starts_with($externalId, 'PSV-')) {
    $url = config('services.xendit.postivo_webhook_url'); // env POSTIVO_WEBHOOK_URL=https://postivo.my.id/api/payment/xendit/callback
    // Http::post($url, $payload) dengan header X-Internal-Token + x-callback-token, sama seperti blok BC-
}
```

Env Postivo: `XENDIT_SECRET_KEY`, `XENDIT_WEBHOOK_TOKEN`, `INTERNAL_WEBHOOK_SECRET`. Tanpa `XENDIT_SECRET_KEY`, mode
development menyediakan tombol **simulasikan pembayaran** (`POSTIVO_BILLING_SIMULATOR=true`, otomatis mati di production).
Harga plan ada di `config/postivo.php` (placeholder — sesuaikan).

## SMTP ingress

`smtp-engine/` (Node) menerima SMTP, mengautentikasi via `/internal/smtp/auth`, menyimpan ke spool, lalu memanggil
`/internal/smtp/ingest` (HMAC `POSTIVO_INTERNAL_SECRET`). Produksi: `NODE_ENV=production`, `TLS_KEY_PATH`, `TLS_CERT_PATH`,
`SMTP_PORTS=587:starttls,465:tls,2525:starttls`, `POSTIVO_SPOOL_DIR` sama untuk engine dan Laravel.

## Git

Branch: `main` (production), `staging`, `dev`. Alur: fitur → `dev` → `staging` → `main`.

## Belum dibangun

Integrasi nyata Stalwart/Brevo sebagai relay pelanggan (adapter feedback `RelayEventController::normalize()` dikonfirmasi di spike M0),
Redis tracking-flush daemon, dan CI.
