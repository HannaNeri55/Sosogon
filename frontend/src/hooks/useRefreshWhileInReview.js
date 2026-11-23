// While an owner's listing waits for the Tourism Office, its page reloads the listing by itself
// when a new notification arrives (an approval or a request for changes), when the tourist comes
// back to the tab, and every 30 seconds as a fallback, so the new status shows without a manual
// refresh. Only while it's in review: editing is locked then, so nothing being typed is lost.
import { useEffect, useRef } from 'react'
import { NOTIFICATIONS_ARRIVED } from './useNotifications'

const FALLBACK_MS = 30000

export default function useRefreshWhileInReview(inReview, reload) {
  const reloadRef = useRef(reload)
  useEffect(() => {
    reloadRef.current = reload
  })

  useEffect(() => {
    if (!inReview) return undefined
    const again = () => {
      Promise.resolve(reloadRef.current()).catch(() => {})
    }
    const onVisible = () => {
      if (document.visibilityState === 'visible') again()
    }
    window.addEventListener(NOTIFICATIONS_ARRIVED, again)
    document.addEventListener('visibilitychange', onVisible)
    const timer = setInterval(() => {
      if (document.visibilityState === 'visible') again()
    }, FALLBACK_MS)

    return () => {
      window.removeEventListener(NOTIFICATIONS_ARRIVED, again)
      document.removeEventListener('visibilitychange', onVisible)
      clearInterval(timer)
    }
  }, [inReview])
}
