// One global toast for the whole app. Any screen fires a transient confirmation with
// `$store.toast.success('…')`, `.error('…')`, or `.info('…')`; a single host
// (resources/views/partials/toast-host.blade.php) renders the queue. This replaces the
// per-screen ad-hoc toasts that used to live inside rolesAdmin and timesheetCapture, so
// the two feel like one app and there is one place to restyle.
export function registerToast(Alpine) {
    Alpine.store('toast', {
        items: [],
        _seq: 0,
        _timers: {},

        // Push a toast. `type` (success | error | info) drives colour + icon. Errors linger
        // a little longer because a failure is worth reading twice; pass an explicit
        // `timeout` (ms) to override, or 0 to make it stay until manually dismissed.
        // `action` is optional: `{ label, run }` renders a button beside the message, so a
        // toast can offer the one thing it is telling you about (e.g. "Reload").
        show(message, type = 'success', timeout = null, action = null) {
            if (!message) return null;
            if (timeout === null) timeout = type === 'error' ? 5000 : 3200;
            const id = ++this._seq;
            const item = { id, message, type, timeout, action, remaining: timeout, started: this._now(), leaving: false };
            this.items.push(item);
            if (timeout > 0) this._arm(item);
            return id;
        },
        success(message, timeout = null, action = null) { return this.show(message, 'success', timeout, action); },
        error(message, timeout = null, action = null) { return this.show(message, 'error', timeout, action); },
        info(message, timeout = null, action = null) { return this.show(message, 'info', timeout, action); },

        // Play the leave transition, then drop the item from the queue.
        dismiss(id) {
            const item = this.items.find((x) => x.id === id);
            if (!item || item.leaving) return;
            item.leaving = true;
            clearTimeout(this._timers[id]);
            delete this._timers[id];
            setTimeout(() => {
                this.items = this.items.filter((x) => x.id !== id);
            }, 200);
        },

        // Hover-to-hold: pause the countdown while the pointer is over a toast so a user
        // reading a long message is never cut off mid-sentence. `remaining` is tracked
        // exactly so the resumed timer (and the CSS progress bar) stay in sync.
        pause(id) {
            const item = this.items.find((x) => x.id === id);
            if (!item || item.leaving || item.timeout <= 0) return;
            clearTimeout(this._timers[id]);
            item.remaining -= this._now() - item.started;
        },
        resume(id) {
            const item = this.items.find((x) => x.id === id);
            if (!item || item.leaving || item.timeout <= 0) return;
            this._arm(item);
        },

        _arm(item) {
            clearTimeout(this._timers[item.id]);
            item.started = this._now();
            this._timers[item.id] = setTimeout(() => this.dismiss(item.id), Math.max(0, item.remaining));
        },
        _now() {
            return (typeof performance !== 'undefined' ? performance.now() : Date.now());
        },
    });

    // The centered popup, for the few things that must not be missed (clock in/out, a
    // project reaching Track later, a new version). Everything else stays a toast, so this
    // one keeps its weight. One at a time: a second show() replaces the first.
    // `timeout` (ms) closes it on its own, 0 keeps it until dismissed. `action` is optional
    // `{ label, run }`, rendered as the main button with a quieter "Not now" beside it.
    // `corner: true` shows it in the toast corner without blocking the page.
    Alpine.store('notice', {
        open: false,
        seq: 0,
        title: '',
        body: '',
        tone: 'success',
        timeout: 2000,
        action: null,
        corner: false,
        _timer: null,

        show({ title, body = '', tone = 'success', timeout = 2000, action = null, corner = false }) {
            clearTimeout(this._timer);
            Object.assign(this, { title, body, tone, timeout, action, corner, open: true });
            this.seq++;
            if (timeout > 0) this._timer = setTimeout(() => this.close(), timeout);
        },
        close() {
            clearTimeout(this._timer);
            this.open = false;
        },
    });
}
