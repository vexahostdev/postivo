<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\Email;
use App\Models\EmailDispatch;
use App\Models\TrackingLink;
use App\Support\Addr;

class TrackingService
{
    public function baseUrl(Domain $domain): string
    {
        if ($domain->tracking_host) {
            return 'https://'.$domain->tracking_host;
        }

        return rtrim(config('postivo.tracking.url') ?: config('app.url'), '/');
    }

    /** Terapkan rewrite link + pixel sesuai opsi email. Mengembalikan HTML baru. */
    public function apply(string $html, Email $email, EmailDispatch $d, Domain $domain): string
    {
        $base = $this->baseUrl($domain);

        if ($email->track_clicks) {
            $html = preg_replace_callback('/<a\b([^>]*?)href=("|\')(https?:\/\/[^"\']+)\2/i', function ($m) use ($d, $email, $base) {
                $url = html_entity_decode($m[3]);
                // Jangan rewrite link unsubscribe milik sistem sendiri.
                if (str_starts_with($url, $base.'/u/')) {
                    return $m[0];
                }
                $token = Addr::token();
                TrackingLink::create(['token' => $token, 'dispatch_id' => $d->id, 'email_id' => $email->id, 'url' => $url, 'created_at' => now()]);

                return '<a'.$m[1].'href='.$m[2].$base.'/c/'.$token.$m[2];
            }, $html) ?? $html;
        }

        if ($email->track_opens) {
            $pixel = '<img src="'.$base.'/o/'.$d->open_token.'.gif" width="1" height="1" alt="" style="display:none">';
            $html = stripos($html, '</body>') !== false ? preg_replace('/<\/body>/i', $pixel.'</body>', $html, 1) : $html.$pixel;
        }

        return $html;
    }
}
