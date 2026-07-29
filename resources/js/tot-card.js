export function registerTotCard(Alpine) {
    Alpine.data('totCard', (seed) => ({
        ...seed,
        flyout: null,
        drawerOpen: false,
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

        // Pressing the score you already gave takes it back, matching the emoji
        // and the eye. The server clears the note along with the score.
        rate(score) {
            return this.act(`/app/tot/${this.id}/rate`, {
                score: this.myScore === score ? null : score,
            });
        },

        // With no score there is nothing for a note to annotate, and posting one
        // would send score:null and clear the row instead of saving the text.
        saveNote(note) {
            if (this.myScore === null || this.myScore === undefined) return;

            return this.act(`/app/tot/${this.id}/rate`, { score: this.myScore, note });
        },

        openDrawer() {
            this.drawerOpen = true;

            return this.openThread();
        },

        async openThread() {
            if (this.thread !== null) return;
            try {
                const res = await fetch(`/app/tot/${this.id}/comments`, {
                    headers: { 'Accept': 'application/json' },
                });
                if (!res.ok) throw new Error(res.status);
                const payload = await res.json();
                this.thread = payload.comments;
                this.notes = payload.notes;
            } catch (e) {
                this.thread = [];
                this.notes = [];
                Alpine.store('toast').error(
                    Alpine.store('ui').lang === 'en'
                        ? 'Could not load the discussion.'
                        : 'Tidak dapat memuatkan perbincangan.'
                );
            }
        },

        async postComment(body) {
            if (!body.trim()) return;
            await this.act(`/app/tot/${this.id}/comment`, { body });
            this.thread = null;
            await this.openThread();
        },

        async removeComment(id) {
            if (this.busy) return;
            this.busy = true;
            try {
                const res = await fetch(`/app/tot/comments/${id}`, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                    },
                });
                if (!res.ok) throw new Error(res.status);
                Object.assign(this, await res.json());
                this.thread = this.thread.filter((c) => c.id !== id);
            } catch (e) {
                Alpine.store('toast').error(
                    Alpine.store('ui').lang === 'en' ? 'Could not remove that.' : 'Tidak dapat membuang.'
                );
            } finally {
                this.busy = false;
            }
        },
    }));
}
