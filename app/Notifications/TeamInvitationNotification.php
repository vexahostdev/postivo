<?php

namespace App\Notifications;

use App\Models\Organization;

class TeamInvitationNotification extends BrandedNotification
{
    public function __construct(public Organization $org, public string $role, public string $token)
    {
        parent::__construct();
    }

    protected function subject(): string
    {
        return 'Undangan bergabung ke '.$this->org->name.' di Postivo';
    }

    protected function lines(): array
    {
        return [
            'Anda diundang bergabung ke organisasi **'.$this->org->name.'** sebagai **'.$this->role.'**.',
            'Undangan berlaku 7 hari.',
        ];
    }

    protected function action(): ?array
    {
        return ['Terima undangan', route('invitation.accept', $this->token)];
    }
}
