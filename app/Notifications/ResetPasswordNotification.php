<?php

namespace App\Notifications;

class ResetPasswordNotification extends BrandedNotification
{
    public function __construct(public string $token)
    {
        parent::__construct();
    }

    protected function subject(): string
    {
        return 'Atur ulang kata sandi Postivo';
    }

    protected function lines(): array
    {
        return [
            'Kami menerima permintaan mengatur ulang kata sandi akun Anda. Tautan berlaku 60 menit.',
            'Jika bukan Anda yang meminta, abaikan email ini — kata sandi tidak berubah.',
        ];
    }

    protected function action(): ?array
    {
        return ['Atur ulang kata sandi', route('password.reset', $this->token)];
    }

    public ?string $email = null;

    public function toMail(object $notifiable): \Illuminate\Notifications\Messages\MailMessage
    {
        $mail = parent::toMail($notifiable);
        $mail->actionUrl .= '?email='.urlencode($notifiable->getEmailForPasswordReset());

        return $mail;
    }
}
