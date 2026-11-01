// Service worker do JUDOMA (ADR-017). Guarda só arquivos estáticos (CSS, JS, ícones) e a página "Sem conexão".
// Páginas com dados (alunos, chamadas, financeiro) nunca são guardadas: sempre vêm da rede.
// A versão vem do endereço de registro (sw.js?v=...), que muda a cada atualização do sistema.
const VERSION = new URL(self.location.href).searchParams.get('v') || 'dev';
const CACHE = 'judoma-static-' + VERSION;
const BASE = new URL('./', self.location.href).pathname;
const OFFLINE = BASE + 'offline.html';
const PRECACHE = [
    OFFLINE,
    BASE + 'css/sigam.css',
    BASE + 'css/modules.css',
    BASE + 'css/direct-registration.css',
    BASE + 'js/common.js',
    BASE + 'icons/icon-192.png',
    BASE + 'manifest.json',
];
const STATIC = /\/(css|js|icons)\/[^?]+\.(css|js|png)$/;

self.addEventListener('install', event => {
    event.waitUntil(caches.open(CACHE).then(cache => cache.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', event => {
    event.waitUntil(caches.keys()
        .then(keys => Promise.all(keys.filter(key => key.startsWith('judoma-') && key !== CACHE).map(key => caches.delete(key))))
        .then(() => self.clients.claim()));
});

self.addEventListener('message', event => {
    if (event.data === 'clear') {
        event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(key => key.startsWith('judoma-')).map(key => caches.delete(key)))));
    }
});

self.addEventListener('fetch', event => {
    const request = event.request;
    if (request.method !== 'GET') {
        return;
    }
    const url = new URL(request.url);
    if (url.origin !== self.location.origin) {
        return;
    }
    if (request.mode === 'navigate') {
        // Sempre da rede; sem conexão, mostra a página guardada "Sem conexão".
        event.respondWith(fetch(request).catch(() => caches.match(OFFLINE)));
        return;
    }
    if (STATIC.test(url.pathname) && url.pathname.startsWith(BASE)) {
        // Arquivo estático: responde do cache e atualiza em segundo plano.
        event.respondWith(caches.open(CACHE).then(cache => cache.match(request).then(cached => {
            const network = fetch(request).then(response => {
                if (response.ok) {
                    cache.put(request, response.clone());
                }
                return response;
            });
            if (cached) {
                network.catch(() => null);
                return cached;
            }
            return network;
        })));
    }
});
