const POLL_MS = 60_000;
const CURSOR_KEY = 'amanahku:lastNotificationId';

/**
 * Raises OS-level notifications for new bells while the app is open.
 *
 * Scope is deliberate: this fires while a tab or installed window is running, including
 * when the browser is minimised. It does NOT fire with the browser closed — that needs
 * Web Push (VAPID keys, stored subscriptions, a server-side sender), which is not built.
 *
 * Alerts go through the service worker's showNotification() rather than `new Notification()`
 * because iOS has no such constructor; the worker path is the only one that works on every
 * platform.
 */
export function registerNotifier(Alpine) {
    Alpine.data('notifier', () => ({
        notif: false,
        permission: 'Notification' in window ? Notification.permission : 'unsupported',
        timer: null,

        init() {
            if (this.permission === 'granted') {
                this.startPolling();
            }

            // Stop polling when the component goes away so a long session cannot stack
            // intervals across Alpine re-inits.
            this.$el.addEventListener('alpine:destroyed', () => this.stopPolling());
        },

        /** Only offer the opt-in when the browser has not already decided. */
        get canAsk() {
            return this.permission === 'default';
        },

        async enable() {
            // Browsers reject a permission request that is not driven by a click, so this
            // must stay wired to a real button and never run on page load.
            this.permission = await Notification.requestPermission();

            if (this.permission === 'granted') {
                this.startPolling();
            }
        },

        startPolling() {
            if (this.timer !== null) {
                return;
            }
            this.poll();
            // Background tabs are throttled to roughly one timer callback per minute, so a
            // shorter interval buys nothing once the window is hidden.
            this.timer = setInterval(() => this.poll(), POLL_MS);
        },

        stopPolling() {
            if (this.timer !== null) {
                clearInterval(this.timer);
                this.timer = null;
            }
        },

        async poll() {
            const cursor = localStorage.getItem(CURSOR_KEY);

            let payload;
            try {
                const response = await fetch(`/app/notifications/unseen?since=${cursor ?? '0'}`, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                if (!response.ok) {
                    return; // signed out, or the session expired: stay quiet
                }
                payload = await response.json();
            } catch {
                return; // offline: try again on the next tick
            }

            const firstRun = cursor === null;
            localStorage.setItem(CURSOR_KEY, String(payload.latestId));

            // A first run only records the starting point. Without this, a returning user
            // is hit with a burst of alerts for bells they already know about.
            if (firstRun) {
                return;
            }

            const registration = await navigator.serviceWorker.ready;
            for (const notification of payload.notifications) {
                registration.showNotification(notification.title, {
                    body: notification.body ?? '',
                    icon: '/icons/icon-192.png',
                    badge: '/icons/icon-192.png',
                    tag: `amanahku-${notification.id}`,
                    data: { url: notification.url ?? '/app' },
                });
            }
        },
    }));
}
