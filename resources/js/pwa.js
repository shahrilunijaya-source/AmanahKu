// Registers the root-scoped service worker. Kept separate from the notifier so the
// install/PWA concern has one owner; the notifier just awaits navigator.serviceWorker.ready.
export function registerServiceWorker() {
    if (!('serviceWorker' in navigator)) {
        return;
    }

    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch((error) => {
            // Registration fails on insecure origins and in private windows. Not fatal:
            // the in-app bell keeps working, only the OS-level alert is lost.
            console.warn('Service worker registration failed', error);
        });
    });
}
