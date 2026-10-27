@extends('layouts.legal')
@section('title', 'Kebijakan Privasi')
@section('content')
<p>Kebijakan ini menjelaskan data apa yang {{ \App\Support\Brand::name() }} (dioperasikan PT Destinara) kumpulkan, untuk apa, dan berapa lama disimpan, sejalan dengan UU Pelindungan Data Pribadi (UU No. 27 Tahun 2022).</p>

<h2>1. Peran kami</h2>
<ul>
    <li><b>Data akun Pelanggan</b> (nama, email, organisasi, penagihan): Kami adalah pengendali data.</li>
    <li><b>Data penerima email Pelanggan</b> (alamat penerima, isi email, status pengiriman): Pelanggan adalah pengendali data dan Kami adalah pemroses data yang bertindak atas instruksi Pelanggan.</li>
</ul>

<h2>2. Data yang kami proses</h2>
<ul>
    <li>Akun: nama, alamat email, kata sandi (disimpan ter-hash), pengaturan 2FA.</li>
    <li>Penagihan: riwayat order dan status pembayaran. Data kartu atau rekening diproses langsung oleh Xendit dan tidak kami simpan.</li>
    <li>Pengiriman: alamat pengirim dan penerima, subjek, isi dan lampiran email, status (terkirim, bounce, komplain), serta event buka dan klik bila tracking diaktifkan.</li>
    <li>Teknis: alamat IP, user agent, dan log audit untuk keamanan dan pencegahan penyalahgunaan.</li>
</ul>

<h2>3. Tujuan</h2>
<p>Menyediakan dan mengamankan layanan, menagih, mencegah spam dan penipuan, memenuhi kewajiban hukum, serta mengirim notifikasi layanan (verifikasi, peringatan kuota, tagihan).</p>

<h2>4. Retensi</h2>
<ul>
    <li>Isi (body) email dihapus setelah {{ config('postivo.retention_days.body') }} hari; event pengiriman setelah {{ config('postivo.retention_days.events') }} hari.</li>
    <li>Metadata penagihan disimpan sesuai kewajiban pembukuan dan perpajakan.</li>
    <li>Setelah akun dihapus, data akun dihapus atau dianonimkan kecuali yang wajib kami simpan menurut hukum.</li>
</ul>

<h2>5. Berbagi data</h2>
<p>Kami tidak menjual data. Data dibagikan hanya kepada sub-prosesor yang diperlukan: penyedia relay email (mis. Brevo), penyedia pembayaran (Xendit), dan penyedia infrastruktur hosting. Kami dapat mengungkapkan data bila diwajibkan hukum.</p>

<h2>6. Keamanan</h2>
<p>Koneksi dienkripsi (TLS), kunci privat DKIM dan secret webhook dienkripsi, API key hanya disimpan sebagai hash, dan akses staf platform memakai 2FA serta dicatat di log audit.</p>

<h2>7. Hak Anda</h2>
<p>Anda berhak mengakses, memperbaiki, menghapus, dan memindahkan data pribadi Anda, serta menarik persetujuan. Ajukan permintaan ke {{ config('postivo.support_email') }}. Untuk data penerima email, hubungi Pelanggan yang mengirimnya.</p>

<h2>8. Kontak</h2>
<p>PT Destinara · {{ config('postivo.support_email') }}</p>
@endsection
