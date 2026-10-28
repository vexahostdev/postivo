'use strict';
/**
 * Postivo SMTP ingress (BACKEND_SPEC §11).
 * Hanya: TLS + autentikasi (API key) + spool ke disk + forward ke Laravel + terjemah respons ke kode SMTP.
 * Tidak mem-parse MIME. Laravel adalah sumber kebenaran (kuota, suppression, domain).
 *
 * Deviasi dari spec: autentikasi memakai POST /internal/smtp/auth (bukan hash Redis) sehingga
 * engine bisa jalan tanpa Redis; hasil positif di-cache 30 detik di memori.
 */
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { pipeline } = require('node:stream/promises');
const { SMTPServer } = require('smtp-server');

// Muat ../.env bila variabel belum diset (dev lokal); produksi memakai environment asli.
(function loadDotenv() {
    const f = path.join(__dirname, '..', '.env');
    if (!fs.existsSync(f)) return;
    for (const line of fs.readFileSync(f, 'utf8').split(/\r?\n/)) {
        const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*?)\s*$/);
        if (m && process.env[m[1]] === undefined) process.env[m[1]] = m[2].replace(/^["']|["']$/g, '');
    }
})();

const dev = process.env.NODE_ENV !== 'production';
const cfg = {
    appUrl: (process.env.APP_INTERNAL_URL || 'http://127.0.0.1:8050').replace(/\/$/, ''),
    secret: process.env.POSTIVO_INTERNAL_SECRET,
    spool: process.env.POSTIVO_SPOOL_DIR || path.join(__dirname, '..', 'storage', 'app', 'spool'),
    maxSize: 25 * 1024 * 1024,
    maxRcpt: 50,
    ports: (process.env.SMTP_PORTS || (dev ? '2525:starttls' : '587:starttls,465:tls,2525:starttls')).split(','),
    host: process.env.POSTIVO_SMTP_HOST || 'smtp.postivo.my.id',
};
if (!cfg.secret) throw new Error('POSTIVO_INTERNAL_SECRET is required');
fs.mkdirSync(cfg.spool, { recursive: true });

function loadTls() {
    if (process.env.TLS_KEY_PATH && process.env.TLS_CERT_PATH) {
        return { key: fs.readFileSync(process.env.TLS_KEY_PATH), cert: fs.readFileSync(process.env.TLS_CERT_PATH) };
    }
    if (!dev) throw new Error('TLS_KEY_PATH and TLS_CERT_PATH are required in production');
    const pems = require('selfsigned').generate([{ name: 'commonName', value: 'localhost' }], { days: 30, keySize: 2048 });
    console.log(JSON.stringify({ level: 'warn', msg: 'using ephemeral self-signed certificate (dev only)' }));
    return { key: pems.private, cert: pems.cert };
}
const tls = loadTls();

const log = (level, msg, extra = {}) => console.log(JSON.stringify({ level, msg, ...extra })); // tidak pernah mencatat kredensial
const smtpError = (code, msg) => Object.assign(new Error(msg), { responseCode: code });
const sha256 = (s) => crypto.createHash('sha256').update(s).digest('hex');

function signed(body) {
    const ts = Math.floor(Date.now() / 1000);
    const sig = crypto.createHmac('sha256', cfg.secret).update(`${ts}.${body}`).digest('hex');
    return { 'Content-Type': 'application/json', 'X-Postivo-Timestamp': String(ts), 'X-Postivo-Signature': sig };
}
async function post(pathname, payload, timeout) {
    const body = JSON.stringify(payload);
    const res = await fetch(cfg.appUrl + pathname, { method: 'POST', headers: signed(body), body, signal: AbortSignal.timeout(timeout) });
    return { status: res.status, json: await res.json().catch(() => ({})) };
}

// --- Gerbang gagal-auth per IP: 10 gagal / 10 menit → 421 sementara ---------------------------------
const fails = new Map();
function failCount(ip) {
    const e = fails.get(ip);
    if (!e || e.reset < Date.now()) return 0;
    return e.n;
}
function noteFail(ip) {
    const e = fails.get(ip);
    if (!e || e.reset < Date.now()) fails.set(ip, { n: 1, reset: Date.now() + 600_000 });
    else e.n++;
}
const gate = (ip) => { if (failCount(ip) >= 10) throw smtpError(421, '4.7.0 Too many authentication failures'); };

const authCache = new Map(); // sha256(password) -> { id, exp }

function mapLaravelError(status, code) {
    switch (code) {
        case 'domain_not_verified': case 'forbidden': return smtpError(550, '5.7.1 Sender domain not verified');
        case 'recipient_suppressed': return smtpError(550, '5.1.1 All recipients are suppressed');
        case 'quota_exceeded': case 'rate_limited': return smtpError(452, '4.5.3 Quota or rate limit exceeded');
        case 'payload_too_large': return smtpError(552, '5.3.4 Message too large');
        case 'validation_failed': return smtpError(554, '5.6.0 Message rejected: invalid content');
        case 'unauthenticated': return smtpError(535, '5.7.8 Authentication credentials invalid');
        default: return status >= 500 || !status ? smtpError(451, '4.3.0 Temporary failure, please retry')
            : smtpError(554, '5.0.0 Transaction failed');
    }
}

function buildServer(mode) {
    return new SMTPServer({
        name: cfg.host,
        banner: 'Postivo SMTP Ingress',
        secure: mode === 'tls', // 465 implicit TLS
        key: tls.key, cert: tls.cert, minVersion: 'TLSv1.2',
        authMethods: ['PLAIN', 'LOGIN'],
        authOptional: false,
        allowInsecureAuth: false, // AUTH hanya setelah TLS
        disabledCommands: mode === 'tls' ? ['STARTTLS'] : [],
        size: cfg.maxSize,
        maxClients: 500,
        socketTimeout: 60_000,

        onConnect(session, cb) { try { gate(session.remoteAddress); cb(); } catch (e) { cb(e); } },

        async onAuth(auth, session, cb) {
            try {
                gate(session.remoteAddress);
                const pw = auth.password || '';
                const h = sha256(pw);
                let hit = authCache.get(h);
                if (!hit || hit.exp < Date.now()) {
                    const r = await post('/internal/smtp/auth', { password: pw }, 5000);
                    if (r.status === 200 && r.json.ok && r.json.org_status === 'ACTIVE') {
                        hit = { id: r.json.api_key_id, exp: Date.now() + 30_000 };
                        authCache.set(h, hit);
                    } else if (r.status === 401 || r.status === 200) {
                        noteFail(session.remoteAddress);
                        return cb(smtpError(535, '5.7.8 Authentication credentials invalid'));
                    } else {
                        return cb(smtpError(454, '4.7.0 Temporary authentication failure'));
                    }
                }
                cb(null, { user: hit.id });
            } catch (e) {
                if (e.responseCode) return cb(e);
                cb(smtpError(454, '4.7.0 Temporary authentication failure'));
            }
        },

        onRcptTo(address, session, cb) {
            if (session.envelope.rcptTo.length >= cfg.maxRcpt) return cb(smtpError(452, '4.5.3 Too many recipients'));
            cb();
        },

        async onData(stream, session, cb) {
            const ingestId = crypto.randomUUID();
            const file = path.join(cfg.spool, `${ingestId}.eml`);
            try {
                await pipeline(stream, fs.createWriteStream(file, { mode: 0o660, flags: 'wx' }));
                if (stream.sizeExceeded) throw smtpError(552, '5.3.4 Message size exceeds limit');
                const fd = fs.openSync(file, 'r+'); fs.fsyncSync(fd); fs.closeSync(fd);

                const { status, json } = await post('/internal/smtp/ingest', {
                    ingest_id: ingestId, api_key_id: session.user,
                    mail_from: session.envelope.mailFrom && session.envelope.mailFrom.address,
                    rcpt_to: session.envelope.rcptTo.map((r) => r.address),
                    spool_path: file, size: fs.statSync(file).size,
                    remote_ip: session.remoteAddress, tls: !!session.secure,
                }, 20_000);

                if (status === 202 || status === 200) {
                    log('info', 'accepted', { ingest_id: ingestId, email_id: json.email_id });
                    return cb(null, `2.0.0 Ok: queued as ${json.email_id}`);
                }
                throw mapLaravelError(status, json.error && json.error.code);
            } catch (err) {
                fs.promises.unlink(file).catch(() => {});
                if (!err.responseCode) { log('error', 'ingest failed', { ingest_id: ingestId, err: err.message }); err = smtpError(451, '4.3.0 Temporary failure, please retry'); }
                cb(err);
            }
        },
    });
}

// Sapu spool yatim > 1 jam (Laravel menghapus file setelah commit) + entri memori kedaluwarsa.
setInterval(() => {
    for (const f of fs.readdirSync(cfg.spool)) {
        const p = path.join(cfg.spool, f);
        try { if (Date.now() - fs.statSync(p).mtimeMs > 3_600_000) fs.unlinkSync(p); } catch { /* sudah hilang */ }
    }
    const now = Date.now();
    for (const [ip, e] of fails) if (e.reset < now) fails.delete(ip);
    for (const [h, e] of authCache) if (e.exp < now) authCache.delete(h);
}, 600_000).unref();

const servers = [];
for (const entry of cfg.ports) {
    const [port, mode] = entry.split(':');
    const srv = buildServer(mode);
    srv.on('error', (e) => log('error', e.message, { port }));
    srv.listen(Number(port), '0.0.0.0', () => log('info', 'SMTP ingress listening', { port, mode }));
    servers.push(srv);
}
for (const sig of ['SIGINT', 'SIGTERM']) process.on(sig, () => { servers.forEach((s) => s.close()); setTimeout(() => process.exit(0), 500).unref(); });
