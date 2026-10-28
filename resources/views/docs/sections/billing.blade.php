<section id="paket" data-doc="Paket & batas" data-group="Paket & Penagihan">
    <h2>Paket &amp; batas</h2>
    <div class="overflow-x-auto">
    <table>
        <thead><tr><th>Paket</th><th>Harga / bulan</th><th>Kuota email / bulan</th><th>Batas harian</th></tr></thead>
        <tbody>
            @foreach(\App\Support\Plans::active() as $p)
                <tr><td><b>{{ $p['name'] }}</b></td><td>{{ $p['price'] ? 'Rp '.number_format($p['price'], 0, ',', '.') : 'Gratis' }}</td><td>{{ number_format($p['monthly_quota']) }}</td><td>{{ $p['daily_limit'] ? number_format($p['daily_limit']) : 'Tanpa batas' }}</td></tr>
            @endforeach
        </tbody>
    </table>
    </div>
    <p>Kuota direset setiap awal bulan kalender. Batas per pesan: 25 MB, 50 penerima. Bila paket berbayar berakhir tanpa perpanjangan, akun otomatis kembali ke Free — data, domain, dan API key tetap aman.</p>
</section>

<section id="penagihan" data-doc="Checkout & pembayaran" data-group="Paket & Penagihan">
    <h2>Checkout &amp; pembayaran</h2>
    <ol>
        <li>Buka <b>Billing</b> di console, pilih paket, tentukan durasi (1/3/6/12 bulan).</li>
        <li>Klik <b>Buat tagihan &amp; bayar</b> — Anda diarahkan ke halaman pembayaran aman <b>Xendit</b>.</li>
        <li>Pilih metode: QRIS, virtual account (BCA, BNI, BRI, Mandiri, Permata, CIMB), Indomaret/Alfamart, atau e-wallet.</li>
        <li>Setelah dibayar, paket aktif <b>otomatis</b> (lewat webhook; halaman tagihan juga memeriksa status langsung ke Xendit saat Anda kembali).</li>
    </ol>
    <ul>
        <li>Tagihan berlaku 24 jam. Yang kedaluwarsa bisa dibuat ulang tanpa biaya.</li>
        <li><b>Perpanjangan</b> paket yang sama menyambung dari tanggal berakhir; <b>ganti paket</b> berlaku dari saat pembayaran.</li>
        <li>Hanya <b>owner</b> dan <b>admin</b> organisasi yang dapat membeli.</li>
        <li>Anda menerima email saat tagihan dibuat, saat pembayaran diterima, dan sebelum paket berakhir (7 dan 1 hari).</li>
    </ul>
</section>

<section id="notifikasi" data-doc="Email dari Postivo" data-group="Paket & Penagihan">
    <h2>Email dari Postivo</h2>
    <p>Email sistem dikirim dari <code>noreply@postivo.my.id</code>; balasan diarahkan ke <code>{{ config('postivo.support_email') }}</code>. Pastikan keduanya tidak masuk filter spam Anda.</p>
    <div class="overflow-x-auto">
    <table>
        <thead><tr><th>Email</th><th>Kapan dikirim</th><th>Penerima</th></tr></thead>
        <tbody>
            <tr><td>Selamat datang</td><td>Setelah pendaftaran</td><td>Pendaftar</td></tr>
            <tr><td>Verifikasi email</td><td>Pendaftaran / kirim ulang</td><td>Pendaftar</td></tr>
            <tr><td>Atur ulang kata sandi</td><td>Lupa kata sandi</td><td>Pemilik akun</td></tr>
            <tr><td>Undangan tim</td><td>Admin mengundang anggota</td><td>Orang yang diundang</td></tr>
            <tr><td>API key baru</td><td>Key dibuat (peringatan keamanan)</td><td>Pembuat key</td></tr>
            <tr><td>Domain terverifikasi / bermasalah</td><td>Status domain berubah</td><td>Owner &amp; admin</td></tr>
            <tr><td>Kuota 80% / 100%</td><td>Pemakaian bulanan mencapai ambang</td><td>Owner &amp; admin</td></tr>
            <tr><td>Webhook dinonaktifkan</td><td>{{ config('postivo.webhook.disable_after') }} kegagalan beruntun</td><td>Owner &amp; admin</td></tr>
            <tr><td>Tagihan dibuat / pembayaran diterima</td><td>Checkout dan konfirmasi Xendit</td><td>Owner &amp; admin / pembayar</td></tr>
            <tr><td>Paket akan berakhir / berakhir</td><td>7 hari, 1 hari, dan saat berakhir</td><td>Owner &amp; admin</td></tr>
        </tbody>
    </table>
    </div>
</section>

<section id="keamanan" data-doc="Keamanan & kepatuhan" data-group="Operasional">
    <h2>Keamanan &amp; kepatuhan</h2>
    <ul>
        <li><b>Transport:</b> TLS 1.2+ untuk API dan SMTP. API key disimpan sebagai hash; kunci DKIM privat dan secret webhook dienkripsi.</li>
        <li><b>Isolasi:</b> setiap organisasi hanya melihat data miliknya; key bisa dibatasi per domain.</li>
        <li><b>Retensi:</b> isi email (HTML/teks) dihapus setelah {{ config('postivo.retention_days.body') }} hari; event setelah {{ config('postivo.retention_days.events') }} hari. Metadata ringkas tetap ada untuk audit.</li>
        <li><b>Penggunaan yang diizinkan:</b> hanya email transaksional/berizin. Spam, daftar yang dibeli, phishing, dan konten ilegal berujung penangguhan akun.</li>
        <li><b>Melapor celah keamanan:</b> {{ config('postivo.support_email') }} (jangan sertakan data pelanggan).</li>
    </ul>
</section>

<section id="faq" data-doc="FAQ" data-group="Operasional">
    <h2>FAQ</h2>
    <h3>Respons 202 tapi email tidak sampai?</h3>
    <p>202 hanya berarti diterima &amp; diantrekan. Buka detail email di console: timeline menunjukkan <code>sent</code>, <code>delivered</code>, atau <code>bounced</code> lengkap dengan respons SMTP server penerima. Cek juga folder spam dan pastikan SPF/DKIM/DMARC hijau.</p>
    <h3>Berapa lama email sampai?</h3>
    <p>Umumnya hitungan detik. Angka “&lt; 50 ms” pada materi kami hanya merujuk ke waktu API menerima &amp; mengantrekan, bukan ke waktu sampai di inbox.</p>
    <h3>Bisa mengirim dari domain tanpa subdomain?</h3>
    <p>Bisa, tetapi subdomain khusus disarankan: reputasi terpisah dan CNAME Return-Path/Tracking tidak berbenturan dengan record utama.</p>
    <h3>Email ke satu penerima ditolak <code>recipient_suppressed</code>?</h3>
    <p>Alamat itu pernah bounce keras, komplain, atau berhenti berlangganan. Hapus dari Suppressions hanya bila Anda yakin masalahnya sudah selesai.</p>
    <h3>Bagaimana menguji tanpa mengirim email sungguhan?</h3>
    <p>Gunakan API key <code>test</code> (sandbox): validasi penuh dan event tercatat, tetapi tidak ada email yang keluar dan kuota tidak terpakai.</p>
    <h3>Kontak</h3>
    <p>Email <a href="mailto:{{ config('postivo.support_email') }}">{{ config('postivo.support_email') }}</a> dengan menyertakan <code>X-Request-Id</code> atau ID email terkait.</p>
</section>
