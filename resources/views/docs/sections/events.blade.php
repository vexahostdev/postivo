<section id="status-email" data-doc="Siklus status" data-group="Status & Event">
    <h2>Siklus status</h2>
    <p>Setiap penerima punya satu status. Status hanya <b>maju</b>: event yang datang terlambat tidak bisa memundurkannya (mis. <code>delivered</code> setelah <code>bounced</code> diabaikan).</p>
    <div class="overflow-x-auto">
    <table>
        <thead><tr><th>Status</th><th>Arti</th></tr></thead>
        <tbody>
            <tr><td><code>QUEUED</code></td><td>Diterima, menunggu giliran kirim (atau waktu <code>scheduled_at</code>).</td></tr>
            <tr><td><code>SENDING</code></td><td>Sedang diserahkan ke relay.</td></tr>
            <tr><td><code>DEFERRED</code></td><td>Gangguan sementara; akan dicoba ulang dengan jeda 1m, 5m, 15m, 1j, 3j, 6j, 12j.</td></tr>
            <tr><td><code>SENT</code></td><td>Relay menerima pesan.</td></tr>
            <tr><td><code>DELIVERED</code></td><td>Server penerima menerima pesan (balasan 250).</td></tr>
            <tr><td><code>BOUNCED</code></td><td>Ditolak permanen oleh server penerima.</td></tr>
            <tr><td><code>COMPLAINED</code></td><td>Penerima menandai sebagai spam.</td></tr>
            <tr><td><code>FAILED</code></td><td>Gagal setelah semua retry, dibatalkan, atau kesalahan internal.</td></tr>
            <tr><td><code>SUPPRESSED</code></td><td>Dilewati karena ada di suppression list.</td></tr>
        </tbody>
    </table>
    </div>
    <p><code>opened</code>, <code>clicked</code>, dan <code>unsubscribed</code> adalah <b>event</b>, bukan status — satu email bisa berstatus <code>DELIVERED</code> dan tetap menerima event <code>opened</code> berkali-kali.</p>
</section>

<section id="bounce" data-doc="Bounce & komplain" data-group="Status & Event">
    <h2>Bounce &amp; komplain</h2>
    <div class="overflow-x-auto">
    <table>
        <thead><tr><th>Jenis</th><th>Penyebab</th><th>Perlakuan</th></tr></thead>
        <tbody>
            <tr><td><code>HARD</code></td><td>Alamat tidak ada / domain tidak valid (5xx)</td><td>Status <code>BOUNCED</code>; alamat masuk suppression.</td></tr>
            <tr><td><code>SOFT</code></td><td>Kotak penuh, gangguan sementara (4xx)</td><td>Dicoba ulang; tidak di-suppress.</td></tr>
            <tr><td><code>BLOCK</code></td><td>Ditolak karena reputasi/kebijakan pengirim</td><td>Status <code>BOUNCED</code> namun alamat <b>tidak</b> di-suppress (bukan salah alamat).</td></tr>
            <tr><td>Komplain</td><td>Penerima menekan “spam”</td><td>Status <code>COMPLAINED</code>; alamat di-suppress.</td></tr>
        </tbody>
    </table>
    </div>
    <x-callout type="warn" title="Jaga reputasi">Bounce &gt; 2% atau komplain &gt; 0,1% merusak reputasi domain. Jangan mengirim ke daftar yang dibeli atau tidak berizin.</x-callout>
</section>

<section id="suppression" data-doc="Suppression list" data-group="Status & Event">
    <h2>Suppression list</h2>
    <p>Daftar alamat yang tidak akan dikirimi email dari organisasi Anda. Terisi otomatis oleh <code>HARD_BOUNCE</code>, <code>COMPLAINT</code>, dan <code>UNSUBSCRIBE</code>; Anda juga bisa menambah manual (<code>MANUAL</code>) atau menghapus entri bila perlu (console atau <code>/v1/suppressions</code>).</p>
    <p>Pencocokan memakai alamat huruf kecil; titik dan <code>+tag</code> pada Gmail <b>tidak</b> dinormalisasi.</p>
    <p><b>Berhenti berlangganan:</b> sertakan header <code>List-Unsubscribe</code> Anda sendiri lewat field <code>headers</code>. Tautan <code>/u/&lt;token&gt;</code> milik Postivo mendukung one-click (RFC 8058) dan langsung men-suppress penerima.</p>
</section>

<section id="webhook-event" data-doc="Webhook: event & payload" data-group="Webhook">
    <h2>Event &amp; payload</h2>
    <p>Buat endpoint di <b>Webhooks</b> (HTTPS publik) dan pilih event. Postivo mengirim <code>POST</code> JSON setiap ada perubahan.</p>
    <p>Tipe event: <code>email.queued</code> <code>email.sent</code> <code>email.delivered</code> <code>email.deferred</code> <code>email.bounced</code> <code>email.complained</code> <code>email.failed</code> <code>email.suppressed</code> <code>email.opened</code> <code>email.clicked</code> <code>email.unsubscribed</code>, atau <code>*</code> untuk semuanya.</p>
    <x-code title="Payload" lang="json">@verbatim{
  "id": "evt_0192…",
  "type": "email.delivered",
  "created_at": "2026-10-27T08:15:32Z",
  "data": {
    "email_id": "0192…", "dispatch_id": "0192…",
    "recipient": "user@example.com", "domain": "mail.contoh.com",
    "tags": ["otp"], "metadata": { "user_id": "8812" },
    "smtp_response": "250 2.0.0 OK", "bounce": null
  }
}@endverbatim</x-code>
    <p>Header: <code>Postivo-Signature</code>, <code>Postivo-Event-Id</code>, <code>User-Agent: Postivo-Webhook/1.0</code>. Endpoint harus membalas <code>2xx</code> dalam 10 detik. Pengiriman bersifat <b>at-least-once</b> — jadikan handler idempoten pada <code>Postivo-Event-Id</code>.</p>
    <x-callout title="Keamanan URL">Target harus mengarah ke alamat publik. <code>localhost</code>, jaringan privat, dan redirect ditolak (proteksi SSRF). Untuk uji lokal, gunakan tunnel HTTPS.</x-callout>
</section>

<section id="webhook-signature" data-doc="Verifikasi tanda tangan webhook" data-group="Webhook">
    <h2>Verifikasi tanda tangan</h2>
    <p>Header <code>Postivo-Signature: t=&lt;unix&gt;,v1=&lt;hex&gt;</code>, dengan <code>v1 = HMAC_SHA256(secret, "{t}.{raw_body}")</code>. Secret berformat <code>whsec_…</code> dan hanya tampil sekali saat endpoint dibuat. Gunakan <b>body mentah</b> (sebelum di-parse), bandingkan dengan waktu konstan, dan tolak bila <code>|sekarang − t| &gt; 5 menit</code> (anti-replay).</p>
    <x-code title="Node.js (Express)">@verbatimimport crypto from 'node:crypto';

app.post('/webhooks/postivo', express.raw({ type: 'application/json' }), (req, res) => {
  const [t, v1] = req.get('Postivo-Signature').split(',').map((p) => p.split('=')[1]);
  if (Math.abs(Date.now() / 1000 - Number(t)) > 300) return res.sendStatus(400);

  const expected = crypto.createHmac('sha256', process.env.POSTIVO_WEBHOOK_SECRET)
    .update(`${t}.${req.body.toString('utf8')}`).digest('hex');
  const ok = expected.length === v1.length &&
    crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(v1));
  if (!ok) return res.sendStatus(401);

  const event = JSON.parse(req.body);   // proses idempoten berdasarkan event.id
  res.sendStatus(200);
});@endverbatim</x-code>
    <x-code title="PHP">@verbatim$header = $request->header('Postivo-Signature');           // "t=…,v1=…"
parse_str(str_replace(',', '&', $header), $p);
$raw = $request->getContent();

abort_if(abs(time() - (int) $p['t']) > 300, 400);
$expected = hash_hmac('sha256', $p['t'].'.'.$raw, config('services.postivo.webhook_secret'));
abort_unless(hash_equals($expected, $p['v1']), 401);@endverbatim</x-code>
    <x-code title="Python (Flask)">@verbatimimport hmac, hashlib, time
from flask import request, abort

parts = dict(x.split("=", 1) for x in request.headers["Postivo-Signature"].split(","))
raw = request.get_data()
if abs(time.time() - int(parts["t"])) > 300: abort(400)
expected = hmac.new(SECRET.encode(), f"{parts['t']}.".encode() + raw, hashlib.sha256).hexdigest()
if not hmac.compare_digest(expected, parts["v1"]): abort(401)@endverbatim</x-code>
</section>

<section id="webhook-retry" data-doc="Retry & auto-disable webhook" data-group="Webhook">
    <h2>Retry &amp; auto-disable</h2>
    <ul>
        <li>Respons non-2xx atau timeout dicoba ulang dengan jeda <b>30 dtk, 2 mnt, 10 mnt, 1 jam, 6 jam, 24 jam</b>.</li>
        <li>Setelah <b>{{ config('postivo.webhook.disable_after') }}</b> kegagalan beruntun, endpoint dinonaktifkan otomatis dan Anda diberi tahu lewat email. Aktifkan kembali di halaman Webhooks setelah memperbaiki endpoint.</li>
        <li>Riwayat pengiriman terakhir (status HTTP, durasi) tampil di console.</li>
    </ul>
</section>
