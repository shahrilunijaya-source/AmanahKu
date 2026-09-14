// "To approve" tab on Timesheet Reports (partials/timesheet-report/approvals.blade.php).
// The rows are server-rendered; this only posts to the existing CR-03 day endpoints and
// folds a settled row away, so approving a day never reloads the screen. The tab's own
// count listens for `ts-approvals-left`.

const FOLD_AFTER_MS = 650; // long enough to read "Approved" before the row folds
const PERSON_FOLD_MS = 300;

export function registerTimesheetApprovals(Alpine) {
    Alpine.data('timesheetApprovals', (cfg) => ({
        left: cfg.left, // { [employeeId]: days still waiting }
        isos: cfg.isos, // { [employeeId]: [iso, …] } in display order
        state: {}, // 'emp|iso' → 'approved' | 'returned'
        gone: {}, // 'emp|iso' → true once folded
        gonePerson: {},
        asking: null,
        reason: '',
        busy: null,
        allDone: false,

        en() { return Alpine.store('ui').lang === 'en'; },
        total() { return Object.values(this.left).reduce((sum, n) => sum + n, 0); },
        peopleLeft() { return Object.values(this.left).filter((n) => n > 0).length; },

        ask(key) {
            this.asking = key;
            this.reason = '';
            this.$nextTick(() => document.getElementById(`ta-reason-${key.replace('|', '-')}`)?.focus());
        },
        cancel(key) {
            if (this.asking === key) { this.asking = null; }
            this.reason = '';
        },

        async post(emp, iso, path, body) {
            const res = await fetch(`/app/timesheets/${emp}/days/${iso}/${path}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: JSON.stringify(body || {}),
            });
            if (res.ok) { return true; }
            let message = '';
            try { message = (await res.json()).message || ''; } catch { /* non-JSON error page */ }
            // 422 "not submitted": someone else already settled it (recalled, or HR acted).
            // Fold it away rather than leave a row whose buttons can only fail.
            if (res.status === 422) { return 'stale'; }
            throw new Error(message);
        },

        async approve(emp, iso, first, label, quiet = false) {
            const key = `${emp}|${iso}`;
            if (this.state[key]) { return true; }
            this.busy = key;
            try {
                const ok = await this.post(emp, iso, 'approve');
                this.settle(emp, iso, 'approved');
                if (!quiet && ok === true) {
                    Alpine.store('toast').success(this.en() ? `Approved ${label} for ${first}.` : `${label} diluluskan untuk ${first}.`);
                }
                return true;
            } catch (e) {
                Alpine.store('toast').error(e.message || (this.en() ? 'Could not approve that day.' : 'Tak dapat luluskan hari itu.'));
                return false;
            } finally {
                this.busy = null;
            }
        },

        async approveAll(emp, first) {
            const waiting = (this.isos[emp] || []).filter((iso) => !this.state[`${emp}|${iso}`]);
            let done = 0;
            for (const iso of waiting) {
                if (!(await this.approve(emp, iso, first, '', true))) { break; }
                done++;
            }
            if (done > 0) {
                Alpine.store('toast').success(this.en()
                    ? `Approved ${done} ${done === 1 ? 'day' : 'days'} for ${first}.`
                    : `${done} hari diluluskan untuk ${first}.`);
            }
        },

        async sendBack(emp, iso, first, label) {
            const key = `${emp}|${iso}`;
            const reason = this.reason.trim();
            if (!reason) { return; }
            this.busy = key;
            try {
                const ok = await this.post(emp, iso, 'return', { reason });
                this.asking = null;
                this.reason = '';
                this.settle(emp, iso, 'returned');
                if (ok !== true) { return; }
                Alpine.store('toast').info(this.en() ? `Sent ${label} back to ${first}.` : `${label} dikembalikan kepada ${first}.`);
            } catch (e) {
                Alpine.store('toast').error(e.message || (this.en() ? 'Could not send that day back.' : 'Tak dapat kembalikan hari itu.'));
            } finally {
                this.busy = null;
            }
        },

        settle(emp, iso, kind) {
            const key = `${emp}|${iso}`;
            this.state[key] = kind;
            this.left[emp] = Math.max(0, (this.left[emp] || 0) - 1);
            window.dispatchEvent(new CustomEvent('ts-approvals-left', { detail: this.total() }));
            setTimeout(() => {
                this.gone[key] = true;
                if (this.left[emp] === 0) {
                    setTimeout(() => {
                        this.gonePerson[emp] = true;
                        if (this.total() === 0) { setTimeout(() => { this.allDone = true; }, PERSON_FOLD_MS); }
                    }, PERSON_FOLD_MS);
                }
            }, FOLD_AFTER_MS);
        },
    }));
}
