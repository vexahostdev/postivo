'use strict';
// Uji ujung-ke-ujung lokal:  node scripts/send-test.js <api-key> [from] [to] [port]
const nodemailer = require('nodemailer');

const [key, from = 'Demo <noreply@mail.demo.test>', to = 'user@example.com', port = '2525'] = process.argv.slice(2);
if (!key) { console.error('usage: node scripts/send-test.js <api-key> [from] [to] [port]'); process.exit(2); }

nodemailer.createTransport({
    host: '127.0.0.1', port: Number(port), secure: false, requireTLS: true,
    auth: { user: 'postivo', pass: key },
    tls: { rejectUnauthorized: false }, // sertifikat dev self-signed
}).sendMail({
    from, to, subject: 'Tes SMTP Postivo', text: 'Halo dari SMTP', html: '<p>Halo dari <b>SMTP</b></p>',
    bcc: 'hidden@example.com',
    attachments: [{ filename: 'a.txt', content: 'lampiran' }],
}).then(
    (r) => { console.log('OK', r.response); },
    (e) => { console.log('REJECTED', e.responseCode, e.response || e.message); process.exitCode = 1; },
);
