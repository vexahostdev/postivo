<?php

namespace App\Notifications;

use App\Models\WebhookEndpoint;

class WebhookDisabledNotification extends BrandedNotification
{
    public function __construct(public WebhookEndpoint $endpoint)
    {
        parent::__construct();
    }

    protected function subject(): string
    {
        return 'Webhook dinonaktifkan otomatis';
    }

    protected function lines(): array
    {
        return [
            'Endpoint **'.$this->endpoint->url.'** gagal menerima '.config('postivo.webhook.disable_after').' event berturut-turut sehingga kami nonaktifkan.',
            'Perbaiki endpoint Anda, lalu aktifkan kembali dari halaman Webhooks.',
        ];
    }

    protected function action(): ?array
    {
        return ['Buka Webhooks', route('console.webhooks.index')];
    }
}
