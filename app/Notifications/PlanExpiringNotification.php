<?php

namespace App\Notifications;

use App\Models\Organization;

class PlanExpiringNotification extends BrandedNotification
{
    public function __construct(public Organization $org, public int $days)
    {
        parent::__construct();
    }

    protected function subject(): string
    {
        return 'Paket '.ucfirst($this->org->plan).' berakhir '.($this->days <= 1 ? 'besok' : 'dalam '.$this->days.' hari');
    }

    protected function lines(): array
    {
        return [
            'Paket **'.ucfirst($this->org->plan).'** organisasi **'.$this->org->name.'** berakhir pada '.$this->org->plan_expires_at->timezone('Asia/Jakarta')->format('d M Y').'.',
            'Perpanjang sekarang agar kuota dan batas pengiriman tidak turun ke paket Free.',
        ];
    }

    protected function action(): ?array
    {
        return ['Perpanjang paket', route('console.billing.index')];
    }
}
