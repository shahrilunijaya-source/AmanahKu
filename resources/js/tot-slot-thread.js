// CR-09: one slot's own discussion thread, lazy-loaded when the slot's "Discussion" row
// is opened rather than riding along with the drawer's session-level thread. Mirrors
// tot-card.js's session thread (list + composer), scoped to tot.slots.comment(s).
export function registerTotSlotThread(Alpine) {
    Alpine.data('totSlotThread', (seed) => ({
        ...seed,
        open: false,
        thread: null,
        busy: false,

        toggle() {
            this.open = !this.open;
            if (this.open) {
                this.load();
            }
        },

        async load() {
            if (this.thread !== null) return;
            try {
                const res = await fetch(`/app/tot/${this.sessionId}/slots/${this.slotId}/comments`, {
                    headers: { 'Accept': 'application/json' },
                });
                if (!res.ok) throw new Error(String(res.status));
                this.thread = (await res.json()).comments;
            } catch (e) {
                this.thread = [];
                Alpine.store('toast').error(
                    Alpine.store('ui').lang === 'en' ? 'Could not load the discussion.' : 'Tidak dapat memuatkan perbincangan.'
                );
            }
        },

        // QA F3: hearts per slot. Same toggle rule as the session bar (press again = undo).
        async react(key) {
            if (this.busy) return;
            this.busy = true;
            try {
                const res = await fetch(`/app/tot/${this.sessionId}/slots/${this.slotId}/react`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ emoji: key }),
                });
                if (!res.ok) throw new Error(String(res.status));
                this.reactions = (await res.json()).reactions;
                this.mine = this.mine.includes(key) ? [] : [key];
            } catch (e) {
                Alpine.store('toast').error(
                    Alpine.store('ui').lang === 'en' ? 'That did not save. Try again.' : 'Tidak berjaya disimpan. Cuba lagi.'
                );
            } finally {
                this.busy = false;
            }
        },

        async post(body) {
            if (!body.trim() || this.busy) return;
            this.busy = true;
            try {
                const res = await fetch(`/app/tot/${this.sessionId}/slots/${this.slotId}/comment`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ body }),
                });
                if (!res.ok) throw new Error(String(res.status));
                this.thread = (await res.json()).comments;
            } catch (e) {
                Alpine.store('toast').error(
                    Alpine.store('ui').lang === 'en' ? 'That did not save. Try again.' : 'Tidak berjaya disimpan. Cuba lagi.'
                );
            } finally {
                this.busy = false;
            }
        },
    }));
}
