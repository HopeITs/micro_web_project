/*
 * Micro Projects service worker (F1, docs/planning/ux-borrowed-features-2026-11.md).
 *
 * What it keeps on the device: only the app's shell files (built CSS/JS, fonts, logos, icons) and one
 * offline page. It never stores a page, a Livewire answer or any other response that can hold
 * people's data: those always go to the network. With no network, a page request gets the offline
 * page; nothing is saved or queued offline (online operation with draft recovery, AGENTS.md).
 */
const VERSION = 'mp-shell-v2';
const SCOPE = new URL(self.registration.scope);
const OFFLINE_URL = new URL('offline.html', SCOPE).href;
const PRECACHE = [OFFLINE_URL];
const SHELL_PATHS = ['build/', 'assets/fonts/', 'assets/brand/', 'icons/'].map((path) => new URL(path, SCOPE).pathname);

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(VERSION).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key.startsWith('mp-shell-') && key !== VERSION).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

// A new build (app-<hash>.css) replaces the previous one, so old builds do not pile up on the device.
async function dropOlderBuilds(cache, url) {
    const match = url.pathname.match(/^(.*\/build\/assets\/[^/]+?)-[A-Za-z0-9_-]{6,}(\.[a-z0-9]+)$/);
    if (!match) return;
    const [, stem, extension] = match;
    for (const key of await cache.keys()) {
        const path = new URL(key.url).pathname;
        if (path !== url.pathname && path.startsWith(stem + '-') && path.endsWith(extension)) await cache.delete(key);
    }
}

function isShellFile(url) {
    return url.origin === SCOPE.origin && SHELL_PATHS.some((path) => url.pathname.startsWith(path));
}

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;
    const url = new URL(request.url);

    // Pages: always the network; the offline page only when the network is unreachable.
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match(OFFLINE_URL)));
        return;
    }

    // Shell files: built files carry a content hash, so the first copy stays right; others refresh in the background.
    if (isShellFile(url)) {
        event.respondWith(caches.open(VERSION).then(async (cache) => {
            const cached = await cache.match(request);
            const network = fetch(request).then((response) => {
                if (response.ok && response.type === 'basic') {
                    cache.put(request, response.clone());
                    event.waitUntil(dropOlderBuilds(cache, url));
                }
                return response;
            });
            if (cached) {
                event.waitUntil(network.catch(() => undefined));
                return cached;
            }
            return network;
        }));
    }
    // Everything else (Livewire, search, exports, files): untouched, straight to the network.
});
