// Organisation chart navigator. One seat at a time: the manager above, the subject in
// the middle, its direct reports fanned below, and every circle re-centres the chart on
// itself. The whole reporting graph arrives once from OrgController::screenData, so
// walking it is local and instant; only a change to a reporting line goes to the server.
//
// It replaces the drag-and-drop indented tree. Dragging was the intuitive gesture but it
// needed the whole tree on screen to aim at, which is exactly what does not fit once a
// company has more than a couple of levels. Choosing an empty slot and then a person is
// two deliberate clicks, works from the keyboard, and never depends on the drop target
// being visible.

const api = async (url, token, body) => {
    const res = await fetch(url, {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': token,
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(body),
    });
    const data = res.status === 204 ? {} : await res.json().catch(() => ({}));
    if (!res.ok) {
        throw new Error(data.error || 'That change could not be saved.');
    }
    return data;
};

// One critically-damped spring, retargetable mid-flight. Re-centring twice quickly does
// not restart the motion: push() displaces the value that is currently on screen and
// keeps the velocity it already had, which is what stops the second click reading as a
// jump. Apple's damping/response pair rather than mass/stiffness/damping.
const spring = (onFrame, damping = 1, response = 0.42) => {
    const w = (2 * Math.PI) / response;
    let x = 1;
    let v = 0;
    let target = 1;
    let last = 0;
    let raf = null;

    const tick = (now) => {
        const dt = Math.min((now - last) / 1000, 0.032);
        last = now;
        v += (-w * w * (x - target) - 2 * damping * w * v) * dt;
        x += v * dt;
        onFrame(x);
        if (Math.abs(x - target) < 0.0015 && Math.abs(v) < 0.02) {
            x = target;
            v = 0;
            onFrame(x);
            raf = null;
            return;
        }
        raf = requestAnimationFrame(tick);
    };

    return {
        push(delta) {
            x = Math.max(-0.25, x + delta);
            target = 1;
            if (raf === null) {
                last = performance.now();
                raf = requestAnimationFrame(tick);
            }
        },
        settle() {
            x = 1;
            v = 0;
            target = 1;
            onFrame(1);
        },
    };
};

export function registerOrgChart(Alpine) {
    /**
     * @param chart {{people: array, parents: object, verifiers: object, roots: array, directors: array}}
     * @param canEdit {boolean} HR/management may change reporting lines.
     */
    Alpine.data('orgChart', (chart, canEdit) => ({
        canEdit,
        people: chart.people,
        byId: Object.fromEntries(chart.people.map((p) => [p.id, p])),
        parents: { ...chart.parents },
        verifiers: { ...chart.verifiers },
        directorIds: chart.directors,

        focus: null, // null = the directors band, the top of the chart
        dir: 1, // travel direction of the last move, so the motion points where the eye goes
        sel: null,
        picking: null, // {parent: id|null} while a slot is being filled
        pickingVerifier: false,
        q: '',
        busy: false,
        error: '',
        justPlaced: null,
        token: document.querySelector('meta[name="csrf-token"]')?.content ?? '',

        stage: null,
        reduce: false,

        init() {
            this.reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            this.stage = spring((k) => {
                const el = this.$refs.stage;
                if (!el) return;
                el.style.opacity = (0.4 + 0.6 * k).toFixed(3);
                el.style.transform = `translateY(${((1 - k) * 30 * this.dir).toFixed(2)}px)`;
                el.style.filter = k > 0.995 ? 'none' : `blur(${((1 - k) * 5).toFixed(2)}px)`;
            });
        },

        // ── reads ───────────────────────────────────────────────────────────────
        person(id) {
            return this.byId[id];
        },
        /** Direct reports of a seat, by name. `null` yields the top level. */
        kidsOf(id) {
            return Object.keys(this.parents)
                .map(Number)
                .filter((k) => (this.parents[k] ?? null) === (id ?? null) && !this.directorIds.includes(k))
                .sort((a, b) => this.person(a).name.localeCompare(this.person(b).name));
        },
        /** Seats from the top down to, but not including, this one. */
        ancestors(id) {
            const out = [];
            for (let cur = this.parents[id] ?? null; cur !== null; cur = this.parents[cur] ?? null) {
                out.unshift(cur);
                if (out.length > 32) break; // a stored loop must not spin the render
            }
            return out;
        },
        isAncestor(candidate, of) {
            return this.ancestors(of).includes(candidate) || candidate === of;
        },
        get directors() {
            return this.directorIds.map((id) => this.person(id)).filter(Boolean);
        },
        get reports() {
            return this.kidsOf(this.focus);
        },
        get subject() {
            return this.focus === null ? null : this.person(this.focus);
        },
        /** People with no manager: nobody can verify their leave, claims or overtime. */
        get unmanaged() {
            return this.kidsOf(null).filter((id) => id !== this.focus);
        },

        /**
         * Who may fill the open slot under `picking.parent`. A person cannot report to
         * themselves or to anyone already below them, and someone already reporting to
         * this seat would be a no-op. Directors answer to nobody in the chart.
         */
        get candidates() {
            const parent = this.picking?.parent ?? null;
            const q = this.q.trim().toLowerCase();

            return this.people
                .filter((p) => !this.directorIds.includes(p.id))
                .filter((p) => (this.parents[p.id] ?? null) !== parent)
                .filter((p) => parent === null || !this.isAncestor(p.id, parent))
                .filter((p) => !q || `${p.name} ${p.role ?? ''} ${p.dept ?? ''}`.toLowerCase().includes(q))
                .sort((a, b) => {
                    // People with no manager first: those are the ones the chart is missing.
                    const open = ((this.parents[b.id] ?? null) === null) - ((this.parents[a.id] ?? null) === null);
                    return open || a.name.localeCompare(b.name);
                });
        },
        /** Who may be added as an extra verifier for the subject. */
        get verifierCandidates() {
            const q = this.q.trim().toLowerCase();
            const chosen = this.verifiers[this.focus] ?? [];

            return this.people
                .filter((p) => p.id !== this.focus && !chosen.includes(p.id) && p.id !== (this.parents[this.focus] ?? null))
                .filter((p) => !q || `${p.name} ${p.role ?? ''} ${p.dept ?? ''}`.toLowerCase().includes(q));
        },
        get subjectVerifiers() {
            return (this.verifiers[this.focus] ?? []).map((id) => this.person(id)).filter(Boolean);
        },

        // ── navigation ──────────────────────────────────────────────────────────
        dive(id, dir) {
            this.dir = dir;
            this.focus = id;
            this.sel = id;
            this.picking = null;
            this.pickingVerifier = false;
            this.q = '';
            this.error = '';
            this.reduce ? this.stage.settle() : this.stage.push(-1);
        },
        openSlot(parentId) {
            this.picking = { parent: parentId };
            this.pickingVerifier = false;
            this.q = '';
            this.error = '';
            this.$nextTick(() => this.$refs.search?.focus());
        },
        cancel() {
            this.picking = null;
            this.pickingVerifier = false;
            this.q = '';
        },

        // ── writes ──────────────────────────────────────────────────────────────
        async moveTo(employeeId, managerId) {
            this.busy = true;
            this.error = '';
            try {
                await api('/app/org/move', this.token, { employee_id: employeeId, manager_id: managerId });
                this.parents[employeeId] = managerId;
                this.justPlaced = employeeId;
                setTimeout(() => {
                    this.justPlaced = null;
                }, 520);
                this.cancel();
            } catch (err) {
                // Nothing local changed, so there is no drift to repair — say what went
                // wrong and leave the chart exactly as the server has it.
                this.error = err.message;
            } finally {
                this.busy = false;
            }
        },
        place(employeeId) {
            return this.moveTo(employeeId, this.picking?.parent ?? null);
        },
        detach(employeeId) {
            return this.moveTo(employeeId, null);
        },

        async saveVerifiers(ids) {
            this.busy = true;
            this.error = '';
            try {
                const res = await api(`/app/org/verifiers/${this.focus}`, this.token, { verifiers: ids });
                this.verifiers[this.focus] = res.verifiers ?? ids;
                this.pickingVerifier = false;
                this.q = '';
            } catch (err) {
                this.error = err.message;
            } finally {
                this.busy = false;
            }
        },
        addVerifier(id) {
            return this.saveVerifiers([...(this.verifiers[this.focus] ?? []), id]);
        },
        removeVerifier(id) {
            return this.saveVerifiers((this.verifiers[this.focus] ?? []).filter((x) => x !== id));
        },
    }));
}
