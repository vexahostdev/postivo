import Alpine from 'alpinejs';
import focus from '@alpinejs/focus';

Alpine.plugin(focus);

// Halaman dokumentasi: pencarian Ctrl+K (indeks dari DOM) dan scrollspy sidebar.
Alpine.data('docs', () => ({
    nav: false,
    vw: window.innerWidth,
    search: false,
    q: '',
    idx: 0,
    active: '',
    index: [],

    init() {
        this.index = [...document.querySelectorAll('[data-doc]')].map((el) => ({
            id: el.id,
            title: el.dataset.doc,
            group: el.dataset.group || '',
            text: (el.textContent || '').toLowerCase().replace(/\s+/g, ' '),
        }));

        const obs = new IntersectionObserver(
            (entries) => {
                const visible = entries.filter((e) => e.isIntersecting);
                if (!visible.length) return;
                const top = visible.reduce((a, b) => (a.boundingClientRect.top <= b.boundingClientRect.top ? a : b));
                this.active = top.target.id;
            },
            { rootMargin: '-80px 0px -70% 0px' },
        );
        document.querySelectorAll('[data-doc]').forEach((el) => obs.observe(el));
        if (location.hash) this.active = location.hash.slice(1);
        this.$watch('q', () => (this.idx = 0));
    },

    get results() {
        const t = this.q.trim().toLowerCase();
        if (!t) return this.index.slice(0, 8);
        const words = t.split(/\s+/);
        return this.index
            .map((r) => ({ r, score: words.reduce((s, w) => s + (r.title.toLowerCase().includes(w) ? 3 : 0) + (r.text.includes(w) ? 1 : 0), 0) }))
            .filter((x) => x.score > 0 && words.every((w) => x.r.text.includes(w) || x.r.title.toLowerCase().includes(w)))
            .sort((a, b) => b.score - a.score)
            .slice(0, 10)
            .map((x) => x.r);
    },

    openSearch() {
        this.search = true;
        this.q = '';
        this.$nextTick(() => this.$refs.q && this.$refs.q.focus());
    },
    move(d) {
        const n = this.results.length;
        if (n) this.idx = (this.idx + d + n) % n;
    },
    go() {
        const r = this.results[this.idx];
        if (r) {
            this.search = false;
            location.hash = r.id;
        }
    },
}));

window.Alpine = Alpine;
Alpine.start();
