<?php

namespace App\Services;

use App\Exceptions\IngestException;
use App\Support\Addr;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\MailMimeParser;

/** Raw MIME dari SMTP → array siap `EmailIngestService::accept` (BACKEND_SPEC §11). */
class MimeMessageParser
{
    private const FORBIDDEN_EXT = ['exe', 'bat', 'cmd', 'com', 'scr', 'js', 'vbs', 'jar', 'msi', 'dll'];

    /** @param  string[]  $rcptTo  penerima envelope; yang tak ada di header To/Cc dianggap Bcc */
    public function parse(string $path, array $rcptTo): array
    {
        $fh = fopen($path, 'r');
        if (! $fh) {
            throw new IngestException('internal_error', 'Spool file unreadable.', 500);
        }
        try {
            return $this->build((new MailMimeParser)->parse($fh, true), $rcptTo);
        } finally {
            fclose($fh); // Windows: handle terbuka mencegah unlink spool
        }
    }

    private function build($msg, array $rcptTo): array
    {
        $from = $msg->getHeader('From');
        $fromAddr = $from instanceof AddressHeader ? ($from->getAddresses()[0] ?? null) : null;
        if (! $fromAddr || ! filter_var($fromAddr->getEmail(), FILTER_VALIDATE_EMAIL)) {
            throw new IngestException('validation_failed', 'Missing or invalid From header.', 422);
        }
        $fromName = $fromAddr->getName();

        $to = $this->addresses($msg, 'To');
        $cc = $this->addresses($msg, 'Cc');
        $known = array_merge($to, $cc);
        $bcc = array_values(array_diff(array_map([Addr::class, 'normalize'], $rcptTo), $known));
        if (! $to && ! $cc) {
            // Hanya Bcc: jadikan penerima utama.
            $to = $bcc;
            $bcc = [];
        }

        $html = $msg->getHtmlContent();
        $text = $msg->getTextContent();
        if (! $html && ! $text) {
            throw new IngestException('validation_failed', 'Message has no body.', 422);
        }

        $attachments = [];
        foreach ($msg->getAllAttachmentParts() as $part) {
            $name = $part->getFilename() ?: 'attachment';
            if (in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::FORBIDDEN_EXT, true)) {
                throw new IngestException('validation_failed', "Attachment type not allowed: {$name}", 422);
            }
            $attachments[] = [
                'filename' => mb_substr($name, 0, 255),
                'content' => base64_encode($part->getContent() ?? ''),
                'content_type' => $part->getContentType() ?: 'application/octet-stream',
            ];
        }

        return [
            'from' => $fromName ? sprintf('%s <%s>', $fromName, $fromAddr->getEmail()) : $fromAddr->getEmail(),
            'to' => $to, 'cc' => $cc, 'bcc' => $bcc,
            'reply_to' => $this->addresses($msg, 'Reply-To')[0] ?? null,
            'subject' => mb_substr((string) ($msg->getHeaderValue('Subject') ?: '(no subject)'), 0, 255),
            'html' => $html, 'text' => $text,
            'attachments' => $attachments,
            'track_opens' => null, 'track_clicks' => null,
        ];
    }

    /** @return string[] */
    private function addresses($msg, string $name): array
    {
        $h = $msg->getHeader($name);
        if (! $h instanceof AddressHeader) {
            return [];
        }

        return array_values(array_filter(array_map(fn ($a) => Addr::normalize($a->getEmail()), $h->getAddresses())));
    }
}
