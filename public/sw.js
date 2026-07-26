// Minimal service worker. It exists for exactly two reasons:
//   1. Installability — Chrome and Edge only offer "Install app" once a worker with a
//      fetch listener is registered, and installing is the ONLY way iOS will show a
//      notification from a web app at all.
//   2. Notifications — iOS has no `new Notification()` constructor, so every platform
//      goes through registration.showNotification() instead.
// It deliberately caches nothing: the app is server-rendered and must never serve stale
// HR data from a previous session.

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

// Declared but intentionally passive. Registering the handler satisfies the install
// criteria; not calling respondWith() lets every request go straight to the network.
self.addEventListener('fetch', () => {});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = (event.notification.data && event.notification.data.url) || '/app';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
            const existing = clients.find((client) => 'focus' in client);
            if (!existing) {
                return self.clients.openWindow(url);
            }
            // navigate() can reject when the client is not controlled by this worker;
            // focusing the existing window is a good enough fallback.
            return Promise.resolve(existing.navigate(url))
                .catch(() => existing)
                .then(() => existing.focus());
        }),
    );
});
