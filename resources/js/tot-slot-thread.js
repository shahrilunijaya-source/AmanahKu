// One slot's reactions. The slot's discussion thread itself lives in the drawer's room
// pane (tot-card.js openSlotRoom), so this component only owns the heart + picker.
export function registerTotSlotThread(Alpine) {
    Alpine.data('totSlotThread', (seed) => ({
        ...seed,
        pick: false,
        busy: false,

        get reactionTotal() {
            return Object.values(this.reactions).reduce((a, b) => a + b, 0);
        },

        // Same rule as the session bar: with a reaction already left, the heart takes it back.
        heartPress() {
            if (this.mine.length) {
                this.pick = false;

                return this.react(this.mine[0]);
            }
            this.pick = !this.pick;
        },

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
    }));
}
