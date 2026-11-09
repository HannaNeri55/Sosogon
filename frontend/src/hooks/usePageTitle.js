import { useEffect } from 'react'

const SITE_NAME = 'Sorsogon Tourism'

/**
 * Sets the browser tab title to "<title> | Sorsogon Tourism" while the page is open, and puts
 * the previous title back when it closes. Pass nothing (e.g. while a listing loads) to leave
 * the title as it is.
 */
export default function usePageTitle(title) {
  useEffect(() => {
    if (!title) return undefined

    const previous = document.title
    document.title = `${title} | ${SITE_NAME}`
    return () => { document.title = previous }
  }, [title])
}
