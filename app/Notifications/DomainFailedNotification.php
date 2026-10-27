<?php

namespace App\Notifications;

use App\Models\Domain;

class DomainFailedNotification extends BrandedNotification
{
    public function __construct(public Domain $domain)
    {
        parent::__construct();
    }

    protected function subject(): string
    {
        return 'Perhatian: DNS '.$this->domain->domain_name.' bermasalah';
    }

    protected function lines(): array
    {
        return [
            'Audit DNS terakhir tidak menemukan record wajib untuk **'.$this->domain->domain_name.'**. Pengiriman dari domain ini ditolak sampai record dipulihkan.',
            'Biasanya penyebabnya record terhapus atau diganti di penyedia DNS.',
        ];
    }

    protected function action(): ?array
    {
        return ['Perbaiki record DNS', route('console.domains.show', $this->domain->id)];
    }
}
