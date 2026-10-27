# POSTIVO — Backend Technical Specification

**Versi:** 3.0.0 (Development-Ready) · selaras dengan `PRD_POSTIVO_FULL.md` v3.0.0
**Runtime:** PHP 8.3 (min 8.2), Laravel 12, MySQL 8.0 (InnoDB, strict mode, `utf8mb4_0900_ai_ci`), Redis 7.2, Node.js 20 LTS (hanya `smtp-engine`)
**Queue:** Laravel Queue (driver Redis) + **Laravel Horizon**, dijalankan Supervisor
**Relay:** Stalwart Mail Server (primary) + Brevo SMTP relay (fallback)
**Auth:** Console = session Laravel bawaan. API/SMTP = API key kustom (hash SHA-256). **Sanctum tidak dipakai.**

---

## DAFTAR ISI
1. [Arsitektur & Pembagian Service](#1-arsitektur--pembagian-service)
2. [Struktur Proyek](#2-struktur-proyek)
3. [Database Schema (DDL kanonik)](#3-database-schema-ddl-kanonik)
4. [Autentikasi, Otorisasi, Rate Limit & Kuota](#4-autentikasi-otorisasi-rate-limit--kuota)
5. [Ingest & Dispatch Pipeline (Queue/Job)](#5-ingest--dispatch-pipeline)
6. [Tracking (Link & Pixel)](#6-tracking-link--pixel)
7. [DNS Auditor & DKIM](#7-dns-auditor--dkim)
8. [Outbound Relay & Failover](#8-outbound-relay--failover)
9. [Feedback, Bounce & Suppression](#9-feedback-bounce--suppression)
10. [Webhook Dispatcher](#10-webhook-dispatcher)
11. [SMTP Ingress (`smtp-engine`)](#11-smtp-ingress-smtp-engine)
12. [Konfigurasi, Infra, Scheduler & Operasi](#12-konfigurasi-infra-scheduler--operasi)
13. [Strategi Test](#13-strategi-test)
14. [Aturan untuk AI Agent / Developer](#14-aturan-untuk-ai-agent--developer)

---

## 1. ARSITEKTUR & PEMBAGIAN SERVICE

| Service | Tugas | Port (internal) |
|---|---|---|
| `app` (php-fpm + nginx) | REST API, console, tracking endpoint, endpoint internal | 8045 |
| `horizon` | Menjalankan worker queue (`outbound`, `webhooks`, `tracking`, `default`, `low`) | — |
| `scheduler` | `php artisan schedule:work` | — |
| `tracking-flush` | Daemon: flush event tracking Redis → MySQL | — |
| `smtp` (Node) | SMTP ingress 587/465/2525; auth API key; spool pesan; panggil endpoint internal Laravel | 587/465/2525 |
| `mysql`, `redis` | Data & antrean | 3306 / 6379 |
| Stalwart (host terpisah) | MTA keluar, IP reputasi | 587 (submission, hanya dari IP app) |

Pembagian tanggung jawab: **Laravel = sumber kebenaran** (validasi, kuota, suppression, state). **smtp-engine** hanya TLS + autentikasi + spool + terjemahan respons. **Relay** hanya mengirim dan melaporkan event.

Alur ingest dari dua pintu ke satu `EmailIngestService` (satu perilaku, dua adapter):

```
POST /v1/emails ──► SendEmailRequest ──┐
                                       ├─► EmailIngestService::accept(IngestData) ─► DB tx ─► DispatchOutboundEmailJob (per recipient)
SMTP DATA ─► smtp-engine ─► POST /internal/smtp/ingest ─► MimeMessageParser ─┘
```

---

## 2. STRUKTUR PROYEK

```
postivo/
├── app/
│   ├── Actions/                   # use-case kecil (CreateApiKey, AddDomain, ...)
│   ├── Console/Commands/          # postivo:tracking-flush, postivo:dns-audit, postivo:prune, postivo:sync-api-keys, postivo:usage-sync
│   ├── DTO/                       # IngestData, RecipientData, AttachmentData, RelayEvent
│   ├── Enums/                     # DomainStatus, DispatchStatus, EventType, BounceType, ApiScope, MemberRole, SuppressionReason
│   ├── Exceptions/                # TransientRelayException, PermanentRelayException, QuotaExceededException, ...
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Api/V1/            # EmailController, DomainController, SuppressionController, WebhookController, ApiKeyController
│   │   │   ├── Console/           # Dashboard, Domain, Email, Suppression, Webhook, Settings, Auth*
│   │   │   ├── Internal/          # SmtpIngestController, RelayEventController
│   │   │   ├── TrackingController.php
│   │   │   └── UnsubscribeController.php
│   │   ├── Middleware/            # AuthenticateApiKey, RequireScope, VerifyInternalSignature, SecurityHeaders, SetRequestId
│   │   ├── Requests/              # SendEmailRequest, BatchEmailRequest, StoreDomainRequest, ...
│   │   └── Resources/             # EmailResource, DomainResource, ... (bentuk JSON API)
│   ├── Jobs/                      # DispatchOutboundEmailJob, ProcessRelayEventJob, SendWebhookEventJob, PurgeExpiredDataJob
│   ├── Models/                    # lihat §3 (semua memakai HasUuids kecuali EmailEvent/TrackingLink/WebhookDelivery)
│   ├── Policies/
│   ├── Services/
│   │   ├── EmailIngestService.php
│   │   ├── MimeMessageParser.php          # SMTP raw → IngestData (zbateson/mail-mime-parser)
│   │   ├── MimeBuilder.php                # IngestData+dispatch → Symfony Email
│   │   ├── LinkTrackingService.php
│   │   ├── DkimService.php                # keypair + signing (Symfony DkimSigner)
│   │   ├── DnsAuditorService.php
│   │   ├── OutboundRelayService.php       # Stalwart + Brevo + circuit breaker
│   │   ├── RelayEventNormalizer.php       # adapter event Stalwart/Brevo → RelayEvent
│   │   ├── BounceClassifier.php
│   │   ├── DispatchStateMachine.php
│   │   ├── SuppressionService.php
│   │   ├── QuotaService.php
│   │   ├── WebhookSigner.php / SafeUrlGuard.php
│   │   └── ApiKeyService.php              # generate, hash, sync ke Redis
│   └── Support/                           # Ulid/Token helper, EmailAddress normalizer
├── config/postivo.php                     # semua setting domain (relay, limits, retention)
├── database/{migrations,factories,seeders}
├── docs/openapi.yaml                      # kontrak API (wajib sinkron dengan PRD §8)
├── routes/{api.php,web.php,internal.php,console.php}
├── smtp-engine/{package.json,server.js,Dockerfile}
├── docker/{nginx,php,supervisor}/ · docker-compose.yml · docker-compose.dev.yml
└── tests/{Unit,Feature,Integration}
```

Konvensi: controller tipis → Action/Service; semua query tenant lewat trait `BelongsToOrganization` (global scope `organization_id`); enum PHP backed-string; tidak ada logika bisnis di Blade.

---

## 3. DATABASE SCHEMA (DDL KANONIK)

Migrasi Laravel **harus** menghasilkan skema ini (nama, tipe, index). ID `CHAR(36)` = UUID v7 (`HasUuids` bawaan Laravel 12). `utf8mb4`, InnoDB.

```sql
CREATE TABLE users (
  id CHAR(36) PRIMARY KEY, name VARCHAR(120) NOT NULL,
  email VARCHAR(191) NOT NULL UNIQUE, email_verified_at TIMESTAMP NULL,
  password VARCHAR(255) NOT NULL, remember_token VARCHAR(100) NULL,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL
);

CREATE TABLE organizations (
  id CHAR(36) PRIMARY KEY, name VARCHAR(120) NOT NULL, slug VARCHAR(80) NOT NULL UNIQUE,
  plan VARCHAR(30) NOT NULL DEFAULT 'free',
  monthly_quota INT UNSIGNED NOT NULL DEFAULT 3000,
  daily_limit INT UNSIGNED NULL DEFAULT 100,
  status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',          -- ACTIVE | SUSPENDED
  suspended_reason VARCHAR(255) NULL,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL
);

CREATE TABLE organization_members (
  id CHAR(36) PRIMARY KEY, organization_id CHAR(36) NOT NULL, user_id CHAR(36) NOT NULL,
  role VARCHAR(20) NOT NULL,                              -- owner|admin|developer|viewer
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  UNIQUE (organization_id, user_id),
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE organization_invitations (
  id CHAR(36) PRIMARY KEY, organization_id CHAR(36) NOT NULL, email VARCHAR(191) NOT NULL,
  role VARCHAR(20) NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at TIMESTAMP NOT NULL, accepted_at TIMESTAMP NULL, created_at TIMESTAMP NULL,
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
);

CREATE TABLE api_keys (
  id CHAR(36) PRIMARY KEY, organization_id CHAR(36) NOT NULL, name VARCHAR(100) NOT NULL,
  prefix VARCHAR(16) NOT NULL,                            -- 'psv_live_AbCd' untuk tampilan
  key_hash CHAR(64) NOT NULL UNIQUE,                      -- sha256(key)
  environment VARCHAR(8) NOT NULL DEFAULT 'live',         -- live|test
  scopes JSON NOT NULL, domain_ids JSON NULL,
  last_used_at TIMESTAMP NULL, last_used_ip VARBINARY(16) NULL,
  created_by CHAR(36) NULL, revoked_at TIMESTAMP NULL,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  INDEX (organization_id, revoked_at),
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
);

CREATE TABLE domains (
  id CHAR(36) PRIMARY KEY, organization_id CHAR(36) NOT NULL,
  domain_name VARCHAR(191) NOT NULL UNIQUE,               -- lowercase, punycode
  status VARCHAR(20) NOT NULL DEFAULT 'PENDING',          -- PENDING|ACTIVE|FAILED|SUSPENDED
  dkim_selector VARCHAR(50) NOT NULL DEFAULT 'postivo',
  dkim_private_key TEXT NOT NULL,                         -- Crypt::encryptString
  dkim_public_key TEXT NOT NULL,                          -- base64 DER (tanpa header PEM)
  return_path_host VARCHAR(191) NOT NULL,                 -- bounces.<domain>
  tracking_host VARCHAR(191) NULL,                        -- trk.<domain> jika CNAME lulus, else null → trk.postivo.my.id
  track_opens_default TINYINT(1) NOT NULL DEFAULT 0,
  track_clicks_default TINYINT(1) NOT NULL DEFAULT 0,
  verified_at TIMESTAMP NULL, last_audit_at TIMESTAMP NULL, audit_started_at TIMESTAMP NULL,
  deleted_at TIMESTAMP NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  INDEX (organization_id), INDEX (status),
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
);

CREATE TABLE dns_records (
  id CHAR(36) PRIMARY KEY, domain_id CHAR(36) NOT NULL,
  purpose VARCHAR(20) NOT NULL,                           -- dkim|spf|return_path|dmarc|tracking
  type VARCHAR(10) NOT NULL, name VARCHAR(255) NOT NULL, value TEXT NOT NULL,
  is_required TINYINT(1) NOT NULL DEFAULT 1, is_verified TINYINT(1) NOT NULL DEFAULT 0,
  last_checked_at TIMESTAMP NULL, last_seen_value TEXT NULL,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  UNIQUE (domain_id, purpose),
  FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE
);

CREATE TABLE emails (
  id CHAR(36) PRIMARY KEY, organization_id CHAR(36) NOT NULL, domain_id CHAR(36) NOT NULL,
  api_key_id CHAR(36) NULL, source VARCHAR(8) NOT NULL,   -- API|SMTP
  from_address VARCHAR(191) NOT NULL, from_name VARCHAR(100) NULL, reply_to VARCHAR(191) NULL,
  subject VARCHAR(255) NOT NULL,
  html_body MEDIUMTEXT NULL, text_body MEDIUMTEXT NULL,   -- dihapus oleh prune (retensi)
  headers JSON NULL, tags JSON NULL, metadata JSON NULL,
  track_opens TINYINT(1) NOT NULL DEFAULT 0, track_clicks TINYINT(1) NOT NULL DEFAULT 0,
  idempotency_key VARCHAR(191) NULL, request_hash CHAR(64) NULL,
  scheduled_at TIMESTAMP NULL, canceled_at TIMESTAMP NULL,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  UNIQUE (organization_id, idempotency_key),
  INDEX (organization_id, created_at), INDEX (domain_id, created_at),
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  FOREIGN KEY (domain_id) REFERENCES domains(id)
);

CREATE TABLE email_attachments (
  id CHAR(36) PRIMARY KEY, email_id CHAR(36) NOT NULL, filename VARCHAR(255) NOT NULL,
  content_type VARCHAR(127) NOT NULL, size_bytes INT UNSIGNED NOT NULL,
  disposition VARCHAR(10) NOT NULL DEFAULT 'attachment', content_id VARCHAR(191) NULL,
  storage_path VARCHAR(255) NOT NULL,                     -- disk 'mail' (local/S3)
  created_at TIMESTAMP NULL,
  FOREIGN KEY (email_id) REFERENCES emails(id) ON DELETE CASCADE
);

CREATE TABLE email_dispatches (
  id CHAR(36) PRIMARY KEY, email_id CHAR(36) NOT NULL,
  organization_id CHAR(36) NOT NULL, domain_id CHAR(36) NOT NULL,   -- denormalisasi utk query cepat
  recipient VARCHAR(191) NOT NULL, recipient_hash CHAR(64) NOT NULL, -- sha256(lowercase)
  recipient_type VARCHAR(4) NOT NULL DEFAULT 'to',                   -- to|cc|bcc
  status VARCHAR(12) NOT NULL DEFAULT 'QUEUED', status_rank TINYINT UNSIGNED NOT NULL DEFAULT 0,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0, next_attempt_at TIMESTAMP NULL,
  relay VARCHAR(10) NULL,                                            -- STALWART|BREVO
  message_id VARCHAR(255) NULL, smtp_response VARCHAR(512) NULL, bounce_type VARCHAR(20) NULL,
  open_token CHAR(22) NOT NULL UNIQUE, unsubscribe_token CHAR(22) NOT NULL UNIQUE,
  raw_mime_path VARCHAR(255) NULL,
  queued_at TIMESTAMP NULL, sent_at TIMESTAMP NULL, delivered_at TIMESTAMP NULL,
  first_opened_at TIMESTAMP NULL, first_clicked_at TIMESTAMP NULL,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  INDEX (email_id), INDEX (organization_id, status, created_at), INDEX (organization_id, recipient_hash),
  INDEX (message_id), INDEX (status, next_attempt_at),
  FOREIGN KEY (email_id) REFERENCES emails(id) ON DELETE CASCADE
);

CREATE TABLE email_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, public_id CHAR(36) NOT NULL UNIQUE,
  organization_id CHAR(36) NOT NULL, email_id CHAR(36) NOT NULL, dispatch_id CHAR(36) NOT NULL,
  type VARCHAR(16) NOT NULL, occurred_at DATETIME(3) NOT NULL,
  data JSON NULL, ip VARBINARY(16) NULL, user_agent VARCHAR(512) NULL, is_bot TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL,
  INDEX (dispatch_id, type), INDEX (email_id), INDEX (organization_id, occurred_at), INDEX (organization_id, type, occurred_at)
);

CREATE TABLE tracking_links (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, token CHAR(22) NOT NULL UNIQUE,
  dispatch_id CHAR(36) NOT NULL, email_id CHAR(36) NOT NULL,
  url TEXT NOT NULL, click_count INT UNSIGNED NOT NULL DEFAULT 0, created_at TIMESTAMP NULL,
  INDEX (dispatch_id)
);

CREATE TABLE suppressions (
  id CHAR(36) PRIMARY KEY, organization_id CHAR(36) NOT NULL,
  email VARCHAR(191) NOT NULL, email_hash CHAR(64) NOT NULL,
  reason VARCHAR(20) NOT NULL,         -- HARD_BOUNCE|SOFT_REPEATED|COMPLAINT|UNSUBSCRIBE|MANUAL
  source_dispatch_id CHAR(36) NULL, note VARCHAR(255) NULL, created_at TIMESTAMP NULL,
  UNIQUE (organization_id, email_hash),
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
);

CREATE TABLE webhook_endpoints (
  id CHAR(36) PRIMARY KEY, organization_id CHAR(36) NOT NULL, url VARCHAR(2048) NOT NULL,
  secret TEXT NOT NULL,                                   -- Crypt, format whsec_…
  events JSON NOT NULL,                                   -- ["email.delivered", …] atau ["*"]
  is_active TINYINT(1) NOT NULL DEFAULT 1, consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
  disabled_at TIMESTAMP NULL, deleted_at TIMESTAMP NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  INDEX (organization_id, is_active),
  FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
);

CREATE TABLE webhook_deliveries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, endpoint_id CHAR(36) NOT NULL,
  event_id BIGINT UNSIGNED NOT NULL, event_public_id CHAR(36) NOT NULL,
  attempt TINYINT UNSIGNED NOT NULL DEFAULT 0, status VARCHAR(10) NOT NULL DEFAULT 'PENDING', -- PENDING|SUCCESS|FAILED
  http_status SMALLINT UNSIGNED NULL, response_snippet VARCHAR(500) NULL, duration_ms INT UNSIGNED NULL,
  next_attempt_at TIMESTAMP NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  INDEX (endpoint_id, created_at), INDEX (status, next_attempt_at)
);

CREATE TABLE unmatched_relay_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, relay VARCHAR(10) NOT NULL,
  payload JSON NOT NULL, reason VARCHAR(50) NOT NULL, created_at TIMESTAMP NULL,
  INDEX (created_at)                                       -- retensi 7 hari
);

CREATE TABLE usage_counters (
  organization_id CHAR(36) NOT NULL, period CHAR(7) NOT NULL, -- YYYY-MM
  sent INT UNSIGNED NOT NULL DEFAULT 0, delivered INT UNSIGNED NOT NULL DEFAULT 0,
  bounced INT UNSIGNED NOT NULL DEFAULT 0, complained INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NULL, PRIMARY KEY (organization_id, period)
);
```

Catatan:
- Tabel `jobs`/`failed_jobs`/`cache`/`sessions` standar Laravel tetap dibuat (sessions di Redis atau DB).
- `status_rank`: QUEUED=0, SENDING=1, DEFERRED=1, SENT=2, DELIVERED=3, COMPLAINED=4; terminal gagal (BOUNCED/FAILED/SUPPRESSED)=5. Dipakai agar event telat tidak memundurkan status (§5.4).
- `email_events` bervolume tinggi → pertimbangkan partisi bulanan setelah > 50 juta baris (keputusan di M3).
- Email address dinormalisasi lowercase; hash = `sha256(lowercase(trim(email)))`; gmail dot/plus **tidak** dinormalisasi.

---

## 4. AUTENTIKASI, OTORISASI, RATE LIMIT & KUOTA

### 4.1 API key
- Generate: `psv_{live|test}_` + 32 char base62 dari `random_bytes` (≥ 190 bit).
- Simpan: `key_hash = hash('sha256', $plain)`, `prefix = substr($plain, 0, 13)`. Plain hanya dikembalikan sekali.
- **Cache bersama API & SMTP:** Redis hash `postivo:apikeys`, field = `key_hash`, value = JSON `{"id","org_id","scopes":[…],"domain_ids":null|[…],"env":"live","org_status":"ACTIVE"}`.
  - Ditulis saat key dibuat / scope diubah; `HDEL` saat revoke atau organisasi disuspend → efektif ≤ 5 detik (target 1 detik).
  - `postivo:sync-api-keys` (scheduler tiap menit + saat boot) merekonsiliasi dari MySQL.
  - API: baca Redis dulu; miss → MySQL → isi ulang cache.

```php
// app/Http/Middleware/AuthenticateApiKey.php (inti)
public function handle(Request $request, Closure $next, string ...$scopes)
{
    $plain = $request->bearerToken();
    if (!$plain || !preg_match('/^psv_(live|test)_[A-Za-z0-9]{32}$/', $plain)) {
        throw new ApiException('unauthenticated', 401);
    }
    $hash = hash('sha256', $plain);
    $key = $this->apiKeys->resolve($hash);              // Redis → DB fallback, null bila revoked/suspended
    if (!$key) throw new ApiException('unauthenticated', 401);
    foreach ($scopes as $s) {
        if (!in_array($s, $key->scopes, true)) throw new ApiException('scope_missing', 403);
    }
    $request->attributes->set('api_key', $key);
    app()->instance(CurrentOrganization::class, new CurrentOrganization($key->orgId));
    $this->apiKeys->touchLastUsed($key->id, $request->ip());     // throttle 1x/menit via Redis SET NX EX 60
    return $next($request);
}
```
Perbandingan hash tidak perlu `hash_equals` karena lookup by-hash di indeks; tetap dilarang me-log token.

### 4.2 Console
Fortify-style manual (login, register, verify email, reset password) dengan guard `web`. Organisasi aktif disimpan di session; middleware `EnsureOrganizationMember`. Policy per resource memetakan role (PRD FR-1.3):

| Aksi | owner | admin | developer | viewer |
|---|---|---|---|---|
| Lihat log/dashboard | ✔ | ✔ | ✔ | ✔ |
| Kelola domain/API key/webhook | ✔ | ✔ | ✔ | ✖ |
| Hapus suppression | ✔ | ✔ | ✔ | ✖ |
| Undang/hapus anggota, ubah plan | ✔ | ✔ | ✖ | ✖ |
| Hapus organisasi | ✔ | ✖ | ✖ | ✖ |

### 4.3 Rate limit
```php
RateLimiter::for('api-send', fn (Request $r) =>
    Limit::perSecond(config('postivo.limits.send_per_second'))->by($r->attributes->get('api_key')->id));
RateLimiter::for('api', fn (Request $r) => Limit::perSecond(10)->by($r->attributes->get('api_key')->id));
RateLimiter::for('login', fn (Request $r) => Limit::perMinute(5)->by($r->ip().'|'.strtolower((string) $r->input('email'))));
```
Header `X-RateLimit-*` dan `Retry-After` ditambahkan middleware kustom; respons error mengikuti PRD §8.2.

### 4.4 Kuota (`QuotaService`)
Redis counter atomik (Lua) — `reserve(orgId, n)`:
- Kunci `quota:{org}:m:{YYYYMM}` (TTL 40 hari) dan `quota:{org}:d:{YYYYMMDD}` (TTL 2 hari).
- `INCRBY` lalu bandingkan dengan `monthly_quota` / `daily_limit`; bila melebihi → `DECRBY` kembali & lempar `QuotaExceededException` (→ `429 quota_exceeded` / SMTP `452 4.5.3`).
- Yang dihitung: jumlah *dispatch* (penerima) yang diterima, **bukan** yang `SUPPRESSED`. Dispatch gagal `FAILED` internal dikembalikan (`release`).
- `postivo:usage-sync` (tiap menit) menulis ringkasan ke `usage_counters`; rekonsiliasi dari `email_dispatches` tiap hari 01:00.

---

## 5. INGEST & DISPATCH PIPELINE

### 5.1 `EmailIngestService::accept(IngestData $data): Email`
Urutan (satu fungsi, dipakai API & SMTP):
1. Resolve domain dari `from` → harus `ACTIVE`, milik organisasi, termasuk `domain_ids` key bila dibatasi; else `domain_not_verified` / `forbidden`.
2. Idempotensi: bila `idempotency_key` ada → cari `(organization_id, key)`; bila ada dan `request_hash` sama → kembalikan email lama (flag replay); bila hash beda → `409 conflict (idempotency_key_reused)`.
3. Normalisasi penerima (lowercase, dedup antar to/cc/bcc), maks 50.
4. Tentukan penerima suppressed (query `suppressions` batch by hash). Semua suppressed → `recipient_suppressed` 422.
5. `QuotaService::reserve(org, jumlah_non_suppressed)`.
6. **DB transaction:** insert `emails`, `email_attachments` (file ditulis ke disk `mail` sebelum commit; bila rollback → file dihapus), `email_dispatches` (token acak `open_token`, `unsubscribe_token`; `status = QUEUED` atau `SUPPRESSED`), `email_events` (`queued` / `suppressed`).
7. `DB::afterCommit` → `DispatchOutboundEmailJob::dispatch($dispatchId)->onQueue('outbound')->delay($scheduledAt)` untuk tiap dispatch non-suppressed; webhook event `email.queued`/`email.suppressed` di-enqueue.
8. Kembalikan model; controller memetakan ke respons 202.

Latensi target (PRD FR-4.3) menuntut: tidak ada HTTP call keluar, tidak ada render MIME, maksimal 4–6 query di langkah 1–6.

### 5.2 Queue & worker

| Queue | Isi | Worker (Horizon) | Timeout | Tries |
|---|---|---|---|---|
| `outbound` | `DispatchOutboundEmailJob` | 8 proses, balance auto | 60 s | 8 (manual via backoff) |
| `webhooks` | `SendWebhookEventJob` | 3 | 20 s | 1 (retry dikelola tabel) |
| `tracking` | `ProcessRelayEventJob` & turunan | 2 | 30 s | 3 |
| `default` | verifikasi DNS, email notifikasi | 2 | 60 s | 3 |
| `low` | prune, rekonsiliasi | 1 | 300 s | 1 |

Konfigurasi Redis: `maxmemory-policy noeviction`, `appendonly yes`, `appendfsync everysec`. `retry_after` queue > timeout job terpanjang (90 s).

### 5.3 `DispatchOutboundEmailJob`
```php
public int $tries = 8;
public int $timeout = 60;
public function backoff(): array { return [60, 300, 900, 3600, 10800, 21600, 43200]; }   // PRD §6.3

public function handle(DispatchStateMachine $sm, MimeBuilder $builder, LinkTrackingService $tracking,
                       DkimService $dkim, OutboundRelayService $relay, SuppressionService $suppr): void
{
    // 1. Klaim atomik: hanya satu worker yang berhasil memindahkan QUEUED/DEFERRED → SENDING
    if (!$sm->claim($this->dispatchId)) return;                       // UPDATE … WHERE id=? AND status IN ('QUEUED','DEFERRED')
    $d = EmailDispatch::with(['email.attachments', 'email.domain'])->findOrFail($this->dispatchId);

    if ($d->email->canceled_at) { $sm->transition($d, 'FAILED', reason: 'canceled'); return; }
    if ($suppr->isSuppressed($d->organization_id, $d->recipient_hash)) { $sm->transition($d, 'SUPPRESSED'); return; }
    if ($d->email->domain->status !== DomainStatus::ACTIVE) { $sm->transition($d, 'FAILED', reason: 'domain_inactive'); return; }

    try {
        $message  = $builder->build($d);                               // To/Cc tampil; Bcc tidak; Message-ID <dispatchId@domain>
        $tracking->apply($message, $d);                                // rewrite link + pixel (jika opt-in)
        $signed   = $dkim->sign($message, $d->email->domain);          // TERAKHIR — jangan ubah body setelah ini
        $envelope = $builder->envelope($d);                            // MAIL FROM = b+{dispatchId}@bounces.{domain}; RCPT = recipient
        $result   = $relay->send($signed, $envelope, $d);              // pilih relay, circuit breaker
        $sm->transition($d, 'SENT', relay: $result->relay, smtpResponse: $result->response, rawMime: $signed->toString());
    } catch (TransientRelayException $e) {
        $sm->transition($d, 'DEFERRED', smtpResponse: $e->getMessage());
        $this->release($this->backoff()[min($this->attempts() - 1, 6)]);
    } catch (PermanentRelayException $e) {
        $sm->transition($d, 'BOUNCED', bounceType: $e->bounceType, smtpResponse: $e->getMessage());
        $suppr->handleBounce($d, $e->bounceType);
    }
}

public function failed(Throwable $e): void   // percobaan habis / error tak terduga
{
    app(DispatchStateMachine::class)->forceFinal($this->dispatchId, $this->attempts() >= $this->tries ? 'BOUNCED' : 'FAILED',
        $this->attempts() >= $this->tries ? BounceType::SOFT_EXHAUSTED : null, $e->getMessage());
}
```
Aturan penting:
- Job **idempoten**: `claim()` mencegah double-send. Bila worker mati saat `SENDING`, scheduler `postivo:requeue-stuck` (tiap 5 menit) memindahkan `SENDING` > 10 menit → `DEFERRED` dan men-dispatch ulang. Risiko duplikat kecil (relay menerima tapi worker mati sebelum commit) didokumentasikan & diterima; `Message-ID` deterministik membantu dedup di penerima.
- `raw_mime_path`: MIME final (sudah DKIM) dikompres gzip ke disk `mail`; dipakai Raw MIME Inspector. Retensi 30 hari.
- Mode `psv_test_`: lewati relay, langsung `SENT → DELIVERED` simulasi (event bertanda `data.simulated=true`).

### 5.4 `DispatchStateMachine`
```php
const RANK = ['QUEUED'=>0,'SENDING'=>1,'DEFERRED'=>1,'SENT'=>2,'DELIVERED'=>3,'COMPLAINED'=>4,
              'BOUNCED'=>5,'FAILED'=>5,'SUPPRESSED'=>5];
const ALLOWED = [
  'QUEUED'    => ['SENDING','SUPPRESSED','FAILED'],
  'SENDING'   => ['SENT','DEFERRED','BOUNCED','FAILED','SUPPRESSED'],
  'DEFERRED'  => ['SENDING','BOUNCED','FAILED'],
  'SENT'      => ['DELIVERED','BOUNCED','COMPLAINED','DEFERRED'],
  'DELIVERED' => ['COMPLAINED'],
];
```
`transition()` memakai `UPDATE … WHERE id=? AND status=? ` (optimistic), menulis `email_events` dalam transaksi yang sama, lalu enqueue webhook. Transisi ilegal → tidak mengubah status, tetap menyimpan event (`data.ignored_transition=true`) + log `warning`. `claim()` memakai `status IN ('QUEUED','DEFERRED')`. Event relay dari status `SENT`/`DEFERRED` yang tertunda diterapkan sesuai tabel; `opened`/`clicked` **tidak** mengubah status.

### 5.5 MIME
`MimeBuilder` memakai `symfony/mime` (`Email`): `from`, `to`, `cc`, `replyTo`, `subject`, `text`, `html`, attachment (`addPart(new DataPart(...))` + CID untuk inline), `Date`, `Message-ID` = `<{dispatchId}@{domain}>`, header kustom whitelist (PRD FR-4.5), `List-Unsubscribe: <https://trk.postivo.my.id/u/{unsubscribe_token}>, <mailto:unsubscribe@postivo.my.id?subject={token}>` + `List-Unsubscribe-Post: List-Unsubscribe=One-Click` **hanya** bila email ditandai non-transaksional (header `List-Unsubscribe` dari pengirim dipakai apa adanya). `Bcc` tidak pernah ditulis ke header.

---

## 6. TRACKING (LINK & PIXEL)

### 6.1 Rewrite (`LinkTrackingService::apply`)
- Dijalankan hanya bila `track_clicks` / `track_opens` true.
- Regex terkendali pada `<a … href="…">`: abaikan skema selain `http/https`, `#anchor`, link yang mengandung atribut `data-postivo-notrack`, link ke `trk.postivo.my.id` / host tracking sendiri, link `List-Unsubscribe`. `html_entity_decode` URL sebelum simpan; maks 2.048 char; URL > batas tidak di-rewrite.
- Token: `Str::random(22)` URL-safe. Simpan ke `tracking_links` **dan** `SET trk:link:{token} {url} EX 7776000` (90 hari) agar jalur redirect tidak menyentuh MySQL.
- Domain tracking: `trk.<domain>` bila CNAME lulus (`domains.tracking_host`), selain itu `trk.postivo.my.id`.
- Pixel: `<img src="https://{trk}/o/{open_token}.gif" width="1" height="1" alt="" style="display:none">` sebelum `</body>` (atau di akhir HTML).
- Bagian `text/plain` tidak di-rewrite.
- **Wajib** diuji: link berparameter `&`, URL dengan fragment, HTML tanpa `</body>`, atribut tanpa kutip, uppercase `HREF`, CDATA/komentar kondisional Outlook (tidak boleh rusak).

### 6.2 `TrackingController` (jalur panas, tanpa query DB pada kasus normal)
```php
public function open(string $token, Request $r) {        // GET /o/{token}.gif
    Redis::lpush('postivo:trk:events', json_encode(['k'=>'o','t'=>$token,'ip'=>$r->ip(),'ua'=>$r->userAgent(),'at'=>microtime(true)]));
    return response(base64_decode(self::GIF), 200, [
        'Content-Type'=>'image/gif','Cache-Control'=>'no-store, no-cache, must-revalidate, private','Content-Length'=>43]);
}
public function click(string $token, Request $r) {       // GET /c/{token}
    $url = Redis::get("trk:link:{$token}") ?? $this->fallbackLookup($token);   // DB lalu re-cache
    abort_if(!$url, 404);
    Redis::lpush('postivo:trk:events', json_encode(['k'=>'c','t'=>$token,'ip'=>$r->ip(),'ua'=>$r->userAgent(),'at'=>microtime(true)]));
    return redirect()->away($url, 302)->header('Cache-Control','no-store')->header('Referrer-Policy','no-referrer');
}
private const GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';   // GIF 1x1 transparan, 43 byte
```
Token format dicek `^[A-Za-z0-9_-]{22}$` (route constraint) — token salah → 404 tanpa menyentuh Redis. Tidak ada parameter URL tujuan dari klien → **tidak ada open redirect**.
Unsubscribe: `GET /u/{token}` menampilkan halaman konfirmasi; `POST /u/{token}` (one-click RFC 8058) membuat suppression `UNSUBSCRIBE` + event `unsubscribed`.

### 6.3 Flush (`postivo:tracking-flush`, daemon)
Loop: `LMOVE postivo:trk:events postivo:trk:processing RIGHT LEFT` hingga 500 item → resolve token (batch `WHERE token IN`, open_token via `email_dispatches`) → deteksi bot (UA: `GoogleImageProxy` dihitung *open* valid; scanner/security gateway seperti `Barracuda`, `Proofpoint`, `Mimecast`, `python-requests`, `curl`, `HeadlessChrome` → `is_bot=1`) → `INSERT` bulk `email_events` (`opened`/`clicked`) → update `first_opened_at/first_clicked_at`, `click_count` → enqueue webhook (hanya event pertama per dispatch untuk `opened`; setiap klik non-bot untuk `clicked`) → `LREM` dari processing. Saat boot: pindahkan sisa `processing` kembali ke antrean utama (at-least-once; dedup oleh `(dispatch_id,type,occurred_at,ip)` hash bila perlu). IP disimpan dipotong (IPv4 /24, IPv6 /48) bila `TRACKING_ANONYMIZE_IP=true` (default).

---

## 7. DNS AUDITOR & DKIM

### 7.1 Pembuatan record (`AddDomain` action)
1. Normalisasi domain (lowercase, IDNA→punycode), tolak IP, TLD tak valid, domain milik platform (`postivo.my.id`).
2. `DkimService::generateKeypair()` → RSA-2048 (`openssl_pkey_new`); simpan private PEM terenkripsi, public sebagai base64 DER satu baris.
3. Buat 5 `dns_records` (nilai persis PRD §10). Nilai DKIM: `v=DKIM1; k=rsa; p=<base64>` (>255 char: UI wajib menampilkan versi 1 string; panduan untuk provider yang memecah menjadi beberapa string).

### 7.2 `DnsAuditorService`
```php
public function lookup(string $name, string $type): DnsResult
{
    foreach ($this->resolvers as $r) {                       // [cloudflare, google] — fallback bila error/timeout
        try {
            $resp = Http::timeout(4)->retry(1, 100)->acceptJson()
                ->withHeaders(['Accept' => 'application/dns-json'])
                ->get($r['url'], ['name' => $name, 'type' => $type, 'cd' => 0]);
            if (!$resp->successful()) continue;
            $json = $resp->json();
            if (($json['Status'] ?? 2) === 3) return DnsResult::nxdomain();          // NXDOMAIN → tak perlu fallback
            if (($json['Status'] ?? 2) !== 0) continue;
            $values = collect($json['Answer'] ?? [])
                ->filter(fn ($a) => $a['type'] === $this->typeCode($type))
                ->map(fn ($a) => $this->normalize($a['data'], $type))
                ->values()->all();
            return DnsResult::ok($values);
        } catch (\Throwable) { continue; }
    }
    return DnsResult::error();                               // jaringan/resolver gagal ≠ "record tidak ada"
}
private function normalize(string $data, string $type): string
{
    if ($type === 'TXT') { return implode('', str_getcsv(preg_replace('/"\s+"/', '', $data), ' ', '"')) ?: trim($data, '"'); }
    return strtolower(rtrim($data, '.'));                    // CNAME
}
```
Aturan penilaian per record (`DnsRecordVerifier`):
| Purpose | Lulus bila |
|---|---|
| dkim | TXT ada dan parameter `p=` (spasi dibuang) sama dengan public key tersimpan |
| spf | ada TXT berawalan `v=spf1` (tepat **satu**) yang memuat `include:spf.postivo.my.id`; ≥2 record SPF → gagal dengan pesan "multiple SPF records" |
| return_path | CNAME `bounces.<domain>` → `bounce.postivo.my.id` |
| dmarc | TXT di `_dmarc.<domain>` berawalan `v=DMARC1` (nilai policy apa pun) |
| tracking | CNAME `trk.<domain>` → `trk.postivo.my.id` |
Hasil `DnsResult::error()` **tidak** menandai record gagal (hanya `last_checked_at`), mencegah false negative akibat gangguan resolver.

Status domain: semua `is_required` lulus → `ACTIVE` (`verified_at`); scheduler `dns-audit` tiap 5 menit untuk `PENDING` (maks 72 jam sejak `audit_started_at` → `FAILED`; user dapat "Verify" ulang → reset); harian untuk `ACTIVE` (DKIM/SPF/return_path hilang 3 audit berturut-turut → `SUSPENDED` + notifikasi). Verifikasi manual dibatasi 1x/10 detik per domain.

### 7.3 DKIM signing — **gunakan Symfony, jangan tulis sendiri**
```php
use Symfony\Component\Mime\Crypto\DkimSigner;

public function sign(Email $message, Domain $domain): Message
{
    $pem = Crypt::decryptString($domain->dkim_private_key);
    $signer = new DkimSigner($pem, $domain->domain_name, $domain->dkim_selector);
    return $signer->sign($message, [
        'header_canon' => 'relaxed', 'body_canon' => 'relaxed',
        'headers_to_sign' => ['From','To','Cc','Subject','Date','Message-ID','Reply-To','MIME-Version','Content-Type','List-Unsubscribe','List-Unsubscribe-Post'],
        'signature_expiration_delay' => 0,
    ]);
}
```
- `d=` **selalu** domain pengirim (alignment DMARC). `a=rsa-sha256`. Opsi `headers_to_sign`/canon harus diverifikasi terhadap versi `symfony/mime` yang terpasang (cek nama opsi di vendor); jika nama opsi berbeda, sesuaikan — **test verifikasi wajib** (§13) menjadi penentu.
- Header yang ditambahkan **setelah** sign (mis. `X-Mailin-custom` untuk Brevo, §8.3) tidak boleh masuk daftar `h=`.
- Rotasi selector: kolom `dkim_selector` dapat diganti (`postivo2`), record lama dipertahankan 7 hari (fitur M4).

---

## 8. OUTBOUND RELAY & FAILOVER

### 8.1 Konfigurasi
`config/postivo.php → relay`: `stalwart{host,port,username,password,tls:'starttls'}`, `brevo{host,port,username,key}`, `breaker{window:60,threshold:5,open_seconds:120}`, `timeouts{connect:5,send:30}`.
Stalwart menerima submission hanya dari IP app (firewall) + auth.

### 8.2 `OutboundRelayService::send`
```php
public function send(Message $signed, Envelope $env, EmailDispatch $d): RelayResult
{
    $order = $this->breaker->isOpen('stalwart') ? ['brevo'] : ['stalwart', 'brevo'];
    $lastTransient = null;
    foreach ($order as $name) {
        try {
            $transport = $this->transports->make($name);          // EsmtpTransport TLS, timeout dari config
            $message = $name === 'brevo' ? $this->withBrevoRef($signed, $d) : $signed;
            $sent = $transport->send($message, $env);              // SentMessage
            $this->breaker->success($name);
            return new RelayResult($name, $sent->getDebug());
        } catch (TransportExceptionInterface $e) {
            $kind = $this->classify($e);                           // 'transient' | 'permanent'
            if ($kind === 'permanent') throw new PermanentRelayException($e->getMessage(), $this->bounces->fromSmtp($e));
            $this->breaker->failure($name);                        // koneksi gagal, timeout, 421, 450/451/452, 4.7.x throttling
            $lastTransient = $e;
        }
    }
    throw new TransientRelayException($lastTransient?->getMessage() ?? 'all relays unavailable');
}
```
- **Failover hanya untuk kegagalan transient/ketersediaan**; penolakan permanen (5xx saat submission, mis. `550 5.1.1` karena relay memverifikasi penerima) **tidak** dicoba ulang di relay lain (mencegah duplikasi & menyembunyikan bounce valid).
- Mutasi pesan untuk Brevo: tambah header `X-Mailin-custom: {dispatchId}` **setelah** signing (header tak ditandatangani; aman bagi DKIM). Pesan yang sama tetap membawa DKIM `d=domain pelanggan` → DMARC pass; SPF pass lewat Return-Path (CNAME→bounce.postivo.my.id) dan `include:` Brevo di `spf.postivo.my.id`.
- Karena submission ke Stalwart bersifat *queue-and-forward*, `SENT` = "diterima Stalwart"; status akhir datang dari event (§9).

### 8.3 Circuit breaker (Redis)
Kunci `breaker:{relay}:fail` (sorted set timestamp, window 60 d). Buka bila ≥ `threshold` kegagalan dalam window → `breaker:{relay}:open` EX 120. Setelah kedaluwarsa: *half-open* — izinkan 1 request probe (`SET NX EX 10`); sukses → tutup, gagal → buka lagi. Sinyal tambahan: event throttle ISP dari Stalwart (kode `4.7.x` dari ≥ 30% pengiriman 5 menit terakhir ke Gmail/Outlook) → tandai `breaker:stalwart:isp_throttle` sehingga domain penerima itu diarahkan ke Brevo (`routing_hint` per domain penerima, TTL 30 menit). Setiap perubahan state breaker menulis log `warning` + metrik + notifikasi Telegram.

---

## 9. FEEDBACK, BOUNCE & SUPPRESSION

### 9.1 Endpoint internal
`POST /internal/relay/events/{relay}` (`relay` = `stalwart|brevo`) — hanya dapat diakses dari jaringan privat/allowlist IP relay, **diblokir dari Nginx publik** dan diautentikasi:
- Stalwart: header `X-Postivo-Signature: sha256=<hmac(RELAY_EVENT_SECRET, raw_body)>`.
- Brevo: `Authorization: Bearer <BREVO_WEBHOOK_TOKEN>` (+ allowlist IP Brevo).
Controller hanya memvalidasi, menyimpan payload mentah ke Redis stream/queue `tracking` dan `ProcessRelayEventJob::dispatch` → `200` < 20 ms.

### 9.2 Normalisasi
`RelayEventNormalizer` (satu adapter per relay) menghasilkan DTO `RelayEvent{dispatchId?, messageId?, type(delivered|deferred|bounced|complained), smtpCode, enhancedCode, diagnostic, remoteMx, occurredAt, raw}`. Korelasi dispatch berurutan: (1) VERP `b+{dispatchId}@bounces…` pada envelope sender, (2) `X-Mailin-custom`, (3) `Message-ID`. Event yang tak terkorelasi disimpan ke `unmatched_relay_events` (tabel log kecil, retensi 7 hari) + metrik.
> **Catatan M0:** bentuk payload Stalwart (versi & nama event) dikonfirmasi saat spike; hanya adapter `Stalwart*` yang berubah, DTO tetap.

### 9.3 `BounceClassifier`
| Kondisi | `BounceType` | Efek |
|---|---|---|
| `5.1.1`, `5.1.2`, `5.1.3`, `5.1.6`, `5.2.1`(akun nonaktif) | `HARD` | status `BOUNCED`, suppression `HARD_BOUNCE` |
| `4.x.x`, `5.2.2` (mailbox penuh), `5.3.4` (pesan terlalu besar) | `SOFT` | `DEFERRED`; setelah retry habis → `BOUNCED` `SOFT_EXHAUSTED`; hitung ke aturan soft-bounce |
| `5.7.x` dengan kata kunci blocklist/reputation/spam | `BLOCK` | `BOUNCED`, **tanpa** suppression, alert ke operator (indikasi reputasi IP) |
| Complaint (ARF/Brevo `spam`) | `SPAM_COMPLAINT` | status `COMPLAINED`, suppression `COMPLAINT` |
| Lainnya 5.x.x | `HARD` | seperti hard |
Soft bounce berturut-turut (≥ 3 dalam 7 hari untuk alamat yang sama, tanpa `delivered` di antaranya) → suppression `SOFT_REPEATED` (cek via `SuppressionService`).

### 9.4 `SuppressionService`
`isSuppressed(org, hash)` (cache Redis set per organisasi `supp:{org}` bila ≤ 100k item, selain itu query), `add(...)` idempoten (`INSERT IGNORE`), `remove(...)` (hanya manual/role cukup; mencatat audit log). Penambahan memicu event webhook `email.suppressed` hanya untuk pengiriman berikutnya.

---

## 10. WEBHOOK DISPATCHER

1. Saat event dibuat (`DispatchStateMachine`, flush tracking) → cari endpoint aktif organisasi yang berlangganan tipe event → buat baris `webhook_deliveries` `PENDING` → `SendWebhookEventJob`.
2. `SendWebhookEventJob`:
   - Bangun payload PRD §8.7 dari `email_events`; `t = time()`; `sig = hash_hmac('sha256', "{$t}.{$body}", $secret)`.
   - `SafeUrlGuard::resolve($url)`: HTTPS saja; resolusi DNS; **tolak** IP privat/loopback/link-local/metadata (`10/8, 172.16/12, 192.168/16, 127/8, 169.254/16, ::1, fc00::/7, fe80::/10`); IP hasil resolusi dipin ke koneksi (`CURLOPT_RESOLVE`) untuk mencegah DNS rebinding; redirect dimatikan.
   - `Http::withoutRedirecting()->timeout(10)->withHeaders([...])->post(...)`; `2xx` = sukses.
3. Gagal → `attempt++`, `next_attempt_at` sesuai jadwal [30s, 2m, 10m, 1h, 6h, 24h]; setelah percobaan ke-6 → `FAILED`. `consecutive_failures++` per kegagalan akhir; ≥ 50 → `is_active=false`, `disabled_at`, email pemberitahuan. Sukses → reset counter.
4. Sweeper scheduler tiap menit: `PENDING` dengan `next_attempt_at <= now` yang belum di-queue (job hilang) → dispatch ulang.
5. Replay manual: buat delivery baru dari event yang sama. "Kirim event uji": payload contoh `type=email.test`.

---

## 11. SMTP INGRESS (`smtp-engine`)

Node 20, dependensi: `smtp-server`, `ioredis`, `undici`(fetch). **Tidak** mem-parse MIME; hanya autentikasi, spool, forward.

Alur: `onAuth` → `onMailFrom/onRcptTo` (batas) → `onData` (stream ke file spool, hitung ukuran, `fsync`) → `POST http://app:8045/internal/smtp/ingest` (JSON: `ingest_id`, `api_key_id`, `org_id`, `mail_from`, `rcpt_to[]`, `spool_path`, `size`, `remote_ip`, `tls`) ditandatangani HMAC `X-Postivo-Internal-Signature` → petakan respons Laravel ke kode SMTP (PRD §9).

```javascript
// smtp-engine/server.js
'use strict';
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { pipeline } = require('node:stream/promises');
const { SMTPServer } = require('smtp-server');
const Redis = require('ioredis');

const cfg = {
  redisUrl: process.env.REDIS_URL || 'redis://127.0.0.1:6379',
  appUrl: process.env.APP_INTERNAL_URL || 'http://app:8045',
  secret: process.env.INTERNAL_SHARED_SECRET,             // wajib
  spool: process.env.SPOOL_DIR || '/spool',
  maxSize: 25 * 1024 * 1024,
  maxRcpt: 50,
  tlsKey: fs.readFileSync(process.env.TLS_KEY_PATH),
  tlsCert: fs.readFileSync(process.env.TLS_CERT_PATH),
  ports: (process.env.SMTP_PORTS || '587:starttls,465:tls,2525:starttls').split(','),
};
if (!cfg.secret) throw new Error('INTERNAL_SHARED_SECRET is required');
const redis = new Redis(cfg.redisUrl);

const sha256 = (s) => crypto.createHash('sha256').update(s).digest('hex');
const smtpError = (code, msg) => Object.assign(new Error(msg), { responseCode: code });

async function authFailureGate(ip) {                      // 10 gagal / 10 menit / IP → blokir sementara
  const k = `smtp:authfail:${ip}`;
  if (Number(await redis.get(k)) >= 10) throw smtpError(421, '4.7.0 Too many authentication failures');
}

function buildServer(mode) {
  return new SMTPServer({
    name: 'smtp.postivo.my.id',
    banner: 'Postivo SMTP Ingress',
    secure: mode === 'tls',                                // 465 implicit TLS
    key: cfg.tlsKey, cert: cfg.tlsCert, minVersion: 'TLSv1.2',
    authMethods: ['PLAIN', 'LOGIN'],
    authOptional: false,
    allowInsecureAuth: false,                              // AUTH hanya setelah TLS
    disabledCommands: mode === 'tls' ? ['STARTTLS'] : [],
    size: cfg.maxSize,
    maxClients: 500,
    socketTimeout: 60_000,
    hideSTARTTLS: false,

    async onConnect(session, cb) { try { await authFailureGate(session.remoteAddress); cb(); } catch (e) { cb(e); } },

    async onAuth(auth, session, cb) {
      try {
        await authFailureGate(session.remoteAddress);
        const raw = await redis.hget('postivo:apikeys', sha256(auth.password || ''));
        const key = raw ? JSON.parse(raw) : null;
        if (!key || key.org_status !== 'ACTIVE' || !key.scopes.includes('emails:send')) {
          const k = `smtp:authfail:${session.remoteAddress}`;
          await redis.multi().incr(k).expire(k, 600).exec();
          return cb(smtpError(535, '5.7.8 Authentication credentials invalid'));
        }
        cb(null, { user: key.id, key });                   // session.user = key.id
      } catch (e) { cb(smtpError(454, '4.7.0 Temporary authentication failure')); }
    },

    onRcptTo(address, session, cb) {
      if (session.envelope.rcptTo.length >= cfg.maxRcpt) return cb(smtpError(452, '4.5.3 Too many recipients'));
      cb();
    },

    async onData(stream, session, cb) {
      const ingestId = crypto.randomUUID();
      const file = path.join(cfg.spool, `${ingestId}.eml`);
      try {
        await pipeline(stream, fs.createWriteStream(file, { mode: 0o600, flags: 'wx' }));
        if (stream.sizeExceeded) throw smtpError(552, '5.3.4 Message size exceeds limit');
        const fd = fs.openSync(file, 'r'); fs.fsyncSync(fd); fs.closeSync(fd);

        const body = JSON.stringify({
          ingest_id: ingestId, api_key_id: session.user,
          mail_from: session.envelope.mailFrom && session.envelope.mailFrom.address,
          rcpt_to: session.envelope.rcptTo.map((r) => r.address),
          spool_path: file, size: fs.statSync(file).size,
          remote_ip: session.remoteAddress, tls: !!session.secure,
        });
        const sig = crypto.createHmac('sha256', cfg.secret).update(body).digest('hex');
        const res = await fetch(`${cfg.appUrl}/internal/smtp/ingest`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-Postivo-Internal-Signature': `sha256=${sig}` },
          body, signal: AbortSignal.timeout(20_000),
        });
        const json = await res.json().catch(() => ({}));
        if (res.status === 202 || res.status === 200) return cb(null, `2.0.0 Ok: queued as ${json.email_id}`);
        throw mapLaravelError(res.status, json && json.error && json.error.code);
      } catch (err) {
        fs.promises.unlink(file).catch(() => {});
        if (!err.responseCode) err = smtpError(451, '4.3.0 Temporary failure, please retry');
        cb(err);
      }
    },
  });
}

function mapLaravelError(status, code) {
  switch (code) {
    case 'domain_not_verified': case 'forbidden': return smtpError(550, '5.7.1 Sender domain not verified');
    case 'recipient_suppressed': return smtpError(550, '5.1.1 All recipients are suppressed');
    case 'quota_exceeded': case 'rate_limited': return smtpError(452, '4.5.3 Quota or rate limit exceeded');
    case 'payload_too_large': return smtpError(552, '5.3.4 Message too large');
    case 'validation_failed': return smtpError(554, '5.6.0 Message rejected: invalid content');
    default: return status >= 500 || !status ? smtpError(451, '4.3.0 Temporary failure, please retry')
                                              : smtpError(554, '5.0.0 Transaction failed');
  }
}

for (const entry of cfg.ports) {
  const [port, mode] = entry.split(':');
  const srv = buildServer(mode);
  srv.on('error', (e) => console.error(JSON.stringify({ level: 'error', port, msg: e.message })));
  srv.listen(Number(port), '0.0.0.0', () => console.log(JSON.stringify({ level: 'info', msg: `SMTP ingress listening`, port, mode })));
}
```
Sisi Laravel (`SmtpIngestController`): verifikasi HMAC (`hash_equals`), pastikan `spool_path` berada di bawah direktori spool yang dikonfigurasi (cegah path traversal), `api_key_id` dimuat dari DB (jangan percaya client), parse `MimeMessageParser` (zbateson) → `IngestData(source: SMTP)`: ambil `From` header (bukan envelope), `To/Cc` dari header + envelope `rcpt_to` (penerima yang tidak ada di header ⇒ Bcc), subject, html/text, attachment & inline (CID) → `EmailIngestService::accept`. Hapus file spool setelah commit; job `low` menyapu spool yatim > 1 jam. Lampiran tipe terlarang & ukuran dicek ulang di sini.

Operasional: kontainer smtp membaca sertifikat dari volume Let's Encrypt (reload via SIGHUP/restart terjadwal); health check TCP 587; log JSON tanpa kredensial; `/spool` volume bersama dengan `app`+`horizon` (read/delete).

---

## 12. KONFIGURASI, INFRA, SCHEDULER & OPERASI

### 12.1 `.env.example` (tanpa secret nyata)
```ini
APP_NAME=Postivo
APP_ENV=production
APP_KEY=                       # php artisan key:generate
APP_DEBUG=false
APP_URL=https://postivo.my.id
API_HOST=api.postivo.my.id
TRACKING_HOST=trk.postivo.my.id

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=postivo_db
DB_USERNAME=postivo_user
DB_PASSWORD=                   # isi dari secret store

QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=

INTERNAL_SHARED_SECRET=        # openssl rand -hex 32 (app ↔ smtp-engine)
RELAY_EVENT_SECRET=            # HMAC event Stalwart → app
BREVO_WEBHOOK_TOKEN=

STALWART_RELAY_HOST=           # IP/host Stalwart (privat)
STALWART_RELAY_PORT=587
STALWART_RELAY_USER=
STALWART_RELAY_PASSWORD=
BREVO_FALLBACK_HOST=smtp-relay.brevo.com
BREVO_FALLBACK_PORT=587
BREVO_FALLBACK_USER=
BREVO_FALLBACK_KEY=

MAIL_DISK=mail                 # local | s3
SPOOL_DIR=/spool
TRACKING_ANONYMIZE_IP=true
RETENTION_BODY_DAYS=30
RETENTION_EVENT_DAYS=90
SEND_RATE_PER_SECOND=20
```
Aturan: `.env` tidak pernah di-commit; secret produksi di Coolify/secret store; `config:cache` di produksi.

### 12.2 Docker Compose (produksi, ringkas)
```yaml
services:
  app:       { build: ., command: ["php-fpm+nginx"], env_file: .env, volumes: [spool:/spool, mail:/var/www/storage/app/mail], depends_on: [mysql, redis], ports: ["8045:8045"] }
  horizon:   { build: ., command: php artisan horizon, env_file: .env, volumes: [spool:/spool, mail:/var/www/storage/app/mail], restart: always, stop_grace_period: 90s }
  scheduler: { build: ., command: php artisan schedule:work, env_file: .env, restart: always }
  tracking-flush: { build: ., command: php artisan postivo:tracking-flush, env_file: .env, restart: always }
  smtp:      { build: ./smtp-engine, env_file: .env, volumes: [spool:/spool, certs:/certs:ro], ports: ["587:587","465:465","2525:2525"], depends_on: [redis, app], restart: always }
  mysql:     { image: "mysql:8.0", command: ["--default-authentication-plugin=caching_sha2_password","--sql-mode=STRICT_ALL_TABLES"], volumes: [mysql:/var/lib/mysql], restart: always }
  redis:     { image: "redis:7.2", command: ["redis-server","--appendonly","yes","--appendfsync","everysec","--maxmemory-policy","noeviction","--requirepass","${REDIS_PASSWORD}"], volumes: [redis:/data], restart: always }
volumes: { spool: {}, mail: {}, mysql: {}, redis: {}, certs: {} }
```
Dev: `docker-compose.dev.yml` menambahkan **Mailpit** sebagai pengganti relay (`STALWART_RELAY_HOST=mailpit`), Vite dev server, dan seeding. Nginx menolak `/internal/*` dari host publik (hanya jaringan Docker).

### 12.3 Scheduler (`routes/console.php`)
| Jadwal | Tugas |
|---|---|
| tiap menit | `postivo:sync-api-keys`, `postivo:usage-sync`, sweeper webhook, `postivo:release-scheduled` |
| tiap 5 menit | `postivo:dns-audit --pending`, `postivo:requeue-stuck` |
| harian 01:00 | `postivo:dns-audit --active`, rekonsiliasi kuota, `postivo:prune` (retensi §PRD 12.4) |
| harian 02:00 | cek ambang abuse (bounce > 5% / complaint > 0,3% per organisasi 24 jam → peringatan/suspend) |
| harian | sapu spool yatim, `queue:prune-batches`, `horizon:snapshot` (5 menit) |

### 12.4 Observabilitas & alert
Log JSON (`request_id`, `org_id`, `dispatch_id`); metrik minimum: kedalaman & umur antrean per queue, p95 latensi `POST /emails`, jumlah failover/state breaker, rasio bounce/complaint, DNS audit gagal, event tak terkorelasi. Sumber p95 latensi di dashboard: middleware `RecordApiLatency` menulis durasi tiap `POST /v1/emails` ke Redis sorted set per menit (`metrics:enqueue:{YYYYMMDDHHmm}`, TTL 48 jam); `DashboardStats` menghitung persentil darinya. Alert (Telegram/email): queue `outbound` oldest job > 60 s, breaker terbuka, Redis memori > 80%, disk spool/mail > 80%, bounce > 5%.

### 12.5 Backup, rotasi & pemulihan
- MySQL dump harian + binlog; uji restore bulanan (checklist di `docs/runbook.md`). Volume `mail` ke object storage terenkripsi.
- Rotasi `APP_KEY`: set `APP_PREVIOUS_KEYS`, jalankan `postivo:reencrypt` (re-encrypt `dkim_private_key`, `webhook_endpoints.secret`) lalu hapus key lama.
- Runbook insiden: IP blacklist, Redis penuh, relay down, domain pelanggan abuse (`docs/runbook.md` wajib ada sebelum M3).

---

## 13. STRATEGI TEST

Wajib ada sebelum merge (PRD DoD §13.1). Daftar minimum per area:

| Area | Test |
|---|---|
| DKIM | Pesan hasil `DkimService::sign` diverifikasi dengan `dkimpy`/`opendkim-testmsg` (CI), termasuk body dengan CRLF/spasi ujung, header terlipat, karakter non-ASCII; hasil `pass` |
| Tracking rewrite | Edge case §6.1; link `mailto:`/anchor tidak berubah; pixel hanya sekali; HTML tanpa `</body>` |
| State machine | Semua transisi sah/tidak sah; event out-of-order tidak memundurkan status; `claim()` ganda hanya satu menang |
| Ingest | Idempotensi (sama/beda payload), semua penerima suppressed, kuota (batas tepat), domain non-aktif, attachment terlarang, 51 penerima |
| API | Auth (hilang/salah/revoked/scope), format error PRD §8.2, pagination cursor, rate limit header |
| DNS auditor | Respons Cloudflare/Google palsu (`Http::fake`): TXT terpecah, CNAME dengan titik akhir, NXDOMAIN, resolver error ≠ gagal, SPF ganda |
| Relay | Failover transient → Brevo; permanen tidak failover; breaker open/half-open/close |
| Bounce | Tabel §9.3 (parameterized) + soft-repeated |
| Webhook | Vektor signature tetap (secret+timestamp+body → hex tertentu), SSRF (IP privat, DNS rebinding mock, redirect), jadwal retry, auto-disable |
| SMTP | Integrasi: kirim via `swaks`/Nodemailer ke engine → email tercatat; auth salah 535; tanpa TLS ditolak; 51 rcpt; pesan >25 MB 552; Laravel down → 451 |
| Keamanan | Policy per role, tenant isolation (akses resource organisasi lain → 404), CSRF console |
| Load (k6) | 200 rps `POST /emails` 5 menit: p95 < 50 ms, 0 error, antrean terkuras; 50 eps berkelanjutan 30 menit |
| Deliverability (manual) | mail-tester ≥ 9/10, seed-list Gmail/Outlook/Yahoo: Inbox, SPF/DKIM/DMARC pass |

CI (GitHub Actions/Gitea): Pint, PHPStan lvl 6, Pest (MySQL 8 + Redis service), ESLint untuk smtp-engine, `composer audit`, `npm audit`, build Vite.

---

## 14. ATURAN UNTUK AI AGENT / DEVELOPER
1. Ikuti DDL §3 persis; migrasi harus reversible; jangan menambah kolom/tabel tanpa memperbarui dokumen ini.
2. Satu jalur ingest (`EmailIngestService`); jangan menduplikasi logika di controller atau SMTP adapter.
3. DKIM hanya via `DkimService` (Symfony), selalu langkah terakhir; jangan ubah body/header yang ditandatangani sesudahnya.
4. Jalur tracking tidak boleh query MySQL pada kasus normal; tulis ke Redis, flush batch.
5. Jangan pernah log: API key, password SMTP, secret webhook, private key, isi body email.
6. Semua query tenant lewat global scope; test isolasi wajib untuk resource baru.
7. Failover hanya untuk error transient; error permanen = bounce.
8. Bila Stalwart/Brevo berperilaku berbeda dari asumsi (§9.2), ubah **adapter normalizer**, bukan state machine.
9. Setiap endpoint baru: FormRequest, Resource, Policy/scope, test fitur, entri `docs/openapi.yaml`.
