import { useEffect } from 'react'
import { useLocation } from 'react-router-dom'

const ENDPOINT = `${(import.meta.env.VITE_API_URL ?? '').replace(/\/+$/, '')}/api/v1/page-views`

// The page last counted. Shared by every render, so React running an effect twice (StrictMode in
// development) or a change of query only (filters, result pages) doesn't count another view.
let lastCounted = null

/**
 * Counts one page view each time a visitor opens a page of the public site, for the admin's
 * visitor figures. The server decides what is a real public page; a failed send is ignored and
 * never bothers the visitor.
 */
export default function usePageViews() {
  const { pathname } = useLocation()

  useEffect(() => {
    if (pathname === lastCounted) return
    lastCounted = pathname
    fetch(ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ path: pathname }),
      credentials: 'omit',
      keepalive: true,
    }).catch(() => {})
  }, [pathname])
}
