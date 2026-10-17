const CACHE_NAME = "kasirkwu-cache-v2";
const MASTER_API_PATTERNS = ["/products", "/customers", "/pricing", "/categories", "/warehouses"];

// SW hanya menangani GET same-origin — sisanya serahkan ke browser.
const isSameOriginGet = (request, url) => request.method === "GET" && url.origin === self.location.origin;

// Navigasi/HTML tidak boleh cache-first: HTML lama menahan referensi aset lama.
const isNavigation = (request) => request.mode === "navigate" || request.destination === "document";

// Respons Inertia (partial reload) jangan pernah dilayani dari cache.
const isInertia = (request) => request.headers.get("X-Inertia") === "true";

self.addEventListener("install", () => {
    self.skipWaiting();
});

self.addEventListener("activate", (event) => {
    event.waitUntil(
        (async () => {
            const keys = await caches.keys();
            await Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key)));
            // Ambil kendali tab yang sudah terbuka agar aset basi langsung dibuang.
            await self.clients.claim();
        })()
    );
});

self.addEventListener("fetch", (event) => {
    const { request } = event;
    const url = new URL(request.url);

    if (!isSameOriginGet(request, url) || isInertia(request)) return;

    // Network-first untuk navigasi/HTML — selalu ambil HTML terbaru, cache hanya untuk offline.
    if (isNavigation(request)) {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    const copy = response.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(request, copy));
                    return response;
                })
                .catch(() => caches.match(request))
        );
        return;
    }

    // Cache-first for master data API
    if (MASTER_API_PATTERNS.some((p) => url.pathname.includes(p))) {
        event.respondWith(
            caches.open(CACHE_NAME).then((cache) =>
                fetch(event.request)
                    .then((response) => {
                        cache.put(event.request, response.clone());
                        return response;
                    })
                    .catch(() => cache.match(event.request))
            )
        );
        return;
    }

    // Network-first for transaction API
    if (url.pathname.includes('/transactions/')) {
        event.respondWith(
            fetch(event.request).catch(() => new Response(JSON.stringify({ offline: true }), {
                status: 503,
                headers: { 'Content-Type': 'application/json' },
            }))
        );
        return;
    }

    // Cache-first for static assets
    event.respondWith(
        caches.match(event.request).then((cached) => cached || fetch(event.request))
    );
});
