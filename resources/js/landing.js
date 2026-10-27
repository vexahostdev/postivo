import Alpine from 'alpinejs';
import focus from '@alpinejs/focus';

Alpine.plugin(focus);

const caps = [
    {
        id: 'relay', title: 'Dedicated SMTP Relay', subtitle: 'REST API and SMTP, one pipeline', kicker: 'Capability 01',
        services: ['Stalwart relay', 'Brevo failover', 'TLS + retries'],
        summary: 'Every message enters through the REST API or an SMTP login, is validated once, queued, DKIM-signed and handed to our relay. If the primary relay has a transient failure, delivery continues through the fallback without losing the message.',
        deliverables: ['REST API with idempotency keys', 'SMTP on 587, 465 and 2525', 'Exponential backoff retries', 'Automatic relay failover'],
    },
    {
        id: 'auth', title: 'Domain Authentication', subtitle: 'SPF, DKIM, DMARC — guided', kicker: 'Capability 02',
        services: ['Live DNS checks', 'Suppression list', 'Per-domain isolation'],
        summary: 'Add a domain, copy five DNS records, and watch them turn green. Hard bounces and complaints are suppressed automatically so you never keep mailing addresses that hurt your reputation.',
        deliverables: ['DKIM RSA-2048 per domain', 'Return-Path and tracking CNAME', 'Automatic suppression', 'Bounce and complaint handling'],
    },
    {
        id: 'events', title: 'Real-Time Event Stream', subtitle: 'Know what happened to every email', kicker: 'Capability 03',
        services: ['Signed webhooks', 'Per-email timeline', 'Raw MIME inspector'],
        summary: 'Follow each message from queued to delivered, with SMTP responses and the raw MIME that was sent. Receive the same events as HMAC-signed webhooks with retries.',
        deliverables: ['Delivered, bounced, complained events', 'Opt-in open and click tracking', 'Searchable email log', 'Signed webhooks with retries'],
    },
];

Alpine.data('landing', () => ({
    tab: 'curl', copied: false, menu: false, modal: null, caps,
    snippets: {
        curl: `curl https://api.postivo.my.id/v1/emails \\
  -H "Authorization: Bearer $POSTIVO_API_KEY" \\
  -H "Idempotency-Key: otp-8812" \\
  -H "Content-Type: application/json" \\
  -d '{
    "from": "Acme <noreply@mail.acme.com>",
    "to": ["user@example.com"],
    "subject": "Your verification code",
    "html": "<p>Code: <b>123456</b></p>"
  }'`,
        node: `const res = await fetch('https://api.postivo.my.id/v1/emails', {
  method: 'POST',
  headers: {
    Authorization: \`Bearer \${process.env.POSTIVO_API_KEY}\`,
    'Idempotency-Key': 'otp-8812',
    'Content-Type': 'application/json',
  },
  body: JSON.stringify({
    from: 'Acme <noreply@mail.acme.com>',
    to: ['user@example.com'],
    subject: 'Your verification code',
    html: '<p>Code: <b>123456</b></p>',
  }),
});
console.log(res.status, await res.json()); // 202`,
        php: `$res = Http::withToken(env('POSTIVO_API_KEY'))
    ->withHeaders(['Idempotency-Key' => 'otp-8812'])
    ->post('https://api.postivo.my.id/v1/emails', [
        'from'    => 'Acme <noreply@mail.acme.com>',
        'to'      => ['user@example.com'],
        'subject' => 'Your verification code',
        'html'    => '<p>Code: <b>123456</b></p>',
    ]);
$res->status(); // 202`,
    },
    async copyCode() {
        try {
            await navigator.clipboard.writeText(this.snippets[this.tab]);
            this.copied = true;
            setTimeout(() => (this.copied = false), 1500);
        } catch (e) { /* clipboard diblokir */ }
    },
    pipeline: ['Accepted  → API / SMTP 587', 'Queued    → Redis · outbound', 'Signed    → DKIM rsa-sha256', 'Relayed   → Stalwart (primary)', 'Fallback  → Brevo (on transient failure)', 'Delivered → 250 2.0.0 OK'],
    dns: [['TXT', 'postivo._domainkey.mail.acme.com', 'verified'], ['TXT', 'mail.acme.com (SPF)', 'verified'], ['CNAME', 'bounces.mail.acme.com', 'verified'], ['TXT', '_dmarc.mail.acme.com', 'pending'], ['CNAME', 'trk.mail.acme.com', 'pending']],
    events: [['08:15:30.012', 'Queued', 'accepted via REST'], ['08:15:30.980', 'Sent', 'relayed by Stalwart'], ['08:15:32.411', 'Delivered', '250 2.0.0 OK'], ['08:16:04.002', 'Clicked', 'https://acme.com/verify']],
    facts: [['Transport security', 'TLS 1.2+ on SMTP ingress and API'], ['Signing', 'DKIM RSA-2048, relaxed/relaxed'], ['Webhooks', 'HMAC-SHA256, retries with backoff'], ['Failover', 'Stalwart primary, Brevo on transient failure']],
}));

window.Alpine = Alpine;
Alpine.start();
