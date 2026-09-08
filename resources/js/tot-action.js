// CR-09: the "Create T.A.A. task" button on one Tindakan row. tot.actions.card answers
// JSON only (never a redirect), so this is the one CR-09 write that needs a fetch rather
// than a plain form post like the rest of the drawer (slot/attendance/tindakan forms).
export function registerTotAction(Alpine) {
    Alpine.data('totActionCard', (seed) => ({
        ...seed,
        busy: false,

        async createCard() {
            if (this.busy || this.workItemId) return;
            this.busy = true;
            try {
                const res = await fetch(`/app/tot/${this.sessionId}/actions/${this.actionId}/card`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                    },
                });
                const payload = await res.json().catch(() => ({}));
                if (!res.ok) throw new Error(payload.message || String(res.status));
                this.workItemId = payload.work_item.id;
                this.dueAt = payload.work_item.due_at;
                this.dueText = payload.work_item.due_text;
                Alpine.store('toast').success(
                    Alpine.store('ui').lang === 'en' ? 'T.A.A. task created.' : 'Tugasan T.A.A. dicipta.'
                );
            } catch (e) {
                Alpine.store('toast').error(
                    Alpine.store('ui').lang === 'en' ? 'Could not create the task.' : 'Tidak dapat mencipta tugasan.'
                );
            } finally {
                this.busy = false;
            }
        },
    }));
}
