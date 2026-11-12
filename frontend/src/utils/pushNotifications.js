// Browser push notifications: lets a signed-in user see new notifications on their computer or
// phone even when the site is closed. The service worker (public/sw.js) shows them.
import { getPushKey, removePushSubscription, savePushSubscription } from '../api/notifications'

export function pushSupported() {
  return typeof window !== 'undefined' && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window
}

// 'unsupported' | 'denied' (blocked in the browser) | 'default' (not asked yet) | 'granted'
export function pushPermission() {
  return pushSupported() ? Notification.permission : 'unsupported'
}

function keyBytes(base64Url) {
  const base64 = (base64Url + '='.repeat((4 - (base64Url.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/')
  return Uint8Array.from(atob(base64), (c) => c.charCodeAt(0))
}

async function registration() {
  // Production registers it on load (main.jsx); registering again returns the same worker.
  await navigator.serviceWorker.register('/sw.js')
  return navigator.serviceWorker.ready
}

// Signs this browser up for the signed-in user. Asks for permission only when ask is true.
// Returns the resulting permission ('granted' when push is on).
export async function enablePush({ ask = true } = {}) {
  if (!pushSupported()) return 'unsupported'
  const permission = ask && Notification.permission === 'default' ? await Notification.requestPermission() : Notification.permission
  if (permission !== 'granted') return permission

  const publicKey = await getPushKey()
  if (!publicKey) return 'unsupported'

  const reg = await registration()
  const subscription = (await reg.pushManager.getSubscription())
    ?? (await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(publicKey) }))
  await savePushSubscription(subscription.toJSON())
  return 'granted'
}

// On logout: stop sending this account's notifications to this browser (the next user to sign
// in here signs it up for themselves). The browser keeps its permission.
export async function stopPushForThisBrowser() {
  if (!pushSupported()) return
  const reg = await navigator.serviceWorker.getRegistration()
  const subscription = await reg?.pushManager.getSubscription()
  if (subscription) await removePushSubscription(subscription.endpoint)
}
