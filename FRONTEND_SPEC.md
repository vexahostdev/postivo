# POSTIVO — Frontend Technical Specification

**Versi:** 3.0.0 (Development-Ready) · selaras dengan `PRD_POSTIVO_FULL.md` v3.0.0 dan `BACKEND_SPEC.md` v3.0.0
**Stack:** Laravel 12 Blade (server-rendered), Alpine.js 3.14, Tailwind CSS **4** (CSS-first config), Vite **7**, Chart.js 4.4 (lazy), ikon Lucide inline SVG
**Root proyek:** `D:\Project - Postivo\` (root aplikasi Laravel)
**Prinsip:** server-rendered dulu, JavaScript hanya untuk interaksi (copy, polling, tab, chart). Tidak ada React/Vue/SPA di console maupun landing produksi.

---

## DAFTAR ISI
1. [Pipeline Aset](#1-pipeline-aset)
2. [Design System](#2-design-system)
3. [Peta Route & Hierarki View](#3-peta-route--hierarki-view)
4. [Layout & Komponen Blade](#4-layout--komponen-blade)
5. [JavaScript (Alpine)](#5-javascript-alpine)
6. [Spesifikasi Halaman Console](#6-spesifikasi-halaman-console)
7. [Domain DNS Wizard](#7-domain-dns-wizard)
8. [Detail Email: Timeline, MIME Inspector, Preview](#8-detail-email-timeline-mime-inspector-preview)
9. [Suppression, Webhook, API Key, SMTP](#9-suppression-webhook-api-key-smtp)
10. [Landing Page (Publik)](#10-landing-page-publik)
11. [Checklist QA UI](#11-checklist-qa-ui)
12. [Aturan untuk AI Agent / Developer](#12-aturan-untuk-ai-agent--developer)

---

## 1. PIPELINE ASET

| Lapisan | Teknologi | Versi (dikunci) | Catatan |
|---|---|---|---|
| View | Blade + komponen anonim `<x-…>` | Laravel 12 | tanpa Livewire |
| Interaktivitas | Alpine.js | 3.14.x (npm) | tidak lewat CDN di produksi |
| Styling | Tailwind CSS | 4.x (`@tailwindcss/vite`) | konfigurasi di CSS (`@theme`), **tanpa** `tailwind.config.js` |
| Build | Vite + `laravel-vite-plugin` | 7.x | HMR di dev |
| Chart | Chart.js | 4.4.x | di-import dinamis hanya di halaman yang butuh |
| Ikon | `blade-lucide-icons` (SVG inline) | terbaru stabil | stroke 1.5px |
| Font | Inter + JetBrains Mono | self-host (`/public/fonts`, woff2) | tanpa Google Fonts di produksi (privasi & performa) |

`vite.config.js`:
```javascript
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
  plugins: [
    laravel({ input: ['resources/css/app.css', 'resources/js/app.js', 'resources/css/landing.css'], refresh: true }),
    tailwindcss(),
  ],
  server: { host: '0.0.0.0', port: 5174, strictPort: true, hmr: { host: 'localhost' } },
});
```
Anggaran performa: landing ≤ 120 KB JS+CSS gzip total (tanpa font), LCP < 2,0 s di 4G; console halaman daftar ≤ 200 KB JS gzip.

---

## 2. DESIGN SYSTEM

### 2.1 Karakter
**Swiss Editorial Minimalist.** Pure white, satu aksen merah, garis 1px, tipografi tegas, ruang kosong presisi. Dua konteks:

| | Landing (publik) | Console (internal) |
|---|---|---|
| Nuansa | Editorial, wordmark raksasa, rail margin vertikal, pill badge | Padat data, presisi, alat developer (rujukan: Resend, Postmark) |
| Radius | `rounded-full` **hanya** untuk pill/badge/tombol kecil | **0** (tajam) kecuali avatar bulat & dot status |
| Bayangan | tidak ada | tidak ada |
| Gradien | tidak ada | tidak ada |
| Border | 1px solid merah / merah transparan | 1px solid `border` token |

### 2.2 Token (Tailwind 4 `@theme` di `resources/css/app.css`)
```css
@import "tailwindcss";

@theme {
  --color-red-500: #FE0000;          /* aksen utama */
  --color-red-600: #D60000;          /* hover/active */
  --color-red-50:  #FFF5F5;
  --color-paper:   #FBFBFD;
  --color-ink:     #0A0A0C;
  --color-line:    #E5E5E5;
  --color-muted:   #6B6B73;          /* teks sekunder, kontras 5.1:1 di putih */
  --color-ok:      #0F7B3F;          /* hijau teks status sukses (kontras ≥ 4.5) */
  --color-warn:    #8A5A00;          /* amber teks */
  --font-sans: "Inter", ui-sans-serif, system-ui, -apple-system, "Helvetica Neue", sans-serif;
  --font-mono: "JetBrains Mono", ui-monospace, "SF Mono", Menlo, monospace;
  --radius-*: initial;               /* matikan skala radius bawaan di console; landing memakai kelas rounded-full */
}
@layer base {
  body { @apply bg-white text-ink font-sans antialiased; }
  ::selection { background: var(--color-red-500); color: #fff; }
  :focus-visible { outline: 2px solid var(--color-red-500); outline-offset: 2px; }
}
```
Aturan warna: merah `#FE0000` hanya untuk aksi utama, status error/gagal, dan aksen landing — **bukan** teks paragraf panjang di console. Teks merah di atas putih (kontras 4.0:1) hanya boleh ≥ 18px atau bold ≥ 14px; paragraf memakai `ink`/`muted`. Warna status selalu disertai ikon/teks (jangan hanya warna).

### 2.3 Tipografi
UI label: Inter 12–14px; heading halaman: Inter 600, 20–24px, `tracking-tight`; data teknis (DNS, Message-ID, kode SMTP, ID, timestamp): JetBrains Mono 12px. Judul section console `UPPERCASE` mono 11px `tracking-wide` hanya untuk label tabel/kartu.

### 2.4 Spasi & grid
Skala 4px. Console: sidebar 232px (collapse ke ikon di < 1024px, drawer di < 768px), konten `max-w-[1280px]`, padding halaman 24px (16px mobile). Tabel padat: baris 40px. Landing: grid 3 kolom `68px | 1fr | 68px` (rail) di ≥ lg, `44px` di md, tanpa rail di mobile.

### 2.5 Status & warna
| Status dispatch | Label | Gaya (teks / border) |
|---|---|---|
| QUEUED | Queued | muted / line |
| SENDING, DEFERRED | Sending / Deferred | warn / warn |
| SENT | Sent | ink / ink |
| DELIVERED | Delivered | ok / ok |
| BOUNCED, FAILED | Bounced / Failed | red-500 / red-500 |
| COMPLAINED | Complained | red-600, latar red-50 |
| SUPPRESSED | Suppressed | muted, coret ringan |
Domain: PENDING (warn), ACTIVE (ok), FAILED (merah), SUSPENDED (merah, latar red-50).

---

## 3. PETA ROUTE & HIERARKI VIEW

### 3.1 Route web (`routes/web.php`)
| Route | Controller | View |
|---|---|---|
| `GET /` | `LandingController` | `landing` |
| `GET /login`, `/register`, `/forgot-password`, `/reset-password/{token}`, `/verify-email` | Auth | `auth/*` |
| `GET /console` | Dashboard | `console/dashboard` |
| `GET /console/domains` · `/create` · `/{domain}` | Domain | `console/domains/{index,create,show}` |
| `POST /console/domains/{domain}/verify` | Domain@verify | JSON (lihat §7) |
| `GET /console/emails` · `/{email}` | Email | `console/emails/{index,show}` |
| `GET /console/emails/{email}/mime` · `/preview` | Email | raw MIME teks / HTML preview (disajikan sandboxed) |
| `GET /console/suppressions` | Suppression | `console/suppressions/index` |
| `GET /console/webhooks` · `/{endpoint}` | Webhook | `console/webhooks/{index,show}` |
| `GET /console/settings/api-keys` · `/smtp` · `/organization` · `/members` | Settings | `console/settings/*` |
| `GET /u/{token}` · `POST /u/{token}` | Unsubscribe | `public/unsubscribe` |
Semua aksi mutasi memakai method `POST/PATCH/DELETE` + CSRF; **tidak ada aksi yang mengubah state lewat GET**.

### 3.2 Hierarki view
```
resources/views/
├── layouts/{app,guest,landing}.blade.php
├── components/
│   ├── button.blade.php           # variant: primary|outline|ghost|danger, size: sm|md
│   ├── status-pill.blade.php      # props: status (dispatch/domain) → label+ikon+gaya §2.5
│   ├── dns-row.blade.php          # satu baris record + copy + status
│   ├── stat-card.blade.php        # label, value, delta, spark (opsional)
│   ├── data-table.blade.php       # slot head/body, state kosong, sticky header
│   ├── empty-state.blade.php      # ikon, judul, deskripsi, CTA
│   ├── page-header.blade.php      # judul, deskripsi, slot actions, breadcrumb
│   ├── modal.blade.php            # Alpine, focus trap, ESC
│   ├── copy-field.blade.php       # nilai mono + tombol copy
│   ├── code-block.blade.php       # tab bahasa (curl/node/php/python) + copy
│   ├── timeline.blade.php         # stepper event email
│   ├── flash.blade.php            # toast sukses/error (aria-live)
│   └── form/{input,select,toggle,label,error}.blade.php
├── auth/{login,register,forgot-password,reset-password,verify-email}.blade.php
├── console/
│   ├── dashboard.blade.php
│   ├── domains/{index,create,show}.blade.php
│   ├── emails/{index,show}.blade.php
│   ├── suppressions/index.blade.php
│   ├── webhooks/{index,show}.blade.php
│   └── settings/{api-keys,smtp,organization,members}.blade.php
├── public/unsubscribe.blade.php
└── landing.blade.php
```

---

## 4. LAYOUT & KOMPONEN BLADE

**`layouts/app`:** header 48px (switcher organisasi, pencarian cepat `/` fokus, menu user), sidebar navigasi (Dashboard, Emails, Domains, Suppressions, Webhooks, Settings), slot konten, region `<x-flash>`. Banner global bila organisasi `SUSPENDED` atau email belum diverifikasi (blokir aksi kirim). Skip-link "Lewati ke konten" di baris pertama.

**`<x-status-pill>`** — contoh:
```blade
@props(['status'])
@php
  $map = [
    'DELIVERED' => ['Delivered','text-ok border-ok','check'], 'BOUNCED' => ['Bounced','text-red-500 border-red-500','x'],
    'QUEUED' => ['Queued','text-muted border-line','clock'], 'SENT' => ['Sent','text-ink border-ink','send'],
    'DEFERRED' => ['Deferred','text-warn border-warn','refresh-cw'], 'SENDING' => ['Sending','text-warn border-warn','loader'],
    'COMPLAINED' => ['Complained','text-red-600 border-red-500 bg-red-50','alert-triangle'],
    'FAILED' => ['Failed','text-red-500 border-red-500','x-octagon'], 'SUPPRESSED' => ['Suppressed','text-muted border-line line-through','ban'],
    'ACTIVE' => ['Active','text-ok border-ok','check'], 'PENDING' => ['Pending','text-warn border-warn','clock'],
    'SUSPENDED' => ['Suspended','text-red-600 border-red-500 bg-red-50','pause'],
  ];
  [$label,$cls,$icon] = $map[$status] ?? [$status,'text-muted border-line','circle'];
@endphp
<span {{ $attributes->class(["inline-flex items-center gap-1 border px-1.5 py-0.5 font-mono text-[11px] uppercase tracking-wide $cls"]) }}>
  <x-dynamic-component :component="'lucide-'.$icon" class="size-3" aria-hidden="true"/>{{ $label }}
</span>
```
Semua komponen: wajib state `hover`, `focus-visible`, `disabled`; target sentuh ≥ 40px untuk tombol primer di mobile.

---

## 5. JAVASCRIPT (ALPINE)

`resources/js/app.js` (hanya inisialisasi + komponen reusable; kode halaman tertentu di `resources/js/pages/*.js` di-import dinamis):
```javascript
import './bootstrap';
import Alpine from 'alpinejs';
import focus from '@alpinejs/focus';
Alpine.plugin(focus);

document.addEventListener('alpine:init', () => {
  Alpine.data('clipboard', () => ({
    copied: false,
    async copy(text) {
      try { await navigator.clipboard.writeText(text); }
      catch { const t = document.createElement('textarea'); t.value = text; document.body.append(t); t.select(); document.execCommand('copy'); t.remove(); }
      this.copied = true; setTimeout(() => (this.copied = false), 1500);
    },
  }));

  Alpine.data('dnsAuditor', ({ verifyUrl, records, status }) => ({
    records, status, verifying: false, error: null, lastChecked: null, cooldown: 0, timer: null,
    async verifyNow() {
      if (this.verifying || this.cooldown > 0) return;
      this.verifying = true; this.error = null;
      try {
        const res = await fetch(verifyUrl, {
          method: 'POST', credentials: 'same-origin',
          headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
        });
        if (res.status === 429) throw new Error('Terlalu sering. Coba lagi beberapa detik lagi.');
        if (!res.ok) throw new Error('Gagal memeriksa DNS. Coba lagi.');
        const data = await res.json();
        this.records = data.records; this.status = data.status; this.lastChecked = new Date();
        if (data.status === 'ACTIVE') window.dispatchEvent(new CustomEvent('flash', { detail: { type: 'success', text: 'Domain aktif. Siap mengirim.' } }));
      } catch (e) { this.error = e.message; }
      finally { this.verifying = false; this.startCooldown(10); }
    },
    startCooldown(s) { this.cooldown = s; clearInterval(this.timer); this.timer = setInterval(() => { if (--this.cooldown <= 0) clearInterval(this.timer); }, 1000); },
    get allRequiredOk() { return this.records.filter(r => r.is_required).every(r => r.is_verified); },
  }));

  Alpine.data('tabs', (initial) => ({ tab: initial, set(t) { this.tab = t; } }));
});

window.Alpine = Alpine;
Alpine.start();
```
Aturan JS: tanpa `innerHTML` dengan data pengguna; fetch selalu menyertakan CSRF; semua teks UI yang dinamis lewat `x-text`. Auto-polling halaman domain `PENDING`: tiap 30 detik (hanya saat tab terlihat — `document.visibilityState`), berhenti saat `ACTIVE`.

---

## 6. SPESIFIKASI HALAMAN CONSOLE

Setiap halaman wajib punya empat state: **loading** (skeleton, bukan spinner penuh), **empty** (`<x-empty-state>` + CTA), **error** (pesan + coba lagi), **data**.

| Halaman | Isi & perilaku | Sumber data |
|---|---|---|
| **Dashboard** | Rentang waktu (24 jam · 7 hari · 30 hari). 5 kartu: Sent, Delivered %, Bounce %, Complaint %, p95 enqueue (ms). Grafik garis (sent vs delivered vs bounced) dan donut ringkas status. Daftar 10 email terbaru, domain yang butuh perhatian (PENDING/SUSPENDED), peringatan (kuota ≥ 80%, bounce > 3%). Bounce > 5% / complaint > 0,3% tampil merah | `DashboardStats` (agregat dari `usage_counters` + query terindeks; cache 30 s) |
| **Domains / index** | Tabel: domain, status pill, DKIM/SPF/RP ringkas (3 dot ✓/✗), terakhir dicek, aksi | `domains` |
| **Domains / create** | Satu field FQDN + penjelasan subdomain `mail.` direkomendasikan; validasi inline; sukses → redirect ke `show` | — |
| **Domains / show** | Wizard DNS (§7), tab: Records · Settings (default tracking, hapus) · Activity | `dns_records` |
| **Emails / index** | Filter: status, domain, tag, tanggal, penerima/ID (debounce 300 ms, query server). Tabel: waktu (mono, relatif + tooltip absolut UTC & lokal), penerima, subjek, domain, status, relay. Pagination cursor "Muat lebih banyak" | `email_dispatches` join `emails` |
| **Emails / show** | §8 | — |
| **Suppressions** | Tabel + pencarian + filter alasan; tombol "Tambah" (modal) dan "Hapus" (konfirmasi, role ≥ developer); ekspor CSV | `suppressions` |
| **Webhooks** | Daftar endpoint (URL, event, status, kegagalan beruntun), modal tambah/edit (event checklist), secret ditampilkan sekali + "Roll secret"; detail: riwayat delivery (status, HTTP code, durasi, snippet, tombol Replay), "Kirim event uji" | `webhook_*` |
| **Settings / API keys** | Tabel (nama, prefix, scope, terakhir dipakai, dibuat) + "Buat key" (modal: nama, environment live/test, scope checklist, batasan domain). Key penuh tampil **sekali** di modal khusus (copy-field, peringatan, tombol "Saya sudah menyimpan" baru menutup). Revoke butuh konfirmasi ketik nama key | `api_keys` |
| **Settings / SMTP** | Host `smtp.postivo.my.id`, port 587/465/2525, user `postivo`, password = "API key aktif Anda" (+ tautan buat key), contoh konfigurasi (Laravel `.env`, WordPress, Nodemailer, Python `smtplib`) di `<x-code-block>` | statis + daftar key |
| **Settings / Organization** | Nama, plan, kuota bulanan (progress bar), batas harian, reputasi 30 hari (bounce/complaint), zona bahaya (hapus organisasi — owner) | `organizations`, `usage_counters` |
| **Settings / Members** | Daftar anggota + role, undang via email, ubah role, hapus | `organization_members` |

Notifikasi: flash sukses/error otomatis hilang 5 s (error tidak otomatis hilang), `role="status"` / `role="alert"`.

---

## 7. DOMAIN DNS WIZARD

Komponen utama halaman `domains/show`. Perbaikan dibanding draf lama: nilai record dilewatkan lewat `@js()` (aman dari tanda kutip/XSS), verifikasi `POST`, hasil memperbarui baris tanpa reload.

```blade
{{-- resources/views/console/domains/show.blade.php (potongan) --}}
<div x-data="dnsAuditor(@js(['verifyUrl' => route('console.domains.verify', $domain), 'status' => $domain->status->value,
                              'records' => $domain->dnsRecords->map->only(['id','purpose','type','name','value','is_required','is_verified','last_seen_value'])->values()]))"
     x-init="document.visibilityState && {{ $domain->status->value === 'PENDING' ? 'true' : 'false' }} && setInterval(() => document.visibilityState === 'visible' && verifyNow(), 30000)"
     class="border border-line bg-white">
  <div class="flex items-center justify-between gap-4 border-b border-line p-4">
    <div>
      <h2 class="font-mono text-xs font-semibold uppercase tracking-wide">DNS verification</h2>
      <p class="mt-1 text-sm text-muted">Tambahkan record berikut di penyedia DNS Anda (Cloudflare, cPanel, dll). Propagasi bisa memakan waktu hingga 48 jam.</p>
    </div>
    <x-button @click="verifyNow" ::disabled="verifying || cooldown > 0">
      <span x-text="verifying ? 'Memeriksa…' : (cooldown > 0 ? `Tunggu ${cooldown}s` : 'Verifikasi DNS')"></span>
    </x-button>
  </div>
  <p x-show="error" x-text="error" role="alert" class="border-b border-line bg-red-50 px-4 py-2 text-sm text-red-600"></p>
  <table class="w-full text-left font-mono text-xs">
    <thead class="border-b border-line bg-paper text-[10px] uppercase text-muted">
      <tr><th class="w-20 px-4 py-2.5">Type</th><th class="px-4 py-2.5">Name</th><th class="px-4 py-2.5">Value</th><th class="w-32 px-4 py-2.5 text-center">Status</th></tr>
    </thead>
    <tbody class="divide-y divide-line">
      <template x-for="r in records" :key="r.id">
        <tr x-data="clipboard">
          <td class="px-4 py-3 font-semibold" x-text="r.type"></td>
          <td class="break-all px-4 py-3"><span x-text="r.name"></span></td>
          <td class="px-4 py-3">
            <div class="flex items-start gap-2"><span class="break-all" x-text="r.value"></span>
              <button type="button" @click="copy(r.value)" class="shrink-0 text-muted hover:text-ink" :aria-label="`Salin nilai ${r.purpose}`">
                <span x-show="!copied">@svg('lucide-copy','size-4')</span><span x-show="copied" class="text-ok">Tersalin</span>
              </button></div>
            <p x-show="!r.is_verified && r.last_seen_value" class="mt-1 text-[11px] text-warn">Terdeteksi: <span x-text="r.last_seen_value"></span></p>
          </td>
          <td class="px-4 py-3 text-center">
            <span x-show="r.is_verified" class="text-ok">✓ Verified</span>
            <span x-show="!r.is_verified" :class="r.is_required ? 'text-warn' : 'text-muted'" x-text="r.is_required ? 'Pending' : 'Disarankan'"></span>
          </td>
        </tr>
      </template>
    </tbody>
  </table>
</div>
```
Perilaku:
- Header: baris `purpose` ditampilkan sebagai label kecil di kolom Type (DKIM/SPF/Return-Path/DMARC/Tracking); record **wajib** diberi penanda; DMARC & Tracking "Disarankan".
- Petunjuk kontekstual: bila SPF sudah ada di domain, tampilkan callout "Gabungkan `include:spf.postivo.my.id` ke record SPF yang ada — jangan membuat dua record SPF." Nilai DKIM panjang ditampilkan utuh (tidak `truncate`) dengan tombol copy.
- Endpoint `verify` mengembalikan `{status, records:[{id,purpose,type,name,value,is_required,is_verified,last_seen_value}]}`; `429` bila < 10 detik sejak pemeriksaan terakhir.
- Saat `ACTIVE`: banner hijau + CTA "Buat API key" dan "Kirim email uji" (tombol memanggil API internal pengiriman dari domain tsb ke email user).

---

## 8. DETAIL EMAIL: TIMELINE, MIME INSPECTOR, PREVIEW

**Header:** subjek, ID (copy), from → to, domain, waktu, relay (`STALWART`/`BREVO`), status pill, tag, metadata (JSON collapsible). Email dengan banyak penerima: tab per penerima (status berbeda per dispatch).

**Timeline (`<x-timeline>`)** — vertikal, kronologis dari `email_events`, tiap langkah: ikon, label, waktu (mono, ms), detail (kode SMTP & respons relay, MX jauh, alasan bounce).
Urutan utama: `Queued → Sent → Delivered` (atau `Deferred ×n → Bounced`/`Failed`/`Suppressed`). `Opened`, `Clicked`, `Complained`, `Unsubscribed` tampil sebagai **cabang event** di bawah `Delivered` (bukan langkah status), dengan jumlah dan waktu pertama. Label `Opened` diberi tooltip "Perkiraan — dapat terpengaruh Apple Mail Privacy Protection/prefetch"; event `is_bot` disembunyikan secara default dengan toggle "Tampilkan trafik bot".

**Drawer/Tab inspector:** `Preview` · `HTML` · `Text` · `Headers` · `Raw MIME` · `Events (JSON)`.
- **Preview:** `<iframe sandbox srcdoc="…" referrerpolicy="no-referrer" loading="lazy">` tanpa `allow-scripts`/`allow-same-origin`; atribut `csp="default-src 'none'; img-src data: https:; style-src 'unsafe-inline'"`; gambar eksternal diblokir sampai user menekan "Muat gambar" (mencegah pelacakan balik). Toggle viewport desktop/mobile.
- **Raw MIME:** diambil dari endpoint `GET /console/emails/{id}/mime` (`Content-Type: text/plain; charset=utf-8`, `X-Content-Type-Options: nosniff`, `Content-Disposition: inline`), ditampilkan dalam `<pre>` mono, tombol "Salin" dan "Unduh .eml". Header `DKIM-Signature`, `Message-ID`, `Return-Path` disorot. Setelah retensi (30 hari): state "Konten telah dihapus sesuai kebijakan retensi".
- Body/attachment hanya terlihat oleh role ≥ `viewer` organisasi pemilik (policy); akses diaudit (log).

---

## 9. SUPPRESSION, WEBHOOK, API KEY, SMTP

- **Suppression:** alasan ditampilkan sebagai label (`Hard bounce`, `Complaint`, `Unsubscribe`, `Manual`, `Soft bounce berulang`) dengan tautan ke dispatch sumber; penambahan massal via paste daftar (maks 1.000/aksi, validasi per baris dengan laporan baris gagal). Hapus butuh konfirmasi dan peringatan: "Mengirim ke alamat yang pernah bounce/komplain merusak reputasi domain."
- **Webhook:** form URL (HTTPS wajib, validasi klien + server), pilih event (grup: Delivery, Engagement, Failure), tampilan secret `whsec_…` sekali, panel "Verifikasi signature" berisi contoh kode (Node/PHP/Python) memakai vektor uji dari PRD §8.7. Riwayat delivery menampilkan request body & snippet respons (dipotong 500 char).
- **API key:** lihat §6. Key `psv_test_` diberi label "TEST — tidak benar-benar mengirim".
- **SMTP:** teks "Password = API key Anda"; peringatan tidak membagikan; indikator koneksi **tidak** ada (tidak ada tes dari browser).

---

## 10. LANDING PAGE (PUBLIK)

### 10.1 Keputusan
Prototipe `templates/postivo_landing.html` (diperbarui di repo ini) adalah **referensi visual statis** tanpa React/Babel/CDN runtime. Implementasi produksi = `resources/views/landing.blade.php` + `landing.css` + Alpine untuk menu/modal/toast; konten dari array di `LandingController`/`config/landing.php` (mudah diubah tanpa menyentuh markup).

### 10.2 Struktur & konten (wajib)
1. **Header:** emblem bulat merah (logo Postivo), tombol `Menu` (overlay layar penuh merah: Home · Infrastructure · Developers · Pricing · Contact + email `support@postivo.my.id`), tautan `Console`/`Sign in`.
2. **Hero:** wordmark POSTIVO raksasa (SVG teks, `aria-label`), 1 kalimat positioning: *"Transactional email API and SMTP relay for developers — send, track and debug every message."*, CTA utama "Get started" (→ `/register`) dan "Read the docs".
3. **Code card:** blok `curl` `POST /v1/emails` + respons `202` (tab curl/Node/PHP/Python), warna monokrom + aksen merah.
4. **Capabilities (3 baris editorial):** (a) *Dedicated SMTP Relay* — Stalwart + failover Brevo, TLS, retry backoff; (b) *Domain Authentication & Suppression* — SPF/DKIM/DMARC guided setup, suppression otomatis, cek DNS live; (c) *Real-time Events* — webhook HMAC-SHA256, timeline per email, raw MIME. Setiap baris: judul, subjudul, daftar layanan, tombol `Explore` yang membuka modal ringkas (dari array `deliverables`).
5. **Developer strip:** cuplikan timeline email (Queued → Sent → Delivered) sebagai ilustrasi nyata produk, dan matriks DNS (5 record).
6. **Infrastructure:** daftar node/region **hanya yang benar-benar ada**; sampai ada lebih dari 1 region, tampilkan "Jakarta (primary)" saja. Dilarang menampilkan node fiktif.
7. **Footer:** navigasi (Docs, Status, Privacy, Terms, AUP, Contact), wordmark besar "POSTIVO", toast kecil.

### 10.3 Aturan konten (mengikat — dari PRD §1.3)
- **Dilarang** angka tak terverifikasi: "99.98% inbox", "15M+ events", "100/100 Postmaster", "0.01% bounce", latensi node fiktif. Boleh: "p95 API enqueue < 50 ms" (hanya setelah terukur di produksi) dan fakta teknis (TLS 1.2+, DKIM RSA-2048, HMAC-SHA256).
- Tidak ada sisa copy template lama ("Creative Studio", "Los Angeles", "brand identity", "case study", "Featured Work") dan tidak ada ilustrasi atlet/agave/kulit. Ilustrasi hanya artefak produk: kartu kode, timeline, matriks DNS, wordmark.
- Tombol/ikon sosial "gh"/"docs" harus menuju URL nyata (GitHub/Docs) atau dihapus; tidak ada tombol yang hanya memunculkan toast.
- Footer wordmark = **POSTIVO** (bukan "Thanks"/"DELIVER"); easter-egg pill "250 OK!" boleh dipertahankan sebagai elemen dekoratif `aria-hidden`.
- Judul "ghost" tidak boleh memakai kontras di bawah 3:1 untuk teks bermakna; teks bermakna selalu terbaca penuh.
- SEO: `<title>` ≤ 60 char, meta description, OpenGraph/Twitter card, `lang="en"` (konten Inggris; halaman `/id` menyusul), JSON-LD `SoftwareApplication`, `sitemap.xml`, `robots.txt`, favicon set. CSP ketat (tanpa inline script selain nonce).
- Aksesibilitas landing: rail teks vertikal dekoratif `aria-hidden="true"`; tombol Menu `aria-expanded`/`aria-controls`; overlay punya focus trap & ESC; `prefers-reduced-motion` mematikan animasi.

### 10.4 Interaksi
Hover pada kartu/artefak: transform halus ≤ 2px & perubahan warna (tanpa bayangan); modal `Explore` (border merah 1px, tanpa blur berat); toast hanya untuk aksi nyata (mis. "Email disalin").

---

## 11. CHECKLIST QA UI

Setiap halaman/komponen baru wajib lulus sebelum merge:
- [ ] Empat state (loading/empty/error/data) ada dan dites.
- [ ] Navigasi keyboard penuh; fokus terlihat; modal punya focus trap + ESC + mengembalikan fokus.
- [ ] Kontras teks ≥ 4.5:1 (UI besar ≥ 3:1); status tidak hanya dibedakan warna.
- [ ] Responsif 360 / 768 / 1024 / 1440 px tanpa scroll horizontal (tabel lebar dalam kontainer `overflow-x-auto`).
- [ ] Tidak ada `rounded-*` (kecuali pill/avatar/dot), `shadow-*`, atau gradien pada console.
- [ ] Semua nilai dinamis di-escape (`{{ }}` / `x-text` / `@js`); tidak ada `{!! !!}` dengan data pengguna.
- [ ] Form: label terkait, pesan error per field (`aria-describedby`), tombol submit disabled saat proses, CSRF.
- [ ] Teks UI konsisten bahasa (console: Indonesia/Inggris satu per rilis — default **Inggris** untuk istilah teknis, penjelasan **Indonesia**; pilih satu per halaman, jangan campur dalam satu kalimat).
- [ ] Lighthouse: Performance ≥ 90, Accessibility ≥ 95 (landing); Accessibility ≥ 95 (console).
- [ ] Uji Playwright untuk alur: register → tambah domain → verifikasi (fake) → buat API key → lihat email → hapus suppression.

---

## 12. ATURAN UNTUK AI AGENT / DEVELOPER
1. Console mengikuti §2 secara ketat: garis 1px, tanpa bayangan/gradien/radius; merah hanya aksen aksi & error.
2. Server-rendered Blade + Alpine; jangan menambah framework JS. Chart.js di-load dinamis.
3. Tidak ada aksi mutasi via GET; semua `fetch` menyertakan CSRF; semua output di-escape.
4. HTML email pengguna **hanya** dirender di `iframe sandbox` tanpa script; jangan pernah `{!! !!}`.
5. Tampilkan kunci/secret sekali, jangan simpan di localStorage/DOM setelah ditutup.
6. Ikuti §10.3 untuk setiap teks landing; angka marketing harus bersumber data nyata.
7. Sebelum membuat komponen baru, cek `components/` — pakai ulang; dokumentasikan props di komentar atas file.
