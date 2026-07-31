export function registerTotRoster(Alpine) {
    Alpine.data('totRoster', (seed) => ({
        ...seed,
        cursor: null,
        filter: '',
        busy: false,

        init() {
            this.cursor = this.nextEmpty(0);
        },

        /**
         * The next month with no presenter, wrapping at December. A not_tot month
         * is a deliberate "there is no TOT this month" marker and takes no
         * presenter, so the cursor steps over it. A skipped month stays open,
         * because correcting a month that was wrongly left empty should not need
         * HR to change its status first.
         */
        nextEmpty(from) {
            for (let i = 0; i < 12; i++) {
                const k = (from + i) % 12;
                if (!this.slots[k].presenter && this.slots[k].status !== 'not_tot') return k;
            }

            return null;
        },

        get people() {
            const q = this.filter.trim().toLowerCase();

            return q ? this.roster.filter((p) => p.name.toLowerCase().includes(q)) : this.roster;
        },

        badgesFor(id) {
            return this.slots
                .map((s, i) => (s.presenter && s.presenter.id === id ? i + 1 : null))
                .filter(Boolean);
        },

        setCursor(i) {
            if (this.slots[i].status === 'not_tot') return;
            this.cursor = i;
        },

        assign(person) {
            if (this.cursor === null || this.busy) return;

            const i = this.cursor;
            const previous = this.slots[i].presenter;

            this.slots[i].presenter = person;      // optimistic
            this.cursor = this.nextEmpty(i + 1);

            return this.write(i, person.id).catch(() => {
                this.slots[i].presenter = previous;
                this.cursor = i;
            });
        },

        clear(i) {
            if (this.busy) return;

            const previous = this.slots[i].presenter;
            this.slots[i].presenter = null;
            this.cursor = i;

            return this.write(i, '').catch(() => {
                this.slots[i].presenter = previous;
            });
        },

        /**
         * Every pick writes on the click. There is no bulk Save, because a
         * half-filled roster is a valid state and the person filling it stops
         * halfway more often than not.
         */
        async write(index, presenterId) {
            const slot = this.slots[index];
            const url = slot.id ? `/app/tot/${slot.id}` : '/app/tot';
            // status is only in store()'s rule set for a privileged role, where it is
            // required — so HR gets a 422 without it while a tot.assign holder does not.
            // Sending 'planned' makes both roles create the same row, which is what
            // store() already forces for a holder.
            const body = slot.id
                ? { presenter_employee_id: presenterId, totform: slot.id }
                : { year: this.year, month: index + 1, presenter_employee_id: presenterId, status: 'planned' };

            this.busy = true;
            try {
                const res = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(body),
                });
                if (!res.ok) throw new Error(res.status);

                // A create returns the new id. Without adopting it the slot still looks
                // unsaved, and the next edit re-POSTs to /app/tot, which the duplicate
                // guard rejects with a 422.
                if (!slot.id) {
                    const created = await res.json().catch(() => null);
                    if (created?.id) slot.id = created.id;
                }
            } catch (e) {
                Alpine.store('toast').error(
                    Alpine.store('ui').lang === 'en'
                        ? 'That did not save. Try again.'
                        : 'Tidak berjaya disimpan. Cuba lagi.'
                );
                throw e;
            } finally {
                this.busy = false;
            }
        },
    }));
}
