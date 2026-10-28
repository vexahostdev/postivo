import Alpine from 'alpinejs';
import focus from '@alpinejs/focus';
import '@fontsource-variable/inter';

Alpine.plugin(focus);

async function toClipboard(value) {
    try {
        await navigator.clipboard.writeText(value);
        return true;
    } catch (e) {
        /* Clipboard API gagal / context non-secure: fallback textarea + execCommand */
        try {
            const ta = document.createElement('textarea');
            ta.value = value;
            ta.setAttribute('readonly', '');
            ta.style.cssText = 'position:fixed;top:-9999px;opacity:0';
            document.body.appendChild(ta);
            ta.select();
            const ok = document.execCommand('copy');
            ta.remove();
            return ok;
        } catch (e2) {
            return false;
        }
    }
}

// Salin teks ke clipboard dengan umpan balik singkat.
Alpine.data('clipboard', (text = '') => ({
    copied: false,
    failed: false,
    async copy(value = text) {
        if (await toClipboard(value)) {
            this.copied = true;
            setTimeout(() => (this.copied = false), 1500);
        } else {
            this.failed = true;
            setTimeout(() => (this.failed = false), 2500);
        }
    },
}));

// Konfirmasi via data-confirm (form atau tombol submit) — aman untuk teks dengan petik.
// form[data-pending] juga mematikan tombol via CSS (app.css) sebagai pengunci anti klik-ganda.
document.addEventListener('submit', (e) => {
    const form = e.target;
    if (form.dataset.pending === '1') {
        e.preventDefault();
        return;
    }
    const msg = e.submitter?.dataset.confirm ?? form.dataset.confirm;
    if (msg && !window.confirm(msg)) {
        e.preventDefault();
        return;
    }
    form.dataset.pending = '1';
});

// Verifikasi DNS: POST dengan CSRF, cooldown 10 detik, perbarui status record tanpa reload.
Alpine.data('dnsAuditor', (url, initial) => ({
    status: initial.status,
    records: initial.records,
    busy: false,
    cooldown: 0,
    message: '',
    async verify() {
        if (this.busy || this.cooldown > 0) return;
        this.busy = true;
        this.message = '';
        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    Accept: 'application/json',
                },
            });
            const data = await res.json();
            if (!res.ok) {
                this.message = data.error ?? 'Verifikasi gagal. Coba lagi.';
            } else {
                this.status = data.status;
                data.records.forEach((r) => {
                    const rec = this.records.find((x) => x.purpose === r.purpose);
                    if (rec) {
                        rec.verified = r.verified;
                        rec.seen = r.seen;
                    }
                });
                this.message = data.status === 'ACTIVE'
                    ? 'Domain aktif — siap mengirim.'
                    : 'Beberapa record belum terdeteksi. Propagasi DNS bisa memakan waktu.';
            }
        } catch (e) {
            this.message = 'Tidak dapat menghubungi server.';
        } finally {
            this.busy = false;
            this.cooldown = 10;
            const t = setInterval(() => {
                if (--this.cooldown <= 0) clearInterval(t);
            }, 1000);
        }
    },
}));

// Grafik 14 hari: Chart.js dimuat dinamis hanya di dashboard.
Alpine.data('sendChart', (url) => ({
    empty: false,
    async init() {
        let data;
        try {
            const res = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!res.ok) {
                this.empty = true;
                return;
            }
            data = await res.json();
        } catch (e) {
            this.empty = true;
            return;
        }
        if (![...(data.sent ?? []), ...(data.delivered ?? []), ...(data.bounced ?? [])].some(Boolean)) {
            this.empty = true;
            return;
        }
        const { Chart, registerables } = await import('chart.js');
        Chart.register(...registerables);
        const css = getComputedStyle(document.documentElement);
        new Chart(this.$refs.canvas, {
            type: 'bar',
            data: {
                labels: data.labels,
                datasets: [
                    { label: 'Delivered', data: data.delivered, backgroundColor: css.getPropertyValue('--color-ink').trim() || '#0A0A0C' },
                    { label: 'Bounced', data: data.bounced, backgroundColor: css.getPropertyValue('--color-red-500').trim() || '#FE0000' },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                scales: { x: { stacked: true, grid: { display: false } }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } } },
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { family: 'JetBrains Mono', size: 11 } } } },
            },
        });
    },
}));

window.Alpine = Alpine;
window.toClipboard = toClipboard;
Alpine.start();
