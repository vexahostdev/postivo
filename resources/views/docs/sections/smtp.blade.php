<section id="smtp-kredensial" data-doc="Kredensial & port SMTP" data-group="SMTP Relay">
    <h2>Kredensial &amp; port SMTP</h2>
    <p>Pakai Postivo sebagai relay untuk aplikasi/CMS yang hanya mendukung SMTP (WordPress, Laravel Mail, Nodemailer, dst.).</p>
    <table>
        <tbody>
            <tr><th>Host</th><td><code>{{ config('postivo.smtp.host') }}</code></td></tr>
            <tr><th>Port</th><td><code>587</code> STARTTLS (disarankan) · <code>465</code> TLS implisit · <code>2525</code> STARTTLS (alternatif bila 587 diblokir)</td></tr>
            <tr><th>TLS</th><td>Wajib TLS 1.2+. AUTH hanya diterima setelah koneksi terenkripsi.</td></tr>
            <tr><th>Username</th><td>Bebas (disarankan <code>postivo</code>)</td></tr>
            <tr><th>Password</th><td>API key aktif dengan scope <code>emails:send</code></td></tr>
            <tr><th>Batas</th><td>25 MB per pesan · 50 penerima per transaksi</td></tr>
        </tbody>
    </table>
    <ul>
        <li>Domain pengirim ditentukan oleh header <code>From</code>; <code>MAIL FROM</code> amplop diabaikan (Postivo memakai Return-Path sendiri untuk menangkap bounce).</li>
        <li>Penerima di amplop yang tidak tercantum di header <code>To</code>/<code>Cc</code> diperlakukan sebagai <b>Bcc</b>.</li>
        <li>10 kegagalan autentikasi dalam 10 menit dari satu IP memblokir sementara IP tersebut.</li>
    </ul>
</section>

<section id="smtp-kode" data-doc="Kode balasan SMTP" data-group="SMTP Relay">
    <h2>Kode balasan SMTP</h2>
    <p>Pesan dianggap diterima (<code>250</code>) hanya setelah tersimpan dan masuk antrean. Karena validasi berjalan sebelum balasan dikirim, penolakan datang dengan kode yang tepat.</p>
    <table>
        <thead><tr><th>Kode</th><th>Arti</th><th>Tindakan klien</th></tr></thead>
        <tbody>
            <tr><td><code>235</code></td><td>Autentikasi berhasil</td><td>—</td></tr>
            <tr><td><code>250 2.0.0 Ok: queued as &lt;id&gt;</code></td><td>Diterima &amp; diantrekan</td><td>—</td></tr>
            <tr><td><code>535 5.7.8</code></td><td>API key salah / dicabut / scope kurang</td><td>Periksa key</td></tr>
            <tr><td><code>550 5.7.1</code></td><td>Domain <code>From</code> tidak Active atau bukan milik organisasi</td><td>Verifikasi domain</td></tr>
            <tr><td><code>550 5.1.1</code></td><td>Semua penerima ada di suppression list</td><td>Jangan retry</td></tr>
            <tr><td><code>552 5.3.4</code></td><td>Pesan melebihi 25 MB</td><td>Perkecil lampiran</td></tr>
            <tr><td><code>554 5.6.0</code></td><td>Isi tidak valid (tanpa From/body, lampiran terlarang)</td><td>Perbaiki pesan</td></tr>
            <tr><td><code>452 4.5.3</code></td><td>Kuota/batas harian habis atau terlalu banyak penerima</td><td>Retry nanti / upgrade paket</td></tr>
            <tr><td><code>451 4.3.0</code></td><td>Gangguan sementara di sisi Postivo</td><td>Klien akan retry otomatis</td></tr>
            <tr><td><code>421 4.7.0</code></td><td>Terlalu banyak gagal autentikasi</td><td>Tunggu ±10 menit</td></tr>
        </tbody>
    </table>
</section>

<section id="smtp-contoh" data-doc="Contoh koneksi SMTP" data-group="SMTP Relay">
    <h2>Contoh koneksi</h2>
    <x-code title="Nodemailer">@verbatimconst transporter = nodemailer.createTransport({
  host: 'smtp.postivo.my.id',
  port: 587,
  secure: false,          // STARTTLS
  requireTLS: true,
  auth: { user: 'postivo', pass: process.env.POSTIVO_API_KEY },
});@endverbatim</x-code>
    <x-code title="Laravel .env">@verbatimMAIL_MAILER=smtp
MAIL_HOST=smtp.postivo.my.id
MAIL_PORT=587
MAIL_USERNAME=postivo
MAIL_PASSWORD=psv_live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
MAIL_FROM_ADDRESS=noreply@mail.contoh.com@endverbatim</x-code>
    <x-code title="Python (smtplib)">@verbatimimport smtplib, ssl
from email.message import EmailMessage

msg = EmailMessage()
msg["From"], msg["To"], msg["Subject"] = "Acme <noreply@mail.contoh.com>", "user@example.com", "Halo"
msg.set_content("Tes SMTP")

with smtplib.SMTP("smtp.postivo.my.id", 587) as s:
    s.starttls(context=ssl.create_default_context())
    s.login("postivo", "psv_live_…")
    s.send_message(msg)@endverbatim</x-code>
</section>
