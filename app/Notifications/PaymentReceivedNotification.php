<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\Organization;

class PaymentReceivedNotification extends BrandedNotification
{
    public function __construct(public Order $order)
    {
        parent::__construct();
    }

    protected function subject(): string
    {
        return 'Pembayaran diterima — paket '.$this->order->planName().' aktif';
    }

    protected function lines(): array
    {
        $until = Organization::find($this->order->organization_id)?->plan_expires_at?->timezone('Asia/Jakarta')->format('d M Y');

        return [
            'Terima kasih! Pembayaran **Rp '.number_format($this->order->amount, 0, ',', '.').'** (ref '.$this->order->external_id.') sudah kami terima.',
            'Paket **'.$this->order->planName().'** aktif sampai '.$until.'.',
        ];
    }

    protected function action(): ?array
    {
        return ['Lihat tagihan', route('console.billing.orders.show', $this->order->id)];
    }
}
