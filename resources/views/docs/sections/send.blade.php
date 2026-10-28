<section id="kirim-email" data-doc="POST /v1/emails" data-group="Mengirim Email">
    <h2>POST /v1/emails</h2>
    <p>Mengirim satu email ke satu atau beberapa penerima. Scope: <code>emails:send</code>.</p>
    <div class="overflow-x-auto">
    <table>
        <thead><tr><th>Field</th><th>Tipe</th><th>Keterangan</th></tr></thead>
        <tbody>
            <tr><td><code>from</code></td><td>string, wajib</td><td><code>"Nama &lt;alamat@domain&gt;"</code> atau alamat saja. Domain harus Active.</td></tr>
            <tr><td><code>to</code></td><td>array, wajib</td><td>Alamat penerima. Maks 50 penerima gabungan <code>to</code>+<code>cc</code>+<code>bcc</code>.</td></tr>
            <tr><td><code>cc</code>, <code>bcc</code></td><td>array</td><td>Opsional. Penerima duplikat digabung.</td></tr>
            <tr><td><code>subject</code></td><td>string, wajib</td><td>Maks 255 karakter.</td></tr>
            <tr><td><code>html</code>, <code>text</code></td><td>string</td><td>Minimal salah satu wajib. Kirim keduanya untuk kompatibilitas terbaik.</td></tr>
            <tr><td><code>reply_to</code></td><td>string</td><td>Alamat balasan.</td></tr>
            <tr><td><code>headers</code></td><td>object</td><td>Header kustom. Header sistem (From, To, Subject, Message-ID, DKIM-Signature, dst.) diabaikan.</td></tr>
            <tr><td><code>attachments</code></td><td>array</td><td>Lihat <a href="#lampiran">Lampiran</a>.</td></tr>
            <tr><td><code>tags</code>, <code>metadata</code></td><td>array / object</td><td>Lihat <a href="#jadwal-tag">Jadwal, tag, metadata</a>.</td></tr>
            <tr><td><code>track_opens</code>, <code>track_clicks</code></td><td>boolean</td><td>Bawaan <code>false</code> (atau bawaan domain).</td></tr>
            <tr><td><code>scheduled_at</code></td><td>ISO-8601</td><td>Waktu kirim di masa depan.</td></tr>
        </tbody>
    </table>
    </div>
    <p>Setiap penerima menjadi satu <i>dispatch</i> terpisah dan menerima email sendiri (alamat penerima lain tidak terlihat). Penerima yang ada di <a href="#suppression">suppression list</a> ditandai <code>SUPPRESSED</code> dan tidak dikirimi; bila <b>semua</b> penerima suppressed, permintaan ditolak <code>422 recipient_suppressed</code>.</p>
    <x-code title="Respons error validasi — 422" lang="json">@verbatim{
  "error": {
    "code": "validation_failed",
    "message": "The given data was invalid.",
    "request_id": "9b2c…",
    "details": { "to.0": ["The to.0 field must be a valid email address."] }
  }
}@endverbatim</x-code>
</section>

<section id="batch" data-doc="Pengiriman batch" data-group="Mengirim Email">
    <h2>Pengiriman batch</h2>
    <p><code>POST /v1/emails/batch</code> menerima <code>{"emails": [ … ]}</code> berisi 1–100 objek dengan skema yang sama seperti <code>POST /v1/emails</code>. Respons <code>207 Multi-Status</code> memuat hasil per item sehingga satu item gagal tidak membatalkan yang lain. Satu <code>Idempotency-Key</code> berlaku untuk seluruh batch (diturunkan per indeks).</p>
    <x-code title="Respons 207" lang="json">@verbatim{ "results": [
  { "index": 0, "status": 202, "data": { "id": "0192…", "status": "queued" } },
  { "index": 1, "status": 422, "error": { "code": "validation_failed", "details": { "to.0": ["…"] } } }
] }@endverbatim</x-code>
</section>

<section id="lampiran" data-doc="Lampiran" data-group="Mengirim Email">
    <h2>Lampiran</h2>
    <x-code title="attachments" lang="json">@verbatim"attachments": [
  { "filename": "invoice.pdf", "content": "<base64>", "content_type": "application/pdf" }
]@endverbatim</x-code>
    <ul>
        <li>Maks 10 lampiran; total payload maks 25 MB. Konten harus <b>base64</b> valid.</li>
        <li>Tipe berbahaya (<code>.exe .bat .cmd .scr .js .vbs .jar .msi .dll</code>) ditolak pada jalur SMTP.</li>
    </ul>
</section>

<section id="idempotency" data-doc="Idempotency" data-group="Mengirim Email">
    <h2>Idempotency</h2>
    <p>Kirim header <code>Idempotency-Key: &lt;nilai unik&gt;</code> agar retry jaringan tidak menghasilkan email ganda.</p>
    <ul>
        <li>Key sama + body sama → <code>200</code> dengan respons email yang sudah dibuat (tidak dikirim lagi).</li>
        <li>Key sama + body berbeda → <code>409 conflict</code>.</li>
        <li>Key berlaku per organisasi. Gunakan identitas kejadian, mis. <code>otp-{user_id}-{timestamp}</code>.</li>
    </ul>
</section>

<section id="jadwal-tag" data-doc="Jadwal, tag, metadata" data-group="Mengirim Email">
    <h2>Jadwal, tag, metadata</h2>
    <ul>
        <li><code>scheduled_at</code> — kirim nanti. Email terjadwal masih bisa dibatalkan dengan <code>POST /v1/emails/{id}/cancel</code> selama berstatus <code>QUEUED</code>.</li>
        <li><code>tags</code> — hingga 10 label (mis. <code>["otp"]</code>) untuk memfilter: <code>GET /v1/emails?tag=otp</code>.</li>
        <li><code>metadata</code> — pasangan kunci-nilai bebas (mis. <code>{"user_id":"8812"}</code>) yang dikembalikan di respons dan di setiap payload webhook. Jangan menaruh data sensitif.</li>
    </ul>
</section>

<section id="tracking" data-doc="Open & click tracking" data-group="Mengirim Email">
    <h2>Open &amp; click tracking</h2>
    <p>Tracking bersifat <b>opt-in</b>. Aktifkan per email (<code>track_opens</code>, <code>track_clicks</code>) atau sebagai bawaan per domain di console.</p>
    <ul>
        <li><b>Click</b> — link <code>http(s)</code> ditulis ulang ke <code>/c/&lt;token&gt;</code> lalu dialihkan (302). Link berhenti berlangganan milik sistem tidak ikut ditulis ulang.</li>
        <li><b>Open</b> — piksel GIF 1×1 di <code>/o/&lt;token&gt;.gif</code>.</li>
        <li>Pembuka otomatis (proxy privasi, scanner keamanan, crawler) terdeteksi dan diberi tanda <code>is_bot</code> — jangan menganggapnya pembacaan manusia.</li>
        <li>Pasang CNAME <code>trk.{domain}</code> agar link memakai domain Anda.</li>
    </ul>
    <x-callout type="danger" title="Jangan untuk OTP & reset password">Menulis ulang link verifikasi menambah satu hop pihak ketiga dan bisa memicu filter spam. Biarkan <code>track_clicks</code> mati untuk email sensitif.</x-callout>
</section>
