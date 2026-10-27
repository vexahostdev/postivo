# POSTIVO — Admin Panel Platform (Super Admin)

**Versi:** 1.0.0 · Pelengkap `PRD_POSTIVO_FULL.md`, `BACKEND_SPEC.md`, `FRONTEND_SPEC.md`
**Tujuan:** staf Postivo mengoperasikan platform: mengawasi tenant, mencegah abuse, mengelola pembayaran & plan, memantau kesehatan sistem, dan membantu pelanggan — dengan jejak audit untuk setiap aksi.

---

## 1. Prinsip
1. **Admin ≠ owner organisasi.** Admin platform adalah atribut `users.platform_role`, terpisah dari peran di organisasi. Seorang admin tidak otomatis anggota organisasi mana pun.
2. **Least privilege.** Tiga peran staf dengan matriks izin eksplisit (§3). Default: tidak punya akses.
3. **Semua aksi tulis diaudit** (siapa, apa, target, alasan, IP) di tabel append-only `admin_audit_logs`. Aksi sensitif **wajib menyertakan alasan**.
4. **Akses diperketat:** rute di `/admin` (opsional host terpisah `ADMIN_HOST`), 2FA TOTP wajib di production, allowlist IP opsional, rate limit login.
5. **Impersonasi = hanya-baca.** Admin melihat console sebagai pelanggan dengan banner permanen; semua request non-GET diblokir; setiap sesi dicatat.
6. **Tidak ada secret yang tampil** (API key hash, kunci DKIM privat, secret webhook, isi `.env`).

## 2. Peran staf
| Peran | Tujuan |
|---|---|
| `superadmin` | Semua izin, termasuk mengelola staf, plan, pengaturan platform |
| `support` | Membantu pelanggan: lihat semua, impersonasi (read-only), kirim reset/verify, catatan org |
| `finance` | Order & pembayaran, plan/kuota org, rekonsiliasi; baca organisasi |

## 3. Matriks izin
| Izin | superadmin | support | finance |
|---|:-:|:-:|:-:|
| `view` (dashboard, org, user, domain, email, log) | ✓ | ✓ | ✓ |
| `impersonate` | ✓ | ✓ | — |
| `org.suspend` (suspend/unsuspend org) | ✓ | ✓ | — |
| `org.plan` (ubah plan/kuota/masa aktif) | ✓ | — | ✓ |
| `org.note` (catatan internal) | ✓ | ✓ | ✓ |
| `user.manage` (verifikasi manual, kirim reset, disable) | ✓ | ✓ | — |
| `staff.manage` (angkat/cabut staf) | ✓ | — | — |
| `domain.manage` (audit ulang, suspend domain) | ✓ | ✓ | — |
| `billing.manage` (tandai lunas manual, reproses webhook, expire order) | ✓ | — | ✓ |
| `plans.manage` (katalog plan & harga) | ✓ | — | — |
| `blocklist.manage` | ✓ | ✓ | — |
| `system.manage` (retry/hapus failed job) | ✓ | — | — |
| `settings.manage` | ✓ | — | — |

## 4. Modul & halaman
| Rute `/admin/…` | Isi |
|---|---|
| `/` Dashboard | KPI platform: organisasi, pengguna, email 24j/30j, rasio delivered/bounce/komplain, MRR (org berbayar aktif), order pending, webhook bermasalah, failed jobs, event relay tak cocok |
| `/organizations` + `/{id}` | Daftar/cari/filter (plan, status). Detail: anggota, domain, pemakaian bulan ini, reputasi 7 hari, order, catatan, audit org. Aksi: suspend/unsuspend (alasan, email ke owner), beri/ubah plan, override kuota, impersonasi |
| `/users` + `/{id}` | Cari pengguna; detail keanggotaan; aksi: verifikasi email manual, kirim tautan reset, nonaktifkan/aktifkan, atur peran staf |
| `/domains` | Semua domain lintas org; filter status; audit ulang paksa; suspend/aktifkan |
| `/emails` | Pencarian lintas org: penerima, message-id, dispatch/email id; detail + timeline |
| `/abuse` | Reputasi per org (7 hari): volume, bounce %, komplain %, tingkat risiko; suspend satu klik. Scan otomatis `postivo:abuse-scan` |
| `/billing` | Order (filter status, cari), detail + payload; tandai lunas manual, expire, reproses event; log `webhook_events`; ringkasan pendapatan |
| `/plans` | Katalog plan: nama, harga, kuota, batas harian, fitur, aktif — diubah tanpa deploy (menimpa default di `config/postivo.php`) |
| `/blocklist` | Blocklist global (alamat atau domain penerima) yang berlaku di semua org |
| `/suppressions` | Cari suppression lintas org; hapus entri |
| `/system` | Kesehatan: heartbeat scheduler, circuit breaker relay, antrean & failed jobs (retry/hapus), event relay tak cocok, spool SMTP, versi & lingkungan |
| `/audit` | Log audit dengan filter admin/aksi/org/rentang waktu |
| `/settings` | Pengaturan platform: buka/tutup pendaftaran, banner pengumuman di console, ambang abuse |
| `/security` | Setup 2FA TOTP untuk akun admin sendiri |

## 5. Skema tambahan
- `users`: `platform_role` (null|superadmin|support|finance), `disabled_at`, `two_factor_secret` (terenkripsi), `two_factor_confirmed_at`.
- `organizations`: `internal_notes` (text), `suspended_at`.
- `admin_audit_logs` (append-only): `id, admin_id, action, target_type, target_id, organization_id, reason, meta json, ip, user_agent, created_at`.
- `plans`: `key, name, price, monthly_quota, daily_limit, features json, is_active, sort` (baris menimpa config).
- `blocked_recipients`: `id, pattern (email|@domain), reason, created_by, created_at`.
- `platform_settings`: `key, value json`.

## 6. Reputasi & abuse
Per org, jendela 7 hari: `bounce% = (BOUNCED hard) / terkirim`, `komplain% = COMPLAINED / terkirim`, minimal 50 email agar dinilai.
| Level | Aturan |
|---|---|
| OK | bounce < 3% dan komplain < 0,1% |
| Waspada | bounce 3–5% atau komplain 0,1–0,3% |
| Bahaya | bounce > 5% atau komplain > 0,3% |
`postivo:abuse-scan` (tiap jam): level Bahaya → **auto-suspend** bila `abuse_auto_suspend` aktif (default **mati**), selalu kirim email ke `support@`, dan catat di audit (admin = sistem).

## 7. Keamanan
- Login admin memakai login console biasa + 2FA TOTP (RFC 6238, 30 dtk, 6 digit, toleransi ±1 langkah). Kode pemulihan: tidak ada — reset 2FA oleh superadmin lain (diaudit).
- `ADMIN_REQUIRE_2FA` (default true di production). `ADMIN_IP_ALLOWLIST` (CSV, kosong = semua).
- Sesi admin kedaluwarsa 2 jam tanpa aktivitas (re-verifikasi 2FA).
- Akun staf yang `disabled_at` tidak bisa login.

## 8. Di luar cakupan versi ini
Editor template email sistem, tiket dukungan terintegrasi, invoice PDF, refund otomatis via Xendit, peran staf kustom.
