/**
 * The Appearance card on Account & security. Lives in the bundle, not in an inline
 * <script> on the screen: in-app navigation swaps only <main>'s innerHTML, which
 * never runs inline scripts, so the card only worked after a full reload.
 */
export function registerAppearanceCard(Alpine) {
    Alpine.data('appearanceCard', (cfg) => ({
        choice: cfg.choice, dim: cfg.dim, photoUrl: cfg.photoUrl, photoLum: cfg.photoLum, reply: null, busy: false, error: '',
        csrf: document.querySelector('meta[name=csrf-token]').content,

        /* The wallpaper behind this page is swapped in place, so the pick is seen at
           once. A short blur over the crossfade keeps two pictures from reading as
           two objects mid-swap. */
        paint() {
            const shell = document.getElementById('uj-shell');
            const header = document.querySelector('.uj-header');
            let layer = document.querySelector('.uj-wallpaper');
            let bg = null;
            if (this.choice.startsWith('preset:')) bg = cfg.presets[this.choice.slice(7)];
            else if (this.choice === 'upload' && this.photoUrl) bg = 'url(' + this.photoUrl + ')';

            /* Everything a wallpaper toggles, kept in this one list so it can't drift
               from the $wp branch in layouts/app.blade.php: the shell + layer, the
               header blur, and the dark flag (same blend as App\Support\Tone). */
            const blur = bg ? 'blur(14px)' : '';
            const lum = this.choice.startsWith('preset:') ? cfg.lums[this.choice.slice(7)] : (this.choice === 'upload' ? this.photoLum : null);
            const d = (cfg.dims[this.dim] ?? 30) / 100;
            const dark = !!bg && lum != null && (lum * (1 - d) + cfg.canvasLum * d) < cfg.darkBelow;
            shell?.classList.toggle('uj-has-wallpaper', !!bg);
            shell?.classList.toggle('uj-on-dark', dark);
            window.ujMarkSurfaces?.();
            if (header) { header.style.backdropFilter = blur; header.style.webkitBackdropFilter = blur; }

            if (!bg) { layer?.remove(); return; }
            if (!layer) { layer = document.createElement('div'); layer.className = 'uj-wallpaper'; shell.prepend(layer); }
            layer.dataset.dim = this.dim;
            layer.style.transition = 'opacity 220ms cubic-bezier(.23,1,.32,1), filter 220ms cubic-bezier(.23,1,.32,1)';
            layer.style.filter = 'blur(6px)'; layer.style.opacity = '0.6';
            requestAnimationFrame(() => { layer.style.backgroundImage = bg; layer.style.filter = ''; layer.style.opacity = ''; });
        },
        async send(body) {
            this.error = '';
            const fallback = Alpine.store('ui').lang === 'en' ? 'Could not save.' : 'Tidak dapat disimpan.';
            try {
                const res = await fetch(cfg.url, { method: 'POST', headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' }, body });
                if (res.status === 422) { const j = await res.json(); this.error = Object.values(j.errors ?? {})[0]?.[0] ?? fallback; return false; }
                if (!res.ok) { this.error = fallback; return false; }
                this.reply = await res.json().catch(() => null);
                return true;
            } catch (e) {
                this.error = fallback;
                return false;
            }
        },
        form(extra = {}) {
            const f = new FormData(); f.append('wallpaper', this.choice); f.append('dim', this.dim);
            Object.entries(extra).forEach(([k, v]) => f.append(k, v));
            return f;
        },
        async pick(c) { const prev = this.choice; this.choice = c; this.paint(); if (!await this.send(this.form())) { this.choice = prev; this.paint(); } },
        async setDim(d) { const prev = this.dim; this.dim = d; this.paint(); if (!await this.send(this.form())) { this.dim = prev; this.paint(); } },
        async upload(e) {
            const file = e.target.files[0]; if (!file) return;
            this.busy = true;
            const prev = this.choice; this.choice = 'upload';
            const ok = await this.send(this.form({ photo: file }));
            this.busy = false; e.target.value = '';
            if (!ok) { this.choice = prev; return; }
            this.photoUrl = URL.createObjectURL(file); this.photoLum = this.reply?.luminance ?? null; this.paint();
        },
        async removePhoto() {
            const fallback = Alpine.store('ui').lang === 'en' ? 'Could not remove the photo.' : 'Foto tidak dapat dibuang.';
            try {
                const res = await fetch(cfg.deleteUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' } });
                if (!res.ok) { this.error = fallback; return; }
            } catch (e) {
                this.error = fallback;
                return;
            }
            this.photoUrl = null; this.photoLum = null; if (this.choice === 'upload') this.choice = 'none'; this.paint();
        },
    }));
}
