<?php

namespace App\Notifications;

use Illuminate\Support\Facades\URL;

class VerifyEmailNotification extends BrandedNotification
{
    protected function subject(): string
    {
        return 'Verifikasi alamat email Anda';
    }

    protected function lines(): array
    {
        return ['Klik tombol di bawah untuk memverifikasi email. Verifikasi diperlukan untuk membuat API key live. Tautan berlaku 60 menit.'];
    }

    protected function action(): ?array
    {
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $this->notifiableId, 'hash' => $this->hash]);

        return ['Verifikasi email', $url];
    }

    public string $notifiableId = '';

    public string $hash = '';

    public function toMail(object $notifiable): \Illuminate\Notifications\Messages\MailMessage
    {
        $this->notifiableId = $notifiable->getKey();
        $this->hash = sha1($notifiable->getEmailForVerification());

        return parent::toMail($notifiable);
    }
}
