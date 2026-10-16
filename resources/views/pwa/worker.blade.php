@php
$scopePath = parse_url($settings['scope'], PHP_URL_PATH);
$offlineUrl = route('pwa.'.$settings['kind'].'.offline');
$icons = [asset('pwa/icons/'.$settings['kind'].'-192.png'), asset('pwa/icons/'.$settings['kind'].'-512.png')];
$staticPrefixes = [rtrim(parse_url(asset('build'), PHP_URL_PATH), '/').'/', rtrim(parse_url(asset('pwa/icons'), PHP_URL_PATH), '/').'/'];
$logoPath = parse_url(asset('assets/img/mms.png'), PHP_URL_PATH);
@endphp
/* Separate scope and cache namespace for this MKM PWA. No account or approval data is cached. */
const SCOPE = @json($scopePath);
const CACHE_PREFIX = @json($cachePrefix);
const CACHE = CACHE_PREFIX + @json($version);
const OFFLINE = @json($offlineUrl);
const ICONS = @json($icons);
const STATIC_PREFIXES = @json($staticPrefixes);
const LOGO = @json($logoPath);
self.addEventListener('install', event => {
    event.waitUntil(caches.open(CACHE).then(cache => cache.addAll([OFFLINE, ...ICONS])).then(() => self.skipWaiting()));
});
self.addEventListener('activate', event => {
    event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(key => key.startsWith(CACHE_PREFIX) && key !== CACHE).map(key => caches.delete(key)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', event => {
    const request = event.request;
    const url = new URL(request.url);
    // POST/PIN/approval requests always go directly to the server and are never replayed.
    if (request.method !== 'GET' || url.origin !== self.location.origin) return;
    if (request.mode === 'navigate' && url.pathname.startsWith(SCOPE)) {
        event.respondWith(fetch(request).catch(async () => (await caches.open(CACHE)).match(OFFLINE)));
        return;
    }
    // Deliberately exclude HTML, API data, avatars, evidence, auth callbacks and CSRF forms.
    if (!['script', 'style', 'image', 'font'].includes(request.destination)) return;
    if (!(STATIC_PREFIXES.some(prefix => url.pathname.startsWith(prefix)) || url.pathname === LOGO)) return;
    event.respondWith((async () => {
        const cache = await caches.open(CACHE);
        const cached = await cache.match(request);
        if (cached) return cached;
        const response = await fetch(request);
        if (response.ok && response.type === 'basic' && !(response.headers.get('Cache-Control') || '').includes('no-store')) await cache.put(request, response.clone());
        return response;
    })());
});
