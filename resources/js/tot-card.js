export function registerTotCard(Alpine) {
    Alpine.data('totCard', (seed) => ({
        ...seed,
        flyout: null,
        modalOpen: false,
        thread: null,
        notes: [],
        busy: false,

        // Total across every emoji, which is what the heart shows.
        get reactionTotal() {
            return Object.values(this.reactions).reduce((a, b) => a + b, 0);
        },

        // One place that talks to the server. Every action returns the same card state, so
        // there is one merge and one failure path rather than five of each.
        async act(url, body = null) {
            if (this.busy) return;
            this.busy = true;
            try {
                const res = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: body ? JSON.stringify(body) : null,
                });
                if (!res.ok) throw new Error(res.status);
                Object.assign(this, await res.json());
            } catch (e) {
                Alpine.store('toast').error(
                    Alpine.store('ui').lang === 'en'
                        ? 'That did not save. Try again.'
                        : 'Tidak berjaya disimpan. Cuba lagi.'
                );
            } finally {
                this.busy = false;
            }
        },

        react(emoji) {
            return this.act(`/app/tot/${this.id}/react`, { emoji });
        },

        toggleWatched() {
            return this.act(`/app/tot/${this.id}/watched`);
        },

        rate(score) {
            return this.act(`/app/tot/${this.id}/rate`, { score });
        },

        saveNote(note) {
            return this.act(`/app/tot/${this.id}/rate`, { score: this.myScore, note });
        },

        openThread() {
            this.modalOpen = true;
        },
    }));
}
