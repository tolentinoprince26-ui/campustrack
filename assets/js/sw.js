'use strict';

// CampusTrack PWA Service Worker

const CACHE_NAME = 'campustrack-static-v1';

const SCOPE_PATH = new URL(
    self.registration.scope
).pathname;

const OFFLINE_URL = new URL(
    'offline.html',
    self.registration.scope
).href;

// Only public files are pre-cached.

const PRE_CACHE = [

    OFFLINE_URL,

    new URL(
        'assets/icons/icon-192.png',
        self.registration.scope
    ).href,

    new URL(
        'assets/icons/icon-512.png',
        self.registration.scope
    ).href

];

// INSTALL SERVICE WORKER

self.addEventListener('install', event => {

    event.waitUntil((async () => {

        const cache = await caches.open(
            CACHE_NAME
        );

        await cache.addAll(PRE_CACHE);

        await self.skipWaiting();

    })());

});

// ACTIVATE SERVICE WORKER

self.addEventListener('activate', event => {

    event.waitUntil((async () => {

        // Remove older CampusTrack static caches.

        const keys = await caches.keys();

        await Promise.all(
            keys
                .filter(name =>
                    name.startsWith(
                        'campustrack-static-'
                    ) &&
                    name !== CACHE_NAME
                )
                .map(name =>
                    caches.delete(name)
                )
        );

        await self.clients.claim();

    })());

});

// HANDLE REQUESTS

self.addEventListener('fetch', event => {

    const request = event.request;

    // Do not intercept form submissions or
    // other non-GET requests.

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (
        url.origin !== self.location.origin ||
        !url.pathname.startsWith(SCOPE_PATH)
    ) {
        return;
    }

    // HTML and PHP pages always require the network.
    // Never store private page responses.

    if (request.mode === 'navigate') {

        event.respondWith((async () => {

            try {

                return await fetch(request);

            } catch (_) {

                return (
                    await caches.match(OFFLINE_URL)
                ) || Response.error();

            }

        })());

        return;
    }

    // Only cache known public static assets.

    const path = url.pathname.slice(
        SCOPE_PATH.length
    );

    const publicFolders = [
        'assets/css/',
        'assets/js/',
        'assets/icons/'
    ];

    const isPublicAsset = publicFolders.some(
        folder => path.startsWith(folder)
    );

    if (!isPublicAsset) {
        return;
    }

    // Network-first static asset caching.

    event.respondWith((async () => {

        try {

            const response = await fetch(request);

            if (
                response.ok &&
                response.type === 'basic' &&
                !url.search
            ) {

                const cache = await caches.open(
                    CACHE_NAME
                );

                await cache.put(
                    request,
                    response.clone()
                );
            }

            return response;

        } catch (_) {

            return (
                await caches.match(request)
            ) || Response.error();

        }

    })());

});
