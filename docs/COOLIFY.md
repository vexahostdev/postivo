# Panduan Deploy Postivo ke Coolify

Mengikuti pola VexaHost (`PANDUAN_DEPLOY_COOLIFY.md`): satu VPS Coolify, branch Git → environment, domain HTTPS otomatis, env dipaste dari file `.env.{environment}`.
Bedanya: Postivo bukan satu proses web saja, jadi memakai **Docker Compose** (bukan Nixpacks).

## Ringkasan

| Item | Nilai |
| --- | --- |
| Repository | `vexahostdev/postivo` |
| Stack | Laravel 12 · PHP 8.3 · MySQL 8.4 · Redis 7 · Node 22 (smtp-engine) |
| Build pack | **Docker Compose** → file `docker-compose.coolify.yml` |
| Service | `web` (port 80), `app`, `queue`, `scheduler`, `smtp` (587/465), `certbot`, `mysql`, `redis`, `backup` |

| Environment | Branch | Domain | Database | Env template |
| --- | --- | --- | --- | --- |
| **Production** | `main` | `postivo.my.id`, `api.postivo.my.id`, `trk.postivo.my.id` | `postivo_db` | `.env.production` |
| **Staging** | `staging` | `staging.postivo.my.id`, `api-staging…`, `trk-staging…` | `postivo_staging` | `.env.staging` |
| **Development** | `dev` | lokal (`npm run all`, port 8050), tidak di-deploy | SQLite | `.env.development` |

### Kenapa Docker Compose, bukan Nixpacks seperti VexaHost?

1. Ada **server SMTP** (port 587/465) yang bukan HTTP, jadi tidak lewat proxy Coolify.
2. `smtp-engine` dan Laravel **berbagi folder spool** dengan path yang sama persis; di satu stack compose ini mudah dan konsisten.
3. Worker antrean, scheduler, Redis, dan backup ikut satu stack, tidak perlu 5 resource terpisah.

---

## BAGIAN A — Yang dibutuhkan di server

### A1. Server aplikasi (wajib)

| Kebutuhan | Minimum | Disarankan |
| --- | --- | --- |
| OS | Ubuntu 22.04 / 24.04 LTS | sama |
| CPU / RAM | 2 vCPU / 4 GB | **4 vCPU / 8 GB** (Coolify sendiri ±1 GB; production + staging di satu server butuh ini) |
| Disk | 40 GB SSD | 80 GB (MySQL, log, backup 14 hari) |
| IP | 1 IPv4 publik tetap | sama |
| Akses | root / sudo via SSH | sama |

**Port masuk (firewall / security group):**

| Port | Untuk | Catatan |
| --- | --- | --- |
| 22 | SSH | batasi ke IP Anda bila bisa |
| 80, 443 | HTTP(S) via proxy Coolify | wajib |
| 8000 | Dashboard Coolify | tutup setelah memasang domain untuk Coolify |
| **587, 465** | SMTP ingress pelanggan | wajib untuk fitur SMTP relay |

**Cek penyedia VPS sebelum membeli:** sebagian (mis. beberapa paket cloud besar) memblokir port SMTP 25/465/587 secara default. Pastikan port **masuk** 587/465 terbuka. Port **keluar** 25 hanya relevan bila memakai Stalwart (lihat Bagian C).

### A2. Akun & layanan lain

| Kebutuhan | Fungsi |
| --- | --- |
| Domain `postivo.my.id` di **Cloudflare** | DNS + token API untuk sertifikat SMTP (DNS-01) |
| Cloudflare **API Token** (izin `Zone → DNS → Edit`, hanya zona `postivo.my.id`) | dipakai service `certbot` |
| GitHub App Coolify dengan akses ke `vexahostdev/postivo` | auto-deploy dari branch |
| Akun **Brevo** + domain pengirim terverifikasi | email sistem (`noreply@`, `support@`) |
| Xendit (lewat webhook sentral VexaHost) | pembayaran; forwarder `PSV-` di VexaHost harus sudah ter-deploy |
| Penyimpanan luar (S3 / Google Drive / server lain) | salinan backup di luar server |

---

## LANGKAH 1 — Pasang Coolify di VPS

```bash
ssh root@<IP_VPS>
curl -fsSL https://cdn.coollabs.io/coolify/install.sh | bash
```

Buka `http://<IP_VPS>:8000`, buat akun admin Coolify, lalu **Settings → Instance's Domain** isi domain untuk Coolify sendiri (mis. `coolify.vexahostcloud.my.id`) agar ia ikut ber-HTTPS. Jika server ini sudah menjalankan Coolify untuk VexaHost/Flustra, lewati langkah ini: Postivo cukup menjadi project baru di dalamnya (pastikan port 587/465 belum dipakai aplikasi lain).

## LANGKAH 2 — DNS di Cloudflare

| Tipe | Nama | Target | Proxy |
| --- | --- | --- | --- |
| A | `@` | `<IP_VPS>` | boleh Proxied (SSL mode **Full (strict)**) |
| A | `api`, `trk` | `<IP_VPS>` | boleh Proxied |
| A | `staging`, `api-staging`, `trk-staging` | `<IP_VPS>` | boleh Proxied |
| A | `smtp`, `smtp-staging` | `<IP_VPS>` | **DNS only (awan abu-abu)** — SMTP tidak bisa lewat proxy Cloudflare |
| A + MX | `bounce` | A → `<IP_VPS>`, MX → `bounce.postivo.my.id` | **DNS only** |
| TXT | `spf` | `v=spf1 include:spf.brevo.com ~all` (tambah `ip4:` Stalwart bila dipakai) | — |
| TXT | `@` | SPF domain pengirim sistem (sertakan `include:spf.brevo.com`) | — |
| TXT | `_dmarc` | `v=DMARC1; p=none; rua=mailto:dmarc@postivo.my.id` | — |
| CNAME/TXT | `brevo1._domainkey`, `brevo-code` | dari Brevo → *Senders, Domains & Dedicated IPs* | — |

> Tracking (`trk`) dan API aman di belakang proxy Cloudflare. Hanya `smtp*` dan `bounce` yang **wajib DNS only**.

Reverse DNS (PTR) `<IP_VPS>` → `smtp.postivo.my.id` diatur di panel penyedia VPS (bukan di Cloudflare). Ini penting untuk reputasi, terutama bila nanti mengirim langsung.

## LANGKAH 3 — Hubungkan GitHub

Coolify → **Sources → + Add → GitHub App** → install ke organisasi/akun pemilik `vexahostdev/postivo`, beri akses ke repository itu. (Jika sudah ada GitHub App dari VexaHost, tambahkan repository Postivo ke aksesnya.)

## LANGKAH 4 — Project dan environment

Coolify → **Projects → + Add** → nama `Postivo`. Di dalamnya ada environment bawaan `production`; tambah satu lagi `staging`.

## LANGKAH 5 — Resource Docker Compose (ulangi per environment)

1. Masuk project → pilih environment → **+ New Resource** → **Private Repository (with GitHub App)** → pilih `vexahostdev/postivo`.
2. **Build Pack**: pilih **Docker Compose**.
3. **Branch**: `main` (production) atau `staging` (staging).
4. **Docker Compose Location**: `/docker-compose.coolify.yml`.
5. **Continue**. Coolify membaca service dari file itu.
6. Di bagian **Domains**, atur domain pada **service `web`** (port 80) saja:
   - Production: `https://postivo.my.id,https://api.postivo.my.id,https://trk.postivo.my.id`
   - Staging: `https://staging.postivo.my.id,https://api-staging.postivo.my.id,https://trk-staging.postivo.my.id`
7. Service lain (`app`, `queue`, `scheduler`, `smtp`, `certbot`, `mysql`, `redis`, `backup`) **tanpa domain**.
8. Matikan healthcheck otomatis Coolify untuk `web` bila deploy dinilai gagal padahal berjalan (sama seperti VexaHost).

## LANGKAH 6 — Environment Variables

Di resource → **Environment Variables** → **Developer view** → paste isi file `.env.production` (atau `.env.staging`) dari laptop Anda, lalu tambahkan dua variabel khusus Coolify:

```env
DB_ROOT_PASSWORD=<acak panjang>
CLOUDFLARE_API_TOKEN=<token Zone:DNS:Edit>
LETSENCRYPT_EMAIL=<email Anda>
```

Yang perlu dicek per environment:

| Variabel | Production | Staging |
| --- | --- | --- |
| `APP_ENV` | `production` | `staging` |
| `APP_KEY` | acak, **cadangkan terpisah** | acak, **berbeda** dari production |
| `APP_URL` / `POSTIVO_APP_HOST` | `https://postivo.my.id` / `postivo.my.id` | `https://staging.postivo.my.id` / `staging.postivo.my.id` |
| `POSTIVO_SMTP_HOST` | `smtp.postivo.my.id` | `smtp-staging.postivo.my.id` |
| `DB_DATABASE` | `postivo_db` | `postivo_staging` |
| `DB_PASSWORD`, `DB_ROOT_PASSWORD` | acak, berbeda tiap environment | acak, berbeda |
| `POSTIVO_INTERNAL_SECRET` | acak, berbeda tiap environment | acak, berbeda |
| `SMTP_STARTTLS_PORT` / `SMTP_TLS_PORT` | `587` / `465` (bawaan) | **`1587` / `1465`** — port 587/465 hanya bisa dipakai satu kali per server |
| `POSTIVO_RELAY` | `smtp` (isi `POSTIVO_BREVO_DSN`) | `smtp` atau `log` |
| `ADMIN_EMAIL`, `ADMIN_PASSWORD` | password kuat, bukan password uji | password kuat |

Membuat nilai acak:

```bash
php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"   # APP_KEY
openssl rand -hex 32                                                # secret / password
```

Buat DSN Brevo (`POSTIVO_BREVO_DSN`) dari login dan key SMTP Brevo:
`smtp://LOGIN:KEY@smtp-relay.brevo.com:587` (karakter khusus di key di-URL-encode).

Klik **Save**.

## LANGKAH 7 — Deploy dan verifikasi

Klik **Deploy**. Urutan yang terjadi: `mysql` dan `redis` sehat → `app` menjalankan migrasi dan membuat akun admin (`ADMIN_*`) → `web`, `queue`, `scheduler` jalan → `certbot` meminta sertifikat untuk host SMTP (±1 menit) → `smtp` menunggu sertifikat lalu aktif.

Checklist:

- [ ] `https://postivo.my.id/healthz` → `{"status":"ok"}` dan `/readyz` → `ready` (semua check `true`).
- [ ] Landing tampil lengkap (CSS/JS termuat), HTTPS valid.
- [ ] Login admin berhasil, **aktifkan 2FA**, ganti password awal.
- [ ] **Admin → Alat & diagnostik**: *Jalankan pengecekan* DNS (SPF/DMARC/DKIM Brevo = OK), *Uji koneksi* Brevo berhasil, *Kirim uji* ke Gmail Anda dan header SPF/DKIM/DMARC = pass.
- [ ] `openssl s_client -starttls smtp -connect smtp.postivo.my.id:587` menunjukkan sertifikat Let's Encrypt yang valid.
- [ ] Kirim email lewat SMTP: `node scripts/send-test.js` (arahkan ke host SMTP production dengan API key uji).
- [ ] Log `queue` tidak menampilkan error; **Admin → Kesehatan sistem**: scheduler hidup.

Jika `smtp` berulang restart sebelum sertifikat terbit: periksa log `certbot` (token Cloudflare salah, atau DNS belum mengarah).

## LANGKAH 8 — Integrasi eksternal

1. **Xendit via VexaHost**: pastikan forwarder `PSV-` ada di VexaHost production dan `POSTIVO_WEBHOOK_URL` mengarah ke `https://postivo.my.id/api/payment/xendit/callback`. Isi `INTERNAL_WEBHOOK_SECRET` di VexaHost dan Postivo dengan nilai **acak yang sama**.
2. Buat satu invoice uji di staging dengan key Xendit asli, bayar, dan pastikan order berubah **PAID**.
3. **Brevo**: tambahkan IP server ke *Authorized IPs* bila akun Brevo membatasinya.

## LANGKAH 9 — Backup

- Service `backup` membuat dump MySQL tiap 24 jam ke volume `backups` (simpan 14 hari).
- Salin keluar server: tambahkan **Scheduled Task** di Coolify atau cron di host yang menyalin isi volume (`docker volume inspect` untuk lokasi) ke S3/Drive dengan `rclone`.
- **Cadangkan `APP_KEY` di tempat terpisah.** Tanpa itu kunci DKIM privat dan secret webhook di database tidak bisa dibaca meski backup utuh.
- Uji pemulihan di staging berkala: `gunzip -c file.sql.gz | docker exec -i <mysql> mysql -uroot -p…`.

## Update dan rollback

Push ke `main` / `staging` → Coolify deploy otomatis (aktifkan *Auto Deploy*). Rollback: *Deployments → Redeploy* commit sebelumnya. Backup dulu sebelum rilis yang mengubah skema (migrasi hanya maju).

---

## BAGIAN C — Stalwart (relay kirim sendiri, tahap lanjut)

Stalwart dipakai agar tidak bergantung pada Brevo untuk email pelanggan. **Tidak perlu untuk peluncuran awal** (Brevo cukup); tambahkan saat ada pelanggan nyata. Rancangan (BACKEND_SPEC §8): Stalwart berjalan di **server terpisah**, menerima submission hanya dari IP aplikasi.

### C1. Kebutuhan server Stalwart

| Kebutuhan | Detail |
| --- | --- |
| Server | VPS **kedua** (juga bisa ditambahkan ke Coolify sebagai *remote server*), 1–2 vCPU, 2–4 GB, 40 GB |
| IP | IPv4 publik **bersih** (cek Spamhaus, Barracuda, dll. sebelum dipakai) |
| Port keluar **25** | **harus terbuka** — uji: `nc -vz gmail-smtp-in.l.google.com 25` dari server |
| Port masuk | 25 (menerima bounce/DSN di `bounce.postivo.my.id`), 587 **hanya dari IP server aplikasi**, 8080 admin (jangan dibuka umum) |
| PTR / rDNS | IP → `mail1.postivo.my.id`, dan A record `mail1` kembali ke IP yang sama |
| DNS | `mail1` A (DNS only); tambahkan `ip4:<IP Stalwart>` ke TXT `spf.postivo.my.id` |

Alasan server terpisah: reputasi IP pengirim tidak tercampur dengan aplikasi, dan port 25 sering diblokir di VPS aplikasi.

### C2. Pasang di Coolify

1. Hubungkan server kedua: Coolify → **Servers → + Add** (akses SSH).
2. **+ New Resource → Docker Image** pada server itu: `stalwartlabs/stalwart:latest`.
3. Persistent storage: `/opt/stalwart` untuk data. Port mapping: `25:25, 587:587, 8080:8080` (batasi 587 dan 8080 dengan firewall ke IP tertentu).
4. Setelah start, buka admin Stalwart (log container menampilkan password admin awal), lalu:
   - Hostname server `mail1.postivo.my.id`.
   - Buat akun layanan untuk Postivo (user/password kuat) yang boleh mengirim dengan `From` domain mana pun (Postivo yang menandatangani DKIM, jadi Stalwart tidak perlu menandatangani ulang).
   - Aktifkan antrean keluar dengan retry dan TLS oportunistik.
5. Di aplikasi Postivo (env Coolify): `POSTIVO_STALWART_DSN=smtp://USER:PASS@<IP_STALWART>:587`, lalu pastikan `POSTIVO_RELAY=smtp`. Brevo otomatis menjadi cadangan (circuit breaker).
6. Uji di **Admin → Alat & diagnostik → Uji koneksi → Stalwart**, lalu *Kirim uji*.

### C3. Pemanasan (warm-up)

IP baru harus dipanaskan: mulai ±50 email/hari ke penerima aktif, naikkan ±2× tiap 2–3 hari bila bounce <2% dan komplain <0.1%. Pantau di Google Postmaster Tools dan **Admin → Abuse & reputasi**.

### C4. Yang BELUM terbukti (jujur)

- **Event dari Stalwart ke Postivo** (delivered/bounce/complaint) memakai `POST /internal/relay/events/stalwart` dengan header `X-Postivo-Signature` (HMAC) dan `X-Postivo-Timestamp`. Format payload dan cara Stalwart menandatangani webhook **belum diuji dengan Stalwart sungguhan** (spike M0 di PRD). Kemungkinan perlu adapter di `RelayEventController::normalize()` atau perantara kecil yang menandatangani ulang.
- Endpoint `/internal/*` di compose Coolify terbuka lewat domain publik (dilindungi HMAC + toleransi waktu 5 menit). Untuk keamanan tambahan, batasi dengan aturan IP di Cloudflare ke IP Stalwart/Brevo.
- Tanpa event, status email berhenti di `SENT` dan tidak pernah `DELIVERED/BOUNCED` untuk jalur Stalwart. Jalur Brevo butuh pengaturan webhook Brevo terpisah (juga belum diuji).

---

## Troubleshooting

| Kendala | Penyebab | Solusi |
| --- | --- | --- |
| Deploy gagal: `required variable … is missing` | Variabel wajib kosong | Isi `DB_PASSWORD`, `DB_ROOT_PASSWORD`, `CLOUDFLARE_API_TOKEN`, `LETSENCRYPT_EMAIL`, `POSTIVO_INTERNAL_SECRET` |
| `smtp` restart terus | Sertifikat belum terbit | Log `certbot`: token salah / DNS belum benar; tunggu lalu redeploy |
| `port is already allocated` 587/465 | Port dipakai aplikasi lain/environment lain | Ubah `SMTP_STARTTLS_PORT`/`SMTP_TLS_PORT` untuk environment itu |
| SMTP mengembalikan 451/421 saat ingest | `queue`/`app` mati atau spool tidak terbaca | Pastikan service `app` sehat; spool `/spool` harus sama di `app` dan `smtp` |
| Halaman polos tanpa CSS | `APP_URL` bukan HTTPS / aset belum ter-build | `APP_URL` wajib `https://…`, redeploy (build aset ada di Dockerfile) |
| 419 / sesi sering habis | Cookie/HTTPS | `SESSION_SECURE_COOKIE=true`, `APP_URL` benar, Redis sehat |
| Email sistem tidak terkirim | Kredensial Brevo / domain belum terverifikasi | **Admin → Alat & diagnostik → Uji koneksi → Email sistem** |
| 502 dari Cloudflare | SSL mode Flexible | Ubah ke **Full (strict)** |

## Belum diuji di sini

`docker-compose.coolify.yml`, `Dockerfile`, dan skrip `smtp-engine/docker-entrypoint.sh` baru divalidasi sintaksnya. Build image dan jalannya stack di Coolify **belum dicoba** (Docker tidak berjalan di mesin pengembangan). Deploy ke staging dulu, dan harapkan satu-dua penyesuaian kecil (mis. izin folder spool, ekstensi PHP). Bagian C belum pernah dijalankan dengan Stalwart nyata.
