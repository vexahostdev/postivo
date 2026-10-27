<?php

namespace App\Notifications;

use App\Models\Organization;

class PlanExpiredNotification extends BrandedNotification
{
    public function __construct(public Organization $org, public string $oldPlan)
    {
        parent::__construct();
    }

    protected function subject(): string
    {
        return 'Paket '.ucfirst($this->oldPlan).' berakhir — kembali ke Free';
    }

    protected function lines(): array
    {
        return [
            'Paket **'.ucfirst($this->oldPlan).'** organisasi **'.$this->org->name.'** telah berakhir. Akun diturunkan ke Free (3.000 email/bulan, 100/hari).',
            'Data, domain, dan API key Anda tetap aman. Perpanjang kapan saja untuk kembali ke kuota semula.',
        ];
    }

    protected function action(): ?array
    {
        return ['Pilih paket', route('console.billing.index')];
    }
}
