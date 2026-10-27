<?php

namespace App\Notifications;

use App\Models\Organization;

class QuotaWarningNotification extends BrandedNotification
{
    public function __construct(public Organization $org, public int $percent)
    {
        parent::__construct();
    }

    protected function subject(): string
    {
        return $this->percent >= 100 ? 'Kuota email bulanan habis' : 'Kuota email bulanan terpakai '.$this->percent.'%';
    }

    protected function lines(): array
    {
        return [
            'Organisasi **'.$this->org->name.'** sudah memakai **'.$this->percent.'%** dari kuota '.number_format($this->org->monthly_quota).' email bulan ini.',
            $this->percent >= 100
                ? 'Pengiriman baru ditolak (429) sampai bulan berikutnya atau sampai paket ditingkatkan.'
                : 'Tingkatkan paket sebelum kuota habis agar pengiriman tidak terhenti.',
        ];
    }

    protected function action(): ?array
    {
        return ['Tingkatkan paket', route('console.billing.index')];
    }
}
