<?php

namespace App\Notifications;

use App\Models\ApiKey;

class ApiKeyCreatedNotification extends BrandedNotification
{
    public function __construct(public ApiKey $key)
    {
        parent::__construct();
    }

    protected function subject(): string
    {
        return 'API key baru dibuat: '.$this->key->name;
    }

    protected function lines(): array
    {
        return [
            'API key **'.$this->key->name.'** ('.$this->key->prefix.'…, '.$this->key->environment.') baru dibuat di akun Anda.',
            'Jika ini bukan Anda, segera cabut key tersebut dan ganti kata sandi.',
        ];
    }

    protected function action(): ?array
    {
        return ['Kelola API key', route('console.api-keys.index')];
    }
}
