// One shared notification feed for every bell on the page: checks for new notifications every
// 15 seconds (and right away when the tab comes back or a push arrives), keeps the unread count
// in the tab title, and hands notifications that just arrived to the bell to announce.
import { useEffect, useSyncExternalStore } from 'react'
import { getNotifications, markAllNotificationsRead, markNotificationRead } from '../api/notifications'

const POLL_MS = 15000
const HIDDEN_POLL_MS = 60000
const EMPTY = { items: [], unread: 0, loaded: false }

let state = EMPTY
let seen = null
let timer = null
let lastPoll = 0
let titleObserver = null
const listeners = new Set()
let announce = null

function emit(next) {
  state = { ...state, ...next }
  showCountInTitle()
  listeners.forEach((listener) => listener())
}

// "(3) Page title", like a messaging app, kept when a page changes its own title.
function showCountInTitle() {
  const base = document.title.replace(/^\(\d+\+?\)\s/, '')
  const title = state.unread > 0 ? `(${state.unread > 99 ? '99+' : state.unread}) ${base}` : base
  if (document.title !== title) document.title = title
}

async function refresh() {
  lastPoll = Date.now()
  try {
    const data = await getNotifications()
    // The first load only fills the bell; later ones announce what is new since then.
    const fresh = seen ? data.notifications.filter((n) => !n.read_at && !seen.has(n.id)) : []
    seen = new Set([...(seen ?? []), ...data.notifications.map((n) => n.id)])
    emit({ items: data.notifications, unread: data.unread, loaded: true })
    if (fresh.length && announce) announce(fresh)
  } catch {
    // Offline or signed out: the next check tries again.
  }
}

function tick() {
  const wait = document.visibilityState === 'visible' ? POLL_MS : HIDDEN_POLL_MS
  if (Date.now() - lastPoll >= wait - 500) refresh()
}

function onVisible() {
  if (document.visibilityState === 'visible') refresh()
}

function onWorkerMessage(event) {
  if (event.data?.type === 'notification') refresh()
}

function start() {
  refresh()
  timer = setInterval(tick, POLL_MS)
  document.addEventListener('visibilitychange', onVisible)
  navigator.serviceWorker?.addEventListener('message', onWorkerMessage)
  const title = document.querySelector('title')
  if (title) {
    titleObserver = new MutationObserver(showCountInTitle)
    titleObserver.observe(title, { childList: true })
  }
}

function stop() {
  clearInterval(timer)
  document.removeEventListener('visibilitychange', onVisible)
  navigator.serviceWorker?.removeEventListener('message', onWorkerMessage)
  titleObserver?.disconnect()
  titleObserver = null
  seen = null
  state = EMPTY
  showCountInTitle()
}

function subscribe(listener) {
  listeners.add(listener)
  if (listeners.size === 1) start()
  return () => {
    listeners.delete(listener)
    if (listeners.size === 0) stop()
  }
}

export async function markRead(id) {
  const target = state.items.find((n) => n.id === id)
  if (!target || target.read_at) return
  emit({
    items: state.items.map((n) => (n.id === id ? { ...n, read_at: new Date().toISOString() } : n)),
    unread: Math.max(0, state.unread - 1),
  })
  await markNotificationRead(id).catch(() => {})
}

export async function markAllRead() {
  if (state.unread === 0) return
  const now = new Date().toISOString()
  emit({ items: state.items.map((n) => ({ ...n, read_at: n.read_at ?? now })), unread: 0 })
  await markAllNotificationsRead().catch(() => {})
}

// onNew(notifications) is called for notifications that arrive while the page is open. With two
// bells on a page (mobile and desktop headers) the last one mounted announces, so only once.
export function useNotifications(onNew) {
  const snapshot = useSyncExternalStore(subscribe, () => state)

  useEffect(() => {
    if (!onNew) return undefined
    announce = onNew
    return () => {
      if (announce === onNew) announce = null
    }
  }, [onNew])

  return snapshot
}
