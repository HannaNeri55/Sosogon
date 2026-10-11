import { useEffect } from 'react'
import toast from 'react-hot-toast'

// Must match SANCTUM_IDLE_TIMEOUT on the server, which rejects the token after the same
// stretch of inactivity (covers tabs that were asleep when this timer should have fired).
const IDLE_MS = 30 * 60 * 1000
const WARNING_MS = 60 * 1000
const ACTIVITY_EVENTS = ['mousemove', 'keydown', 'click', 'scroll', 'touchstart']

// Logs admins and business owners out after 30 minutes without any interaction,
// warning them a minute before. Tourists are not affected.
export default function useIdleLogout(user, onIdle) {
  const enabled = user?.role === 'admin' || user?.role === 'business_owner'

  useEffect(() => {
    if (!enabled) return undefined

    let warningTimer
    let logoutTimer
    let warningToast = null
    let lastReset = 0

    const reset = () => {
      // mousemove and scroll fire constantly; restarting the timers once a second is plenty.
      const now = Date.now()
      if (now - lastReset < 1000) return
      lastReset = now

      clearTimeout(warningTimer)
      clearTimeout(logoutTimer)
      if (warningToast) {
        toast.dismiss(warningToast)
        warningToast = null
      }
      warningTimer = setTimeout(() => {
        warningToast = toast("You'll be logged out in 1 minute due to inactivity.", { duration: WARNING_MS })
      }, IDLE_MS - WARNING_MS)
      logoutTimer = setTimeout(onIdle, IDLE_MS)
    }

    reset()
    ACTIVITY_EVENTS.forEach((event) => window.addEventListener(event, reset, { passive: true }))

    return () => {
      clearTimeout(warningTimer)
      clearTimeout(logoutTimer)
      if (warningToast) toast.dismiss(warningToast)
      ACTIVITY_EVENTS.forEach((event) => window.removeEventListener(event, reset))
    }
  }, [enabled, onIdle])
}
