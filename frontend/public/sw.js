// Shows the offline itineraries page when a page can't load because there is no connection.
// It stays out of everything else: while online the site loads as usual, nothing from the app
// or the API is cached, and no stale page can be served.
const CACHE = 'sosogon-offline-v1'
const OFFLINE_PAGE = '/offline.html'
const LOGO = '/images/Logo.png'

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE).then((cache) => cache.addAll([OFFLINE_PAGE, LOGO])).then(() => self.skipWaiting())
  )
})

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((names) => Promise.all(names.filter((name) => name !== CACHE).map((name) => caches.delete(name))))
      .then(() => self.clients.claim())
  )
})

self.addEventListener('fetch', (event) => {
  const { request } = event
  const url = new URL(request.url)

  if (request.mode === 'navigate') {
    event.respondWith(fetch(request).catch(() => caches.match(OFFLINE_PAGE)))
  } else if (url.origin === self.location.origin && url.pathname === LOGO) {
    event.respondWith(fetch(request).catch(() => caches.match(LOGO)))
  }
})
