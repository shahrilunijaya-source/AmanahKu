/**
 * Post a drawer form without leaving the page. Every drawer form redirects back to
 * /app/tot, so the response IS the fresh page: pick this month's card out of it and
 * swap it in. Alpine's MutationObserver tears the old card (and its teleported drawer)
 * down and boots the new one; then the drawer is reopened so the user stays where
 * they were. A validation failure comes back the same way (old input + $errors baked
 * into the card, drawerOpen seeded true), so it shows exactly as a full reload would.
 *
 * ponytail: throws the rest of the page away, same trade partial-nav makes. No
 * fragment route until payload size shows up in a profile.
 */
async function submitTotForm(Alpine, event, root) {
    if (event.defaultPrevented) return;
    event.preventDefault();
    const form = event.target;
    const month = root.dataset.totMonth;
    const submit = form.querySelector('[type=submit]');
    if (submit) submit.disabled = true;
    try {
        const res = await // getAttribute: a field named "action" shadows form.action.
        fetch(form.getAttribute('action'), { method: 'POST', body: new FormData(form) });
        if (!res.ok) throw new Error(res.status);
        const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
        const fresh = doc.querySelector(`[data-tot-month="${month}"]`);
        if (!fresh) throw new Error('no card');
        const failed = fresh.hasAttribute('data-tot-failed');
        // Keep each pane where the user had it scrolled; the swap builds a fresh drawer.
        const scrolls = [...document.querySelectorAll('.wd[data-open] .tot-pane')].map((el) => el.scrollTop);
        const next = document.importNode(fresh, true);
        root.replaceWith(next);
        await new Promise((r) => setTimeout(r, 0));
        const data = Alpine.$data(next);
        if (data && typeof data.openDrawer === 'function') {
            data.openDrawer();
            await Alpine.nextTick();
            document.querySelectorAll('.wd[data-open] .tot-pane').forEach((el, i) => { el.scrollTop = scrolls[i] ?? 0; });
        }
        const lang = Alpine.store('ui').lang;
        if (failed) {
            Alpine.store('toast').error(lang === 'en' ? 'Check the form and try again.' : 'Semak borang dan cuba lagi.');
        } else {
            Alpine.store('toast').success(lang === 'en' ? 'Saved.' : 'Disimpan.');
        }
    } catch (e) {
        if (submit) submit.disabled = false;
        Alpine.store('toast').error(
            Alpine.store('ui').lang === 'en' ? 'That did not save. Try again.' : 'Tidak berjaya disimpan. Cuba lagi.'
        );
    }
}

export function registerTotCard(Alpine) {
    // The empty-month card: only the "open this month" form lives in its drawer.
    Alpine.data('totMonth', () => ({
        drawerOpen: false,
        submitForm(event) {
            return submitTotForm(Alpine, event, this.$root);
        },
    }));

    Alpine.data('totCard', (seed) => ({
        ...seed,
        flyout: null,
        drawerOpen: false,
        thread: null,
        notes: [],
        busy: false,
        // A slot's own thread, shown in the room pane in place of the session thread.
        slotRoom: null,

        get roomThread() {
            return this.slotRoom ? this.slotRoom.thread : this.thread;
        },

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

        react(reaction) {
            return this.act(`/app/tot/${this.id}/react`, { reaction });
        },

        // The outer icon is the toggle; the flyout is only for choosing. With a
        // reaction already left, pressing the heart takes it back — one emoji per
        // person, so there is never a question of which one.
        heartPress() {
            if (this.mine.length) {
                this.flyout = null;

                return this.react(this.mine[0]);
            }

            this.flyout = this.flyout === 'react' ? null : 'react';
        },

        starPress() {
            if (this.myScore) {
                this.flyout = null;

                return this.rate(this.myScore);   // same score in, cleared out
            }

            this.flyout = this.flyout === 'rate' ? null : 'rate';
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

        submitForm(event) {
            return submitTotForm(Alpine, event, this.$root);
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
            if (this.slotRoom) return this.postSlotComment(body);
            await this.act(`/app/tot/${this.id}/comment`, { body });
            this.thread = null;
            await this.openThread();
        },

        async openSlotRoom(slot) {
            if (this.slotRoom && this.slotRoom.id === slot.id) return this.closeSlotRoom();
            this.slotRoom = { ...slot, thread: null };
            this.$nextTick(() => {
                this.$refs.room?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                this.$refs.composer?.focus();
            });
            try {
                const res = await fetch(`/app/tot/${this.id}/slots/${slot.id}/comments`, {
                    headers: { 'Accept': 'application/json' },
                });
                if (!res.ok) throw new Error(String(res.status));
                if (this.slotRoom?.id === slot.id) this.slotRoom.thread = (await res.json()).comments;
            } catch (e) {
                if (this.slotRoom?.id === slot.id) this.slotRoom.thread = [];
                Alpine.store('toast').error(
                    Alpine.store('ui').lang === 'en' ? 'Could not load the discussion.' : 'Tidak dapat memuatkan perbincangan.'
                );
            }
        },

        closeSlotRoom() {
            this.slotRoom = null;
        },

        async postSlotComment(body) {
            if (this.busy) return;
            this.busy = true;
            try {
                const res = await fetch(`/app/tot/${this.id}/slots/${this.slotRoom.id}/comment`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ body }),
                });
                if (!res.ok) throw new Error(String(res.status));
                this.slotRoom.thread = (await res.json()).comments;
            } catch (e) {
                Alpine.store('toast').error(
                    Alpine.store('ui').lang === 'en' ? 'That did not save. Try again.' : 'Tidak berjaya disimpan. Cuba lagi.'
                );
            } finally {
                this.busy = false;
            }
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
