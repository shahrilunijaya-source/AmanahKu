// The task board's Google Calendar control. Server state comes from
// CalendarSyncStatus (initial render + GET status); Sync now and Retry are JSON posts
// sharing a 2-minute server-side limit, mirrored here as a live countdown.

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

export function registerCalendarSync(Alpine) {
    Alpine.data('calendarSync', (initial, urls) => ({
        s: initial,
        urls,
        open: false,
        cooldown: initial.retry_after || 0,
        result: null,
        watching: false,
        poller: null,
        ticker: null,

        init() {
            if (this.cooldown > 0) this.countdown();
            if (this.running) this.watch();
            const params = new URLSearchParams(window.location.search);
            if (params.get('calendar') === 'connected') {
                this.open = true;
                this.watch();
                params.delete('calendar');
                const qs = params.toString();
                window.history.replaceState(window.history.state, '', window.location.pathname + (qs ? `?${qs}` : ''));
            }
        },

        destroy() {
            clearInterval(this.poller);
            clearInterval(this.ticker);
        },

        t(en, ms) { return this.$store.ui.lang === 'en' ? en : ms; },

        get progress() { return this.s.progress || { total: 0, done: 0, failed: 0, pulled: 0 }; },
        get running() { return this.s.progress?.state === 'running'; },
        get issueCount() { return this.s.issues.length; },
        get percent() { return this.progress.total ? Math.round((this.progress.done / this.progress.total) * 100) : 0; },
        get tone() { return this.s.state === 'expired' || (this.s.state === 'connected' && this.issueCount > 0) ? 'warn' : 'ok'; },
        get dotColor() {
            if (this.tone === 'warn') return 'var(--error)';
            return this.s.state === 'connected' ? 'var(--success)' : '#b9b6ad';
        },
        get synced() { return this.s.last_synced_human; },
        get pillSub() {
            if (this.running) return this.t('Syncing…', 'Menyegerak…');
            if (this.s.state === 'off') return this.t('Not connected', 'Tidak bersambung');
            if (this.s.state === 'expired') return this.t('Reconnect', 'Sambung semula');
            return this.synced ? this.t(`Synced ${this.synced}`, `Disegerak ${this.synced}`) : this.t('Connected', 'Bersambung');
        },
        get headTitle() {
            if (this.s.state === 'off') return this.t('Not connected', 'Tidak bersambung');
            if (this.s.state === 'expired') return this.t('Connection expired', 'Sambungan tamat');
            if (this.issueCount > 0) return this.t(`Connected, ${this.issueCount} ${this.issueCount === 1 ? 'card needs' : 'cards need'} attention`, `Bersambung, ${this.issueCount} kad perlu perhatian`);
            return this.t('Connected', 'Bersambung');
        },
        get headText() {
            if (this.s.state === 'off') {
                return this.t('Connect once and every card you own or are tagged on shows up in a separate “Amanahku” calendar. Your main calendar is never touched.',
                    'Sambung sekali dan setiap kad milik anda atau yang anda ditanda akan muncul dalam kalendar “Amanahku” berasingan. Kalendar utama anda tidak disentuh.');
            }
            if (this.s.state === 'expired') {
                return this.t('Google stopped letting AmanahKu update your calendar, so nothing is being sent right now. Your cards are safe; reconnect and they will be sent again.',
                    'Google berhenti membenarkan AmanahKu mengemas kini kalendar anda, jadi tiada apa dihantar sekarang. Kad anda selamat; sambung semula dan ia akan dihantar lagi.');
            }
            const cards = this.t(`${this.s.mirrored} ${this.s.mirrored === 1 ? 'card' : 'cards'} in your “Amanahku” calendar`, `${this.s.mirrored} kad dalam kalendar “Amanahku” anda`);
            return this.synced ? `${cards} · ${this.t(`last synced ${this.synced}`, `kali terakhir disegerak ${this.synced}`)}` : cards;
        },
        get buttonText() {
            if (this.running) {
                return this.progress.total
                    ? this.t(`Syncing ${this.progress.done} of ${this.progress.total} cards…`, `Menyegerak ${this.progress.done} daripada ${this.progress.total} kad…`)
                    : this.t('Starting…', 'Bermula…');
            }
            if (this.cooldown > 0) return this.t(`Sync again in ${this.clock}`, `Segerak lagi dalam ${this.clock}`);
            return this.t('Sync now', 'Segerak sekarang');
        },
        get noteText() {
            if (this.running) return this.t('You can keep working. This finishes in the background.', 'Anda boleh terus bekerja. Ini selesai di latar belakang.');
            if (this.cooldown > 0) return this.t('You can sync once every 2 minutes.', 'Anda boleh menyegerak sekali setiap 2 minit.');
            return this.t('Sends all your cards to Google and brings back changes made there. Changes also come in by themselves every 5 minutes.',
                'Menghantar semua kad anda ke Google dan membawa balik perubahan di sana. Perubahan juga masuk sendiri setiap 5 minit.');
        },
        get clock() {
            const m = Math.floor(this.cooldown / 60);
            return `${m}:${String(this.cooldown % 60).padStart(2, '0')}`;
        },
        get resultText() {
            const r = this.result;
            if (!r) return '';
            const sent = r.done - r.failed;
            const pulled = r.pulled ? this.t(` ${r.pulled} ${r.pulled === 1 ? 'change' : 'changes'} brought back from Google.`, ` ${r.pulled} perubahan dibawa balik dari Google.`) : '';
            if (r.failed > 0) {
                return this.t(`${sent} of ${r.total} cards sent. ${r.failed} could not be sent; use Retry below, or Sync now.`,
                    `${sent} daripada ${r.total} kad dihantar. ${r.failed} tidak dapat dihantar; guna Cuba lagi di bawah, atau Segerak sekarang.`) + pulled;
            }
            return (r.total === 0
                ? this.t('Nothing to send: no open cards with a due date.', 'Tiada apa untuk dihantar: tiada kad terbuka yang bertarikh akhir.')
                : this.t(`All ${r.total} ${r.total === 1 ? 'card' : 'cards'} sent.`, `Kesemua ${r.total} kad dihantar.`)) + pulled;
        },

        friendly(message) {
            if (/revoked/i.test(message || '')) return this.t('Google access was removed.', 'Akses Google telah dibuang.');
            return this.t('Google did not accept it. Tried 5 times.', 'Google tidak menerimanya. Dicuba 5 kali.');
        },

        async post(url) {
            let res;
            try {
                res = await fetch(url, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
            } catch {
                this.$store.toast.error(this.t('Could not reach AmanahKu. Check your connection.', 'Tidak dapat menghubungi AmanahKu. Semak sambungan anda.'));
                return null;
            }
            const data = await res.json().catch(() => ({}));
            if (res.status === 429) {
                this.cooldown = data.retry_after || 60;
                this.countdown();
                this.$store.toast.info(this.t(`You can sync again in ${this.clock}.`, `Anda boleh menyegerak lagi dalam ${this.clock}.`));
                return null;
            }
            if (!res.ok) {
                this.$store.toast.error(data.message || this.t('Something went wrong. Try again.', 'Ada masalah. Cuba lagi.'));
                if (res.status === 409) this.refresh();
                return null;
            }
            return data;
        },

        async syncNow() {
            if (this.running || this.cooldown > 0) return;
            this.result = null;
            const data = await this.post(this.urls.sync);
            if (!data) return;
            this.apply(data);
            this.watch();
        },

        // Retry now runs inline server-side: the response already carries fresh
        // status, so toast right away instead of polling for it.
        async retry(id) {
            if (this.running || this.cooldown > 0) return;
            const data = await this.post(this.urls.retry.replace('__ID__', id));
            if (!data) return;
            this.apply(data);
            const stillFailing = data.issues.some((issue) => issue.id === id);
            if (stillFailing) {
                this.$store.toast.error(this.t('Still could not send that card.', 'Kad itu masih tidak dapat dihantar.'));
            } else {
                this.$store.toast.success(this.t('Card sent.', 'Kad dihantar.'));
            }
        },

        async refresh() {
            try {
                const res = await fetch(this.urls.status, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                if (res.ok) this.apply(await res.json());
            } catch {
                // A missed poll is retried on the next tick.
            }
        },

        apply(data) {
            this.s = data;
            if ((data.retry_after || 0) > this.cooldown) {
                this.cooldown = data.retry_after;
                this.countdown();
            }
        },

        // Poll while a sync runs; announce the outcome once when it stops.
        watch() {
            this.watching = true;
            clearInterval(this.poller);
            this.poller = setInterval(async () => {
                await this.refresh();
                if (!this.running) {
                    clearInterval(this.poller);
                    if (this.watching) this.finished();
                    this.watching = false;
                }
            }, 2000);
        },

        finished() {
            const p = this.s.progress;
            if (!p) return;
            if (p.state === 'expired') {
                this.open = true;
                this.$store.toast.error(this.t('Google Calendar access has expired. Reconnect to keep syncing.', 'Akses Kalendar Google telah tamat. Sambung semula untuk terus menyegerak.'));
                return;
            }
            this.result = p;
            if (p.failed > 0) {
                this.$store.toast.error(this.t(`Google Calendar: ${p.failed} of ${p.total} cards could not be sent`, `Kalendar Google: ${p.failed} daripada ${p.total} kad tidak dapat dihantar`));
            } else {
                this.$store.toast.success(this.t(`Google Calendar synced · ${p.total} ${p.total === 1 ? 'card' : 'cards'} sent`, `Kalendar Google disegerak · ${p.total} kad dihantar`));
            }
        },

        countdown() {
            clearInterval(this.ticker);
            this.ticker = setInterval(() => {
                this.cooldown = Math.max(0, this.cooldown - 1);
                if (this.cooldown === 0) clearInterval(this.ticker);
            }, 1000);
        },
    }));
}
