/*
 * OpenBookCase service worker.
 *
 * This worker exists ONLY to make the site installable as a PWA ("Add to home
 * screen" / desktop install). It is deliberately NETWORK-ONLY: it caches
 * nothing and forwards every request straight to the server, so all reading and
 * editing of entries always happens remotely against the live app and users
 * never see stale content. (Network-only also keeps it safe during
 * `encore dev --watch` — it can never serve a desynced build manifest.)
 *
 * UPDATES: bump SW_VERSION below whenever you want to guarantee clients pick up
 * a new worker. Any byte change to this file makes the browser install the new
 * worker; skipWaiting() + clients.claim() activate it immediately, and the page
 * registration script reloads open tabs once the new worker takes control — so
 * a deployed change is recognised the next time someone opens the app or page.
 */
const SW_VERSION = '2026-06-15.7';

self.addEventListener('install', () => {
    // Don't wait for old tabs to close — take over as soon as we're installed.
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    // Drop any caches left over from a previous (caching) worker version, then
    // start controlling already-open pages immediately.
    event.waitUntil(
        (async () => {
            const keys = await caches.keys();
            await Promise.all(keys.map((k) => caches.delete(k)));
            await self.clients.claim();
        })()
    );
});

self.addEventListener('fetch', () => {
    // No-op: let the browser perform its normal network request. A fetch
    // listener is required for installability; we intentionally add no caching.
});
