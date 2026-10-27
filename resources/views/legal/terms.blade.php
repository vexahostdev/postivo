@extends('layouts.legal')
@section('title', 'Syarat Layanan')
@section('content')
<p>Syarat ini mengatur penggunaan layanan {{ \App\Support\Brand::name() }} (REST API dan SMTP relay email transaksional) yang dioperasikan PT Destinara ("Kami"). Dengan membuat akun atau memakai API key, Anda ("Pelanggan") menyetujui syarat ini.</p>

<h2>1. Akun dan keamanan</h2>
<ul>
    <li>Anda wajib memberikan data yang benar dan menjaga kerahasiaan kata sandi serta API key. Aktivitas yang memakai kredensial Anda menjadi tanggung jawab Anda.</li>
    <li>Segera hubungi {{ config('postivo.support_email') }} bila kredensial bocor agar dapat dicabut.</li>
</ul>

<h2>2. Layanan</h2>
<ul>
    <li>Kami mengirimkan email transaksional atas nama domain Anda yang telah terverifikasi DNS-nya (SPF, DKIM, DMARC).</li>
    <li>Kuota, batas harian, dan fitur mengikuti paket yang aktif. Kami dapat mengubah paket dan harga dengan pemberitahuan wajar; perubahan tidak berlaku surut untuk periode yang sudah dibayar.</li>
    <li>Kami berupaya menjaga ketersediaan layanan, namun tidak menjamin pengiriman tanpa gangguan atau penerimaan di setiap kotak masuk penerima.</li>
</ul>

<h2>3. Pembayaran</h2>
<ul>
    <li>Paket berbayar ditagih per periode melalui penyedia pembayaran pihak ketiga (Xendit). Pajak yang berlaku ditanggung Pelanggan.</li>
    <li>Pembayaran yang sudah berhasil tidak dapat dikembalikan kecuali ditentukan lain secara tertulis atau diwajibkan hukum.</li>
    <li>Bila paket berakhir tanpa perpanjangan, akun diturunkan ke paket Free.</li>
</ul>

<h2>4. Penggunaan yang dapat diterima</h2>
<p>Anda wajib mematuhi <a href="{{ route('legal.aup') }}">Kebijakan Penggunaan yang Dapat Diterima</a>. Pelanggaran dapat berujung pada pembatasan atau penangguhan akun tanpa pengembalian dana.</p>

<h2>5. Penangguhan dan penghentian</h2>
<ul>
    <li>Kami dapat menangguhkan atau menghentikan akun segera bila ada indikasi penyalahgunaan, tingkat bounce atau komplain melebihi ambang, risiko keamanan, atau perintah hukum.</li>
    <li>Anda dapat berhenti kapan saja. Data dihapus sesuai <a href="{{ route('legal.privacy') }}">Kebijakan Privasi</a>.</li>
</ul>

<h2>6. Batasan tanggung jawab</h2>
<p>Sejauh diizinkan hukum, tanggung jawab total Kami atas klaim apa pun terbatas pada jumlah yang Anda bayarkan dalam tiga bulan terakhir. Kami tidak bertanggung jawab atas kerugian tidak langsung, kehilangan keuntungan, atau email yang tidak sampai akibat kebijakan penyedia email penerima.</p>

<h2>7. Perubahan syarat</h2>
<p>Kami dapat memperbarui syarat ini; perubahan material diberitahukan lewat email atau banner di console. Penggunaan setelah tanggal berlaku berarti Anda menyetujui perubahan.</p>

<h2>8. Hukum yang berlaku</h2>
<p>Syarat ini tunduk pada hukum Republik Indonesia. Sengketa diupayakan diselesaikan secara musyawarah sebelum ditempuh jalur hukum.</p>

<p class="text-sm text-muted">Pertanyaan: {{ config('postivo.support_email') }}</p>
@endsection
