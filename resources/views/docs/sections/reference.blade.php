<section id="api-endpoint" data-doc="Daftar endpoint API" data-group="Referensi API">
    <h2>Daftar endpoint</h2>
    <p>Base URL <code>https://api.postivo.my.id/v1</code> · JSON UTF-8 · waktu ISO-8601 UTC. Setiap respons membawa <code>X-Request-Id</code> — sertakan saat melapor masalah.</p>
    <table>
        <thead><tr><th>Method &amp; path</th><th>Scope</th><th>Fungsi</th></tr></thead>
        <tbody>
            <tr><td><code>POST /emails</code></td><td>emails:send</td><td>Kirim email (header opsional <code>Idempotency-Key</code>)</td></tr>
            <tr><td><code>POST /emails/batch</code></td><td>emails:send</td><td>Kirim hingga 100 email, respons 207</td></tr>
            <tr><td><code>GET /emails</code></td><td>emails:read</td><td>Daftar; filter <code>status</code>, <code>tag</code>, <code>to</code>, <code>from_date</code>, <code>to_date</code>, <code>limit</code> (1–100), <code>cursor</code></td></tr>
            <tr><td><code>GET /emails/{id}</code></td><td>emails:read</td><td>Detail + penerima + event</td></tr>
            <tr><td><code>GET /emails/{id}/events</code></td><td>emails:read</td><td>Timeline event</td></tr>
            <tr><td><code>POST /emails/{id}/cancel</code></td><td>emails:send</td><td>Batalkan yang masih <code>QUEUED</code></td></tr>
            <tr><td><code>GET /domains</code> · <code>POST /domains</code></td><td>domains:manage</td><td>Daftar / tambah domain</td></tr>
            <tr><td><code>GET /domains/{id}</code> · <code>PATCH</code> · <code>DELETE</code></td><td>domains:manage</td><td>Detail / ubah default tracking / hapus</td></tr>
            <tr><td><code>POST /domains/{id}/verify</code></td><td>domains:manage</td><td>Jalankan audit DNS sekarang</td></tr>
            <tr><td><code>GET /suppressions</code> · <code>POST</code> · <code>DELETE /{id}</code></td><td>suppressions:manage</td><td>Kelola suppression</td></tr>
            <tr><td><code>GET /webhooks</code> · <code>POST</code> · <code>PATCH /{id}</code> · <code>DELETE /{id}</code></td><td>webhooks:manage</td><td>Kelola webhook</td></tr>
            <tr><td><code>GET /api-keys</code> · <code>POST</code> · <code>DELETE /{id}</code></td><td>api_keys:manage</td><td>Kelola API key</td></tr>
            <tr><td><code>GET /ping</code></td><td>—</td><td>Cek ketersediaan API</td></tr>
        </tbody>
    </table>
    <p>Pagination memakai <b>cursor</b>: respons daftar berisi <code>{"data": […], "next_cursor": "…"}</code>; kirim nilainya sebagai <code>?cursor=</code> untuk halaman berikutnya.</p>
</section>

<section id="api-error" data-doc="Format & kode error" data-group="Referensi API">
    <h2>Format &amp; kode error</h2>
    <x-code title="Format seragam" lang="json">@verbatim{ "error": { "code": "validation_failed", "message": "…", "request_id": "9b2c…", "details": { "field": ["…"] } } }@endverbatim</x-code>
    <table>
        <thead><tr><th>HTTP</th><th><code>code</code></th><th>Penyebab</th></tr></thead>
        <tbody>
            <tr><td>400</td><td><code>bad_request</code></td><td>JSON rusak</td></tr>
            <tr><td>401</td><td><code>unauthenticated</code></td><td>Key hilang, salah, atau dicabut</td></tr>
            <tr><td>403</td><td><code>forbidden</code> · <code>scope_missing</code></td><td>Izin kurang; organisasi ditangguhkan; key tak boleh memakai domain itu</td></tr>
            <tr><td>404</td><td><code>not_found</code></td><td>Resource tidak ada / bukan milik organisasi</td></tr>
            <tr><td>409</td><td><code>conflict</code></td><td>Domain sudah terdaftar; Idempotency-Key bentrok; email tak bisa dibatalkan</td></tr>
            <tr><td>413</td><td><code>payload_too_large</code></td><td>Lebih dari 25 MB</td></tr>
            <tr><td>422</td><td><code>validation_failed</code> · <code>domain_not_verified</code> · <code>recipient_suppressed</code></td><td>Data tidak valid; domain <code>From</code> belum Active; semua penerima suppressed</td></tr>
            <tr><td>429</td><td><code>rate_limited</code> · <code>quota_exceeded</code></td><td>Terlalu cepat / kuota habis. Ada header <code>Retry-After</code></td></tr>
            <tr><td>5xx</td><td><code>internal_error</code></td><td>Gangguan di sisi Postivo — aman di-retry dengan <code>Idempotency-Key</code></td></tr>
        </tbody>
    </table>
</section>

<section id="api-rate" data-doc="Rate limit & kuota" data-group="Referensi API">
    <h2>Rate limit &amp; kuota</h2>
    <ul>
        <li><b>Rate limit:</b> <code>POST /emails</code> 20 permintaan/detik per API key; endpoint lain 10/detik. Header <code>X-RateLimit-Limit</code>, <code>-Remaining</code>, <code>-Reset</code> ada di setiap respons.</li>
        <li><b>Kuota bulanan &amp; batas harian</b> mengikuti <a href="#paket">paket</a>. Hanya penerima yang benar-benar diproses yang dihitung (yang <code>SUPPRESSED</code> dan sandbox tidak).</li>
        <li>Saat <code>429</code>, tunggu sesuai <code>Retry-After</code> lalu ulangi dengan <code>Idempotency-Key</code> yang sama.</li>
        <li>Anda mendapat email peringatan saat kuota mencapai 80% dan 100%.</li>
    </ul>
</section>
