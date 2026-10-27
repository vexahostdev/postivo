<?php

namespace App\Notifications;

use App\Models\Organization;

class OrganizationSuspendedNotification extends BrandedNotification
{
    public function __construct(public Organization $org, public string $reason)
    {
        parent::__construct();
    }

    protected function subject(): string
    {
        return 'Akun '.$this->org->name.' ditangguhkan';
    }

    protected function lines(): array
    {
        return [
            'Pengiriman dari organisasi **'.$this->org->name.'** dihentikan sementara.',
            'Alasan: '.$this->reason,
            'Balas email ini untuk meninjau penangguhan. Data dan konfigurasi Anda tetap tersimpan.',
        ];
    }
}
