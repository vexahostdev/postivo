# Deployment Postivo

Stack: `web` (Caddy, TLS otomatis) → `app` (php-fpm) · `queue` · `scheduler` · `smtp` (Node, 587/465) · `mysql` · `redis`.
Definisi ada di `docker-compose.yml`, `Dockerfile`, dan `deploy/`.

## Apa yang butuh server, apa yang tidak

| # | Penghalang peluncuran | Bisa dikerjakan tanpa server? | Yang dibutuhkan |
|---|---|---|---|
| 1 | Relay nyata (Brevo/Stalwart) belum teruji | **Brevo: ya**, dari laptop. **Stalwart: tidak** | Brevo: domain pengirim terverifikasi. Stalwart: VPS dengan port 25 terbuka + IP bersih |
| 2 | Deliverability (SPF/DKIM/DMARC/rDNS) | Sebagian: DNS bisa diatur sekarang | Akses DNS `postivo.my.id`. rDNS/PTR hanya bisa di panel VPS |
| 3 | Paket deploy | Ya (file sudah ada: compose, Dockerfile, CI) | Server untuk menjalankannya |
| 4 | MySQL + Redis | Bisa dicoba lokal via Docker | Server production |
| 5 | Uji pembayaran Xendit | **Tidak**: callback butuh URL publik | `postivo.my.id` online |
| 6 | Tracking via buffer Redis | Ya (kode), belum dikerjakan | Redis (ada di compose) |

Jalur tercepat tanpa menunggu Stalwart: **luncurkan dengan Brevo sebagai relay utama** (`POSTIVO_RELAY=smtp`, isi `POSTIVO_BREVO_DSN`), lalu tambahkan Stalwart saat IP sudah dipanaskan.
Reputasi IP dikelola Brevo sehingga satu VPS biasa cukup untuk aplikasi.

## Spesifikasi minimum server

- 2 vCPU, 4 GB RAM, 40 GB SSD, Ubuntu 22.04/24.04, Docker + Compose plugin.
- Port masuk: 80, 443, 587, 465. Port keluar 25 hanya perlu bila memakai Stalwart (banyak penyedia memblokirnya secara default).

## DNS (`postivo.my.id`)

| Host | Tipe | Nilai |
|---|---|---|
| `@`, `api`, `trk` | A | IP server |
| `smtp` | A | IP server (juga rDNS/PTR-nya) |
| `bounce` | MX/A | IP server (Return-Path VERP) |
| `spf` | TXT | `v=spf1 ip4:<IP server> include:spf.brevo.com ~all` |
| `@` | TXT | SPF domain pengirim sistem (sertakan `include:spf.brevo.com`) |
| `_dmarc` | TXT | `v=DMARC1; p=none; rua=mailto:dmarc@postivo.my.id` |
| `brevo1._domainkey`, `brevo-code` | CNAME/TXT | diberikan Brevo (Senders & IP → Domains) |

Setelah DNS dibuat, buka **Admin → Alat & diagnostik → Jalankan pengecekan**: halaman itu menandai tiap record OK/Periksa/Gagal.

## Langkah deploy pertama

```bash
git clone https://github.com/vexahostdev/postivo.git /opt/postivo && cd /opt/postivo
git checkout main
cp .env.production.example .env.production      # isi semua nilai (APP_KEY, DB_*, BREVO_*, XENDIT_*, ADMIN_*)
docker compose --env-file .env.production up -d --build
docker compose --env-file .env.production logs -f app
```

- `APP_KEY`: `php -r "echo 'base64:'.base64_encode(random_bytes(32));"`. **Cadangkan di tempat terpisah.** Tanpa kunci ini, kunci privat DKIM dan secret webhook di database tidak bisa didekripsi.
- Container `app` menjalankan migrasi dan `postivo:admin:create` (dari `ADMIN_*`) saat start.
- Sertifikat TLS diterbitkan Caddy saat DNS sudah mengarah ke server; jika `smtp` berhenti dengan pesan "Sertifikat belum ada", tunggu lalu `docker compose restart smtp`.
- Ganti password admin awal dan aktifkan 2FA sebelum membuka layanan.

## Setelah deploy

1. **Admin → Alat & diagnostik**: uji koneksi Brevo, kirim email uji ke Gmail, cek header SPF/DKIM/DMARC = pass.
2. Naikkan `POSTIVO_CSP` dari `report` ke `enforce` setelah memeriksa konsol browser tidak melaporkan pelanggaran.
3. Daftarkan monitor uptime ke `https://postivo.my.id/readyz` (503 bila DB/cache/scheduler bermasalah).
4. Pasang cron backup: `15 3 * * * /opt/postivo/deploy/backup.sh` dan salin hasilnya keluar server. Hasil default berada di `/opt/postivo-backups`, di luar build context; `POSTIVO_BACKUP_DIR` dapat menunjuk lokasi eksternal lain. Uji `deploy/restore.sh` di staging. Backup lama di checkout harus dipindahkan keluar sebelum rebuild; bila sudah pernah ikut image, batasi akses image tersebut dan rotasi kredensial yang terdampak.
5. VexaHost: pastikan forwarder `PSV-` sudah ter-deploy, lalu buat satu invoice uji di staging dengan key Xendit asli.
6. Ganti `INTERNAL_WEBHOOK_SECRET` di VexaHost dan Postivo dengan nilai acak yang sama.

## Update rilis

```bash
git pull && docker compose --env-file .env.production up -d --build
```

Rollback: `git checkout <tag-lama>` lalu perintah yang sama. Migrasi bersifat maju; backup dulu sebelum rilis yang mengubah skema.

Untuk upgrade keamanan pada instalasi lama:

- Putuskan sesi web lama saat rollout agar sesi yang belum memiliki binding password tidak terbawa.
- Jika callback pernah mengirim `callback_token` di body, rotasi token Xendit/forwarder yang terdampak dan bersihkan nilai lama dari `webhook_events.payload`, `orders.raw_payload`, session, serta salinan backup sesuai kebijakan retensi. Filter aplikasi mencegah tampilan/pemrosesan ulang token, bukan menghapus salinan historis di database.
- Tinjau akun demo yang mungkin dibuat oleh seeder lama. Pembatasan baru mencegah seed demo di luar `local`/`testing`, tetapi tidak otomatis menghapus akun yang sudah ada.
- Jalankan tes sesi, billing, lifecycle domain/webhook, serta kompetisi kuota dan pembatalan pada MySQL di staging terisolasi sebelum rollout. Sertakan admission pertama lintas organisasi, replay dalam transaksi REPEATABLE READ, dan retry deadlock dengan lampiran. Pemanggil yang membuka transaksi luar harus mengulang keseluruhan transaksi itu bila deadlock, bukan hanya savepoint admission. Tes regresi patch ini belum dijalankan di lingkungan pengembangan ini.
- Pantau exception notifikasi/webhook/antrean. Kegagalan publikasi tidak membatalkan email yang sudah committed; scheduler `postivo:requeue-stuck` memulihkan dispatch QUEUED yang tertinggal setelah 15 menit, tetapi tidak membangun ulang event/webhook yang gagal dicatat. Pastikan scheduler aktif sebelum menerima traffic.

## Belum diuji di sini

Pemeriksaan terdahulu hanya mencatat validasi konfigurasi Compose (`docker compose config`), bukan eksekusi stack. Patch keamanan saat ini belum menjalani tes aplikasi, lint PHP, tes script backup/restore, build image, atau pemeriksaan browser karena sandbox target belum tersedia. Validasi konfigurasi lama bukan bukti validasi perubahan terbaru; jalankan pemeriksaan tersebut di staging terisolasi terlebih dulu.
