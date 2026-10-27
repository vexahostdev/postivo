<?php

namespace App\Services;

use App\Models\Domain;
use Symfony\Component\Mime\Crypto\DkimSigner;
use Symfony\Component\Mime\Email as MimeEmail;
use Symfony\Component\Mime\Message;

class DkimService
{
    /**
     * Selalu langkah TERAKHIR sebelum kirim: header yang ditambahkan setelah ini tidak
     * tercakup di h= dan bisa membuat verifikasi gagal bila relay memodifikasinya.
     */
    public function sign(MimeEmail $message, Domain $domain): Message
    {
        $signer = new DkimSigner($domain->dkim_private_key, $domain->domain_name, $domain->dkim_selector, [
            'algorithm' => 'rsa-sha256',
            'header_canon' => 'relaxed',
            'body_canon' => 'relaxed',
        ]);

        return $signer->sign($message);
    }
}
