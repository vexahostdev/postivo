<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Dasar semua email sistem Postivo. Pengirim noreply@, balasan ke support@ (config/mail + postivo).
 * Subclass cukup mengisi subject(), lines(), dan opsional action().
 */
abstract class BrandedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('postivo_default');
    }

    abstract protected function subject(): string;

    /** @return string[] paragraf isi (boleh Markdown inline) */
    abstract protected function lines(): array;

    /** @return array{0: string, 1: string}|null [label, url] */
    protected function action(): ?array
    {
        return null;
    }

    /** Teks penutup; null = bawaan. */
    protected function outro(): ?string
    {
        return null;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->replyTo(config('postivo.support_email'), 'Postivo Support')
            ->subject($this->subject())
            ->greeting('Halo '.($notifiable->name ?? '').',');

        foreach ($this->lines() as $line) {
            $mail->line($line);
        }
        if ($a = $this->action()) {
            $mail->action($a[0], $a[1]);
        }

        return $mail->line($this->outro() ?? 'Butuh bantuan? Balas email ini atau hubungi '.config('postivo.support_email').'.')
            ->salutation("Salam,\nTim Postivo");
    }
}
