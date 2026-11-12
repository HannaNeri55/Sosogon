import { useEffect } from 'react'
import toast from 'react-hot-toast'

// Must match SANCTUM_IDLE_TIMEOUT on the server, which rejects the token after the same
// stretch of inactivity (covers tabs that were asleep when this timer should have fired).
const IDLE_MS = 30 * 60 * 1000
const WARNING_MS = 60 * 1000
const CHECK_MS = 10 * 1000
const ACTIVITY_EVENTS = ['mousemove', 'keydown', 'click', 'scroll', 'touchstart']
// The browser's tabs share one account, so they share the time of the last interaction too:
// working in one tab keeps the others signed in.
const LAST_ACTIVITY_KEY = 'last_activity'

function readLastActivity() {
  try {
    return Number(localStorage.getItem(LAST_ACTIVITY_KEY)) || 0
  } catch {
    return 0
  }
}

function writeLastActivity(time) {
  try {
    localStorage.setItem(LAST_ACTIVITY_KEY, String(time))
  } catch {
    // Storage blocked: this tab still counts its own activity.
  }
}

// Logs admins and business owners out after 30 minutes without any interaction in any tab,
// warning them a minute before. Tourists are not affected.
export default function useIdleLogout(user, onIdle) {
  const enabled = user?.role === 'admin' || user?.role === 'business_owner'

  useEffect(() => {
    if (!enabled) return undefined

    let ownLast = Date.now()
    let warningToast = null
    writeLastActivity(ownLast)

    const dismissWarning = () => {
      if (warningToast) {
        toast.dismiss(warningToast)
        warningToast = null
      }
    }

    const record = () => {
      // mousemove and scroll fire constantly; noting them once a second is plenty.
      const now = Date.now()
      if (now - ownLast < 1000) return
      ownLast = now
      writeLastActivity(now)
      dismissWarning()
    }

    const check = () => {
      const idle = Date.now() - Math.max(ownLast, readLastActivity())
      if (idle >= IDLE_MS) {
        dismissWarning()
        onIdle()
      } else if (idle >= IDLE_MS - WARNING_MS) {
        warningToast ??= toast("You'll be logged out in 1 minute due to inactivity.", { duration: WARNING_MS })
      } else {
        dismissWarning()
      }
    }

    const timer = setInterval(check, CHECK_MS)
    ACTIVITY_EVENTS.forEach((event) => window.addEventListener(event, record, { passive: true }))

    return () => {
      clearInterval(timer)
      dismissWarning()
      ACTIVITY_EVENTS.forEach((event) => window.removeEventListener(event, record))
    }
  }, [enabled, onIdle])
}
