@extends('layouts.legal')
@section('title', 'Kebijakan Penggunaan yang Dapat Diterima')
@section('content')
<p>{{ \App\Support\Brand::name() }} dibuat untuk email transaksional. Kebijakan ini menjaga reputasi pengiriman bersama agar email semua Pelanggan tetap sampai.</p>

<h2>Diizinkan</h2>
<ul>
    <li>Email yang dipicu tindakan penerima atau transaksi: verifikasi akun, reset kata sandi, struk, notifikasi, peringatan keamanan.</li>
    <li>Pengiriman hanya ke penerima yang alamatnya Anda peroleh secara sah.</li>
</ul>

<h2>Dilarang</h2>
<ul>
    <li>Spam, email massal tidak diminta, atau pembelian daftar alamat.</li>
    <li>Phishing, penipuan, malware, atau konten yang menyesatkan identitas pengirim.</li>
    <li>Konten ilegal, termasuk perjudian ilegal, pornografi, dan eksploitasi anak.</li>
    <li>Memalsukan domain atau pengirim yang bukan milik Anda.</li>
    <li>Mengabaikan permintaan berhenti berlangganan, bounce permanen, atau komplain.</li>
    <li>Menguji, memindai, atau mengganggu infrastruktur layanan tanpa izin tertulis.</li>
</ul>

<h2>Ambang kualitas</h2>
<p>Kami memantau kualitas otomatis. Tingkat bounce di atas {{ \App\Services\PlatformSettings::get('abuse_bounce_danger') }}% atau komplain di atas {{ \App\Services\PlatformSettings::get('abuse_complaint_danger') }}% (pada volume minimum) dapat memicu peringatan, pembatasan, atau penangguhan akun.</p>

<h2>Penindakan</h2>
<p>Pelanggaran dapat berujung pada peringatan, pembatasan kuota, penangguhan, atau penghentian akun tanpa pengembalian dana, dan pelaporan ke pihak berwenang bila diperlukan.</p>

<h2>Laporkan penyalahgunaan</h2>
<p>Terima email yang melanggar kebijakan ini? Kirim header lengkap email tersebut ke {{ config('postivo.support_email') }} dengan subjek "Abuse". Kami menanggapi laporan dalam 1 hari kerja.</p>
@endsection
