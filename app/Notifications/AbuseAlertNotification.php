<?php

namespace App\Notifications;

use App\Models\Organization;

/** Dikirim ke support@ saat reputasi org mencapai level DANGER (postivo:abuse-scan). */
class AbuseAlertNotification extends BrandedNotification
{
    public function __construct(public Organization $org, public array $rep, public bool $suspended)
    {
        parent::__construct();
    }

    protected function subject(): string
    {
        return '[Abuse] '.$this->org->name.' — reputasi berbahaya';
    }

    protected function lines(): array
    {
        $r = $this->rep;

        return [
            "Organisasi **{$this->org->name}** ({$this->org->slug}) melewati ambang reputasi dalam 7 hari terakhir.",
            "Volume {$r['volume']} · bounce {$r['bounce_rate']}% · komplain {$r['complaint_rate']}%.",
            $this->suspended ? 'Akun **ditangguhkan otomatis**.' : 'Penangguhan otomatis mati — tinjau dan tangguhkan manual bila perlu.',
        ];
    }

    protected function action(): ?array
    {
        return ['Buka di admin', route('admin.organizations.show', $this->org->id)];
    }
}
