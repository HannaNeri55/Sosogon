import { useCallback, useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import toast from 'react-hot-toast'
import { FiBell, FiCheck, FiX } from 'react-icons/fi'
import { useAuth } from '../../contexts/AuthContext'
import { markAllRead, markRead, useNotifications } from '../../hooks/useNotifications'
import { enablePush, pushPermission } from '../../utils/pushNotifications'
import { formatShortDate } from '../../utils/helpers'

function timeAgo(iso) {
  const minutes = Math.floor((Date.now() - new Date(iso).getTime()) / 60000)
  if (minutes < 1) return 'Just now'
  if (minutes < 60) return `${minutes} min ago`
  if (minutes < 1440) return `${Math.floor(minutes / 60)} h ago`
  if (minutes < 10080) return `${Math.floor(minutes / 1440)} d ago`
  return formatShortDate(iso)
}

// A short two-note chime, made in the browser (no sound file to load).
function playChime() {
  try {
    const AudioCtx = window.AudioContext || window.webkitAudioContext
    const ctx = new AudioCtx()
    ;[880, 1320].forEach((frequency, i) => {
      const oscillator = ctx.createOscillator()
      const gain = ctx.createGain()
      const at = ctx.currentTime + i * 0.12
      oscillator.frequency.value = frequency
      gain.gain.setValueAtTime(0.0001, at)
      gain.gain.exponentialRampToValueAtTime(0.12, at + 0.02)
      gain.gain.exponentialRampToValueAtTime(0.0001, at + 0.35)
      oscillator.connect(gain).connect(ctx.destination)
      oscillator.start(at)
      oscillator.stop(at + 0.4)
    })
    setTimeout(() => ctx.close(), 1000)
  } catch {
    // Sound is a nicety: browsers may block it until the page has been clicked.
  }
}

// Browsers that already allow notifications are signed up again for whoever signs in.
let pushSyncedFor = null

function NotificationToast({ t, notification, onOpen }) {
  return (
    <div
      className={`pointer-events-auto flex w-88 max-w-[calc(100vw-2rem)] items-start gap-3 rounded-xl border border-gray-200 bg-white p-3.5 shadow-lg transition-all duration-200 ${t.visible ? 'opacity-100 translate-y-0' : 'opacity-0 -translate-y-2'}`}
      role="status"
    >
      <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary-50 text-primary-700">
        <FiBell size={16} aria-hidden="true" />
      </span>
      <button type="button" onClick={onOpen} className="min-w-0 flex-1 text-left">
        <p className="text-sm font-semibold text-gray-900">{notification.title}</p>
        <p className="mt-0.5 text-sm text-gray-600 line-clamp-2">{notification.message}</p>
      </button>
      <button type="button" onClick={() => toast.dismiss(t.id)} className="p-1 -m-1 text-gray-400 hover:text-gray-600" aria-label="Dismiss">
        <FiX size={16} />
      </button>
    </div>
  )
}

function Bell({ tone, userId }) {
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)
  const [push, setPush] = useState(pushPermission)
  const [turningOn, setTurningOn] = useState(false)

  const openNotification = useCallback((notification) => {
    markRead(notification.id)
    setOpen(false)
    if (notification.link) navigate(notification.link)
  }, [navigate])

  const announce = useCallback((fresh) => {
    playChime()
    fresh.slice(0, 3).forEach((notification) => {
      toast.custom((t) => (
        <NotificationToast t={t} notification={notification} onOpen={() => { toast.dismiss(t.id); openNotification(notification) }} />
      ), { id: notification.id, duration: 8000, position: 'bottom-right' })
    })
    if (fresh.length > 3) toast(`${fresh.length - 3} more new notifications`, { icon: '🔔', position: 'bottom-right' })
  }, [openNotification])

  const { items, unread, loaded } = useNotifications(announce)

  useEffect(() => {
    if (pushSyncedFor === userId || pushPermission() !== 'granted') return
    pushSyncedFor = userId
    enablePush({ ask: false }).catch(() => { pushSyncedFor = null })
  }, [userId])

  useEffect(() => {
    if (!open) return undefined
    const onKey = (event) => event.key === 'Escape' && setOpen(false)
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [open])

  const turnOnPush = async () => {
    setTurningOn(true)
    try {
      const result = await enablePush()
      setPush(result === 'unsupported' ? pushPermission() : result)
      if (result === 'granted') {
        pushSyncedFor = userId
        toast.success('Alerts are on. You will be notified even when this site is closed.')
      } else if (result === 'unsupported') {
        toast.error('Alerts are not available on this server yet.')
      }
    } catch {
      toast.error('Could not turn on alerts. Please try again.')
    } finally {
      setTurningOn(false)
    }
  }

  const dark = tone === 'dark'
  const label = unread > 0 ? `Notifications, ${unread} unread` : 'Notifications'

  return (
    <div className="relative">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-label={label}
        aria-expanded={open}
        className={`relative p-2 rounded-full transition-colors ${dark ? 'text-white hover:bg-white/10' : 'text-gray-600 hover:text-primary-700 hover:bg-gray-100'}`}
      >
        <FiBell size={20} aria-hidden="true" />
        {unread > 0 && (
          <span className={`absolute -top-0.5 -right-0.5 flex h-4.5 min-w-4.5 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold leading-none text-white ring-2 ${dark ? 'ring-primary-900' : 'ring-white'}`}>
            {unread > 99 ? '99+' : unread}
          </span>
        )}
      </button>

      {open && (
        <>
          <button type="button" className="fixed inset-0 z-30 cursor-default" onClick={() => setOpen(false)} aria-label="Close notifications" tabIndex={-1} />
          <div className="fixed inset-x-3 top-16 z-40 sm:absolute sm:inset-x-auto sm:right-0 sm:top-full sm:mt-2 sm:w-96 rounded-xl border border-gray-200/70 bg-white text-gray-900 shadow-(--shadow-card-hover) overflow-hidden">
            <div className="flex items-center justify-between gap-3 border-b border-gray-100 px-4 py-3">
              <p className="font-heading font-bold">Notifications</p>
              {unread > 0 && (
                <button type="button" onClick={markAllRead} className="inline-flex items-center gap-1 text-xs font-semibold text-primary-700 hover:text-primary-800">
                  <FiCheck size={13} aria-hidden="true" /> Mark all as read
                </button>
              )}
            </div>

            <ul className="max-h-[min(26rem,60vh)] overflow-y-auto divide-y divide-gray-100">
              {!loaded && <li className="px-4 py-8 text-center text-sm text-gray-500">Loading…</li>}
              {loaded && items.length === 0 && (
                <li className="px-4 py-10 text-center">
                  <FiBell className="mx-auto text-gray-300" size={28} aria-hidden="true" />
                  <p className="mt-2 text-sm text-gray-500">No notifications yet.</p>
                </li>
              )}
              {items.map((notification) => (
                <li key={notification.id}>
                  <button
                    type="button"
                    onClick={() => openNotification(notification)}
                    className={`flex w-full gap-3 px-4 py-3 text-left transition-colors hover:bg-gray-50 ${notification.read_at ? '' : 'bg-primary-50/50'}`}
                  >
                    <span className={`mt-1.5 h-2 w-2 shrink-0 rounded-full ${notification.read_at ? 'bg-transparent' : 'bg-primary-600'}`} aria-hidden="true" />
                    <span className="min-w-0 flex-1">
                      <span className={`block text-sm ${notification.read_at ? 'font-medium text-gray-700' : 'font-semibold text-gray-900'}`}>
                        {notification.title}
                        {!notification.read_at && <span className="sr-only"> (unread)</span>}
                      </span>
                      <span className="mt-0.5 block text-sm text-gray-600 line-clamp-3">{notification.message}</span>
                      <span className="mt-1 block text-xs text-gray-400">{timeAgo(notification.created_at)}</span>
                    </span>
                  </button>
                </li>
              ))}
            </ul>

            {push === 'default' && (
              <div className="border-t border-gray-100 bg-gray-50 px-4 py-3">
                <p className="text-xs text-gray-600">Get alerts on this device even when the site is closed.</p>
                <button type="button" onClick={turnOnPush} disabled={turningOn} className="btn-primary mt-2 w-full justify-center py-2 text-sm disabled:opacity-60">
                  {turningOn ? 'Turning on…' : 'Turn on alerts'}
                </button>
              </div>
            )}
            {push === 'denied' && (
              <p className="border-t border-gray-100 bg-gray-50 px-4 py-3 text-xs text-gray-500">
                Alerts are blocked for this site. To get them when the site is closed, allow notifications in your browser&apos;s site settings.
              </p>
            )}
          </div>
        </>
      )}
    </div>
  )
}

// The bell for whoever is signed in: unread count, recent notifications (who did what), a popup
// and chime when one arrives, and an option to get alerts with the site closed.
// tone: 'light' on white headers, 'dark' on the admin's dark mobile header.
export default function NotificationBell({ tone = 'light' }) {
  const { user } = useAuth()
  if (!user) return null
  return <Bell tone={tone} userId={user.id} />
}
