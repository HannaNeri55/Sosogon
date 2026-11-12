// Shows the offline itineraries page when a page can't load because there is no connection.
// It stays out of everything else: while online the site loads as usual, nothing from the app
// or the API is cached, and no stale page can be served.
// It also shows push notifications (new registrations, submissions, decisions, reviews) on the
// user's computer or phone, even with the site closed.
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

// A notification from the server: { id, title, body, url }. Open tabs are told so their bell
// updates right away; the system notification is skipped when the site is already in front.
self.addEventListener('push', (event) => {
  let data = {}
  try {
    data = event.data ? event.data.json() : {}
  } catch {
    data = { body: event.data?.text() }
  }

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
      windows.forEach((client) => client.postMessage({ type: 'notification' }))
      if (windows.some((client) => client.visibilityState === 'visible' && client.focused)) return undefined

      return self.registration.showNotification(data.title || 'SOSOGON', {
        body: data.body || '',
        icon: LOGO,
        badge: LOGO,
        tag: data.id || undefined,
        data: { url: data.url || '/' },
      })
    })
  )
})

// Clicking it opens the page it is about, in a tab of the site that is already open if any.
self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const url = new URL(event.notification.data?.url || '/', self.location.origin).href

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
      const tab = windows.find((client) => new URL(client.url).origin === self.location.origin)
      if (tab) return tab.navigate(url).then((client) => (client ?? tab).focus()).catch(() => self.clients.openWindow(url))
      return self.clients.openWindow(url)
    })
  )
})
