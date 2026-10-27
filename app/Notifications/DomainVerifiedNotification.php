<?php

namespace App\Notifications;

use App\Models\Domain;

class DomainVerifiedNotification extends BrandedNotification
{
    public function __construct(public Domain $domain)
    {
        parent::__construct();
    }

    protected function subject(): string
    {
        return 'Domain '.$this->domain->domain_name.' terverifikasi';
    }

    protected function lines(): array
    {
        return ['Semua record DNS wajib untuk **'.$this->domain->domain_name.'** sudah terdeteksi. Domain kini berstatus **Active** dan siap mengirim.'];
    }

    protected function action(): ?array
    {
        return ['Lihat domain', route('console.domains.show', $this->domain->id)];
    }
}
