<?php

namespace App\Notifications;

class WelcomeNotification extends BrandedNotification
{
    protected function subject(): string
    {
        return 'Selamat datang di Postivo';
    }

    protected function lines(): array
    {
        return [
            'Akun Anda sudah aktif dengan paket Free (3.000 email/bulan).',
            'Langkah berikutnya: tambahkan domain pengirim, pasang record DNS, lalu buat API key.',
        ];
    }

    protected function action(): ?array
    {
        return ['Buka console', route('console.dashboard')];
    }
}
