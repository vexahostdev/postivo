<?php

namespace App\Notifications;

use App\Models\Order;

class InvoiceCreatedNotification extends BrandedNotification
{
    public function __construct(public Order $order)
    {
        parent::__construct();
    }

    protected function subject(): string
    {
        return 'Tagihan Postivo '.$this->order->planName().' menunggu pembayaran';
    }

    protected function lines(): array
    {
        return [
            'Tagihan paket **'.$this->order->planName().'** ('.$this->order->months.' bulan) sebesar **Rp '.number_format($this->order->amount, 0, ',', '.').'** sudah dibuat.',
            'Selesaikan pembayaran sebelum '.$this->order->expires_at->timezone('Asia/Jakarta')->format('d M Y H:i').' WIB. Paket aktif otomatis setelah pembayaran terkonfirmasi.',
        ];
    }

    protected function action(): ?array
    {
        return ['Bayar sekarang', $this->order->invoice_url ?: route('console.billing.orders.show', $this->order->id)];
    }
}
