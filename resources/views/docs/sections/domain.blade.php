<section id="tambah-domain" data-doc="Menambah domain" data-group="Domain & DNS">
    <h2>Menambah domain</h2>
    <p>Email hanya bisa dikirim dari domain milik organisasi Anda yang berstatus <b>Active</b>. Domain diambil dari alamat pada header <code>From</code>.</p>
    <ul>
        <li>Lewat console: <b>Domains → Tambah</b>. Lewat API: <code>POST /v1/domains</code> (scope <code>domains:manage</code>).</li>
        <li>Gunakan <b>subdomain khusus</b> (mis. <code>mail.contoh.com</code>) agar reputasi pengiriman transaksional terpisah dari domain utama.</li>
        <li>Postivo membuat pasangan kunci DKIM RSA-2048 per domain. Kunci privat disimpan terenkripsi dan tidak pernah ditampilkan.</li>
    </ul>
    <x-code title="POST /v1/domains">@verbatimcurl https://api.postivo.my.id/v1/domains \
  -H "Authorization: Bearer $POSTIVO_API_KEY" -H "Content-Type: application/json" \
  -d '{"domain":"mail.contoh.com"}'@endverbatim</x-code>
</section>

<section id="record-dns" data-doc="Lima record DNS" data-group="Domain & DNS">
    <h2>Lima record DNS</h2>
    <p>Pasang record berikut di penyedia DNS domain Anda (Cloudflare, Niagahoster, dsb.). Nilai persisnya tampil di halaman domain — tombol salin tersedia.</p>
    <div class="overflow-x-auto">
    <table>
        <thead><tr><th>Record</th><th>Tipe · Host</th><th>Fungsi</th><th>Status</th></tr></thead>
        <tbody>
            <tr><td><b>DKIM</b></td><td><code>TXT</code> · <code>postivo._domainkey.{domain}</code></td><td>Bukti email benar-benar ditandatangani domain Anda</td><td>Wajib</td></tr>
            <tr><td><b>SPF</b></td><td><code>TXT</code> · <code>{domain}</code></td><td>Mengizinkan server Postivo: <code>v=spf1 include:{{ config('postivo.dns.spf_include') }} ~all</code></td><td>Wajib</td></tr>
            <tr><td><b>Return-Path</b></td><td><code>CNAME</code> · <code>bounces.{domain}</code> → <code>{{ config('postivo.dns.bounce_target') }}</code></td><td>Menerima bounce; membuat SPF/DMARC selaras</td><td>Wajib</td></tr>
            <tr><td><b>DMARC</b></td><td><code>TXT</code> · <code>_dmarc.{domain}</code></td><td>Kebijakan autentikasi; mulai dari <code>p=none</code></td><td>Disarankan</td></tr>
            <tr><td><b>Tracking</b></td><td><code>CNAME</code> · <code>trk.{domain}</code> → <code>{{ config('postivo.dns.tracking_target') }}</code></td><td>Link/piksel tracking di domain Anda sendiri</td><td>Opsional</td></tr>
        </tbody>
    </table>
    </div>
    <x-callout title="Sudah punya SPF?">Satu domain hanya boleh punya <b>satu</b> record SPF. Jika sudah ada, tambahkan <code>include:{{ config('postivo.dns.spf_include') }}</code> ke record yang ada — jangan membuat record kedua.</x-callout>
    <x-callout type="warn" title="DMARC bertahap">Mulai dengan <code>p=none</code> dan pantau laporan 2–4 minggu, baru naikkan ke <code>quarantine</code> lalu <code>reject</code>.</x-callout>
</section>

<section id="verifikasi-dns" data-doc="Verifikasi & status domain" data-group="Domain & DNS">
    <h2>Verifikasi &amp; status domain</h2>
    <p>Klik <b>Verifikasi sekarang</b> di console atau panggil <code>POST /v1/domains/{id}/verify</code>. Postivo juga mengaudit ulang semua domain otomatis setiap 15 menit.</p>
    <div class="overflow-x-auto">
    <table>
        <thead><tr><th>Status</th><th>Arti</th></tr></thead>
        <tbody>
            <tr><td><code>PENDING</code></td><td>Menunggu record wajib. Dicek otomatis; menjadi <code>FAILED</code> bila lewat 7 hari tanpa verifikasi.</td></tr>
            <tr><td><code>ACTIVE</code></td><td>DKIM, SPF, dan Return-Path lolos. Boleh mengirim.</td></tr>
            <tr><td><code>FAILED</code></td><td>Record wajib hilang/berubah. Pengiriman ditolak dengan <code>domain_not_verified</code>. Anda mendapat email peringatan.</td></tr>
            <tr><td><code>SUSPENDED</code></td><td>Dihentikan oleh Postivo (pelanggaran kebijakan). Hubungi support.</td></tr>
        </tbody>
    </table>
    </div>
    <p>Gangguan resolver DNS <b>tidak</b> dihitung sebagai record gagal: status record terakhir dipertahankan sampai pengecekan berikutnya berhasil.</p>
</section>

<section id="troubleshoot-dns" data-doc="Troubleshooting DNS" data-group="Domain & DNS">
    <h2>Troubleshooting DNS</h2>
    <ul>
        <li><b>Record sudah dipasang tapi belum terdeteksi</b> — propagasi bisa sampai beberapa jam. Kolom “Terdeteksi” di halaman domain menampilkan apa yang benar-benar terbaca.</li>
        <li><b>Penyedia DNS menambahkan domain otomatis</b> — banyak panel menambahkan <code>.{domain}</code> sendiri. Jika host Anda menjadi <code>postivo._domainkey.mail.contoh.com.mail.contoh.com</code>, isi hanya bagian depan (<code>postivo._domainkey</code>).</li>
        <li><b>Nilai DKIM terpotong</b> — kunci publik panjang; sebagian panel meminta nilai dipecah dalam tanda kutip. Hasil akhirnya harus tetap satu string utuh.</li>
        <li><b>CNAME tidak bisa di apex</b> — itulah alasan disarankan memakai subdomain.</li>
        <li><b>Cloudflare</b> — set record CNAME ke <i>DNS only</i> (awan abu-abu), bukan proxied.</li>
    </ul>
</section>
