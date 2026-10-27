<section id="pengenalan" data-doc="Pengenalan" data-group="Mulai Cepat">
    <h2>Pengenalan</h2>
    <p>Postivo adalah layanan email transaksional: OTP, reset password, invoice, notifikasi. Anda mengirim lewat <b>REST API</b> atau <b>SMTP</b>; Postivo memvalidasi, mengantrekan, menandatangani DKIM, mengirim lewat relay, lalu melaporkan setiap perubahan status.</p>
    <x-code title="Alur pengiriman">@verbatimREST / SMTP → validasi (domain, kuota, suppression) → antrean → tanda tangan DKIM → relay (Stalwart; fallback Brevo bila gangguan sementara) → event: queued · sent · delivered · bounced · complained@endverbatim</x-code>
    <ul>
        <li><b>Satu pintu masuk.</b> REST dan SMTP memakai aturan validasi yang sama, sehingga hasilnya konsisten.</li>
        <li><b>Satu email, banyak penerima.</b> Setiap penerima punya status sendiri (<code>dispatch</code>) dan timeline sendiri.</li>
        <li><b>Aman bagi OTP.</b> Open/click tracking <b>mati secara bawaan</b> agar link verifikasi tidak ditulis ulang.</li>
    </ul>
    <x-callout title="Base URL">API: <code>https://api.postivo.my.id/v1</code> · SMTP: <code>smtp.postivo.my.id</code> · Console: <code>https://postivo.my.id/console</code></x-callout>
</section>

<section id="quickstart" data-doc="Quickstart 5 menit" data-group="Mulai Cepat">
    <h2>Quickstart 5 menit</h2>
    <ol>
        <li><a href="{{ route('register') }}">Buat akun</a> dan verifikasi email Anda.</li>
        <li>Buka <b>Domains</b> → tambahkan domain pengirim (disarankan subdomain, mis. <code>mail.contoh.com</code>).</li>
        <li>Pasang lima record DNS di penyedia DNS Anda, lalu klik <b>Verifikasi</b> sampai status <b>Active</b>.</li>
        <li>Buka <b>API Keys</b> → buat key <code>live</code> dengan scope <code>emails:send</code>. Salin — hanya tampil sekali.</li>
        <li>Kirim email pertama:</li>
    </ol>
    <div x-data="{ t: 'curl' }">
        <div class="mt-3 flex gap-1" role="tablist">@foreach(['curl', 'node', 'php', 'python'] as $l)<button type="button" role="tab" :aria-selected="t === '{{ $l }}'" @click="t = '{{ $l }}'" class="px-2.5 py-1 font-mono text-[11px] uppercase tracking-wider" :class="t === '{{ $l }}' ? 'bg-red-500 text-white' : 'border border-line text-muted'">{{ $l }}</button>@endforeach</div>
        <div x-show="t === 'curl'"><x-code title="curl">@verbatimcurl https://api.postivo.my.id/v1/emails \
  -H "Authorization: Bearer $POSTIVO_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "from": "Acme <noreply@mail.contoh.com>",
    "to": ["user@example.com"],
    "subject": "Kode verifikasi Anda",
    "html": "<p>Kode: <b>123456</b></p>",
    "text": "Kode: 123456"
  }'@endverbatim</x-code></div>
        <div x-cloak x-show="t === 'node'"><x-code title="Node.js (fetch)">@verbatimconst res = await fetch('https://api.postivo.my.id/v1/emails', {
  method: 'POST',
  headers: {
    Authorization: `Bearer ${process.env.POSTIVO_API_KEY}`,
    'Content-Type': 'application/json',
  },
  body: JSON.stringify({
    from: 'Acme <noreply@mail.contoh.com>',
    to: ['user@example.com'],
    subject: 'Kode verifikasi Anda',
    html: '<p>Kode: <b>123456</b></p>',
    text: 'Kode: 123456',
  }),
});
console.log(res.status, await res.json()); // 202@endverbatim</x-code></div>
        <div x-cloak x-show="t === 'php'"><x-code title="PHP (Laravel HTTP client)">@verbatim$res = Http::withToken(env('POSTIVO_API_KEY'))
    ->post('https://api.postivo.my.id/v1/emails', [
        'from'    => 'Acme <noreply@mail.contoh.com>',
        'to'      => ['user@example.com'],
        'subject' => 'Kode verifikasi Anda',
        'html'    => '<p>Kode: <b>123456</b></p>',
        'text'    => 'Kode: 123456',
    ]);
$res->status(); // 202@endverbatim</x-code></div>
        <div x-cloak x-show="t === 'python'"><x-code title="Python (requests)">@verbatimimport os, requests

r = requests.post(
    "https://api.postivo.my.id/v1/emails",
    headers={"Authorization": f"Bearer {os.environ['POSTIVO_API_KEY']}"},
    json={
        "from": "Acme <noreply@mail.contoh.com>",
        "to": ["user@example.com"],
        "subject": "Kode verifikasi Anda",
        "html": "<p>Kode: <b>123456</b></p>",
        "text": "Kode: 123456",
    },
)
print(r.status_code, r.json())  # 202@endverbatim</x-code></div>
    </div>
    <p>Respons <code>202 Accepted</code> berarti email sudah divalidasi dan masuk antrean — bukan berarti sudah terkirim. Pantau statusnya di <b>Emails</b> atau lewat <a href="#webhook-event">webhook</a>.</p>
    <x-code title="Respons 202" lang="json">@verbatim{
  "id": "0192f3a0-7c1e-7b3a-9d4e-5a1f0c2b8e11",
  "status": "queued",
  "created_at": "2026-10-27T08:15:30Z",
  "recipients": [
    { "email": "user@example.com", "dispatch_id": "0192f3a0-7c20-7d11-8a2b-1c3d4e5f6a7b", "status": "QUEUED" }
  ]
}@endverbatim</x-code>
    <x-callout title="Coba tanpa risiko">Buat API key <code>test</code> (sandbox): permintaan divalidasi dan tercatat, tetapi tidak ada email yang benar-benar dikirim, dan domain tidak perlu terverifikasi.</x-callout>
</section>

<section id="autentikasi" data-doc="Autentikasi & API key" data-group="Mulai Cepat">
    <h2>Autentikasi &amp; API key</h2>
    <p>Kirim API key di header <code>Authorization: Bearer psv_live_…</code>. Format key: <code>psv_{live|test}_</code> + 32 karakter. Key disimpan sebagai hash — Postivo tidak bisa menampilkannya lagi, jadi simpan di secret manager, bukan di kode.</p>
    <table>
        <thead><tr><th>Scope</th><th>Mengizinkan</th></tr></thead>
        <tbody>
            <tr><td><code>emails:send</code></td><td>Kirim, batch, cancel email; login SMTP</td></tr>
            <tr><td><code>emails:read</code></td><td>Daftar email, detail, event</td></tr>
            <tr><td><code>domains:manage</code></td><td>Tambah, verifikasi, ubah, hapus domain</td></tr>
            <tr><td><code>suppressions:manage</code></td><td>Kelola suppression list</td></tr>
            <tr><td><code>webhooks:manage</code></td><td>Kelola endpoint webhook</td></tr>
            <tr><td><code>api_keys:manage</code></td><td>Buat/cabut API key</td></tr>
        </tbody>
    </table>
    <x-callout type="warn" title="Praktik terbaik">Beri tiap aplikasi key sendiri dengan scope seminimal mungkin (cukup <code>emails:send</code> untuk aplikasi biasa). Cabut key yang bocor dari halaman <b>API Keys</b> — efeknya langsung. Key <code>live</code> hanya bisa dibuat setelah email akun terverifikasi.</x-callout>
    <x-code title="401 — key salah / dicabut" lang="json">@verbatim{ "error": { "code": "unauthenticated", "message": "Invalid or missing API key.", "request_id": "9b2c…" } }@endverbatim</x-code>
</section>
