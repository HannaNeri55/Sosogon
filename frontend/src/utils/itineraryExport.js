// Getting an itinerary out of the browser: PDF files, the print window and the system share sheet.

export const pdfFilename = (title) => {
  const slug = (title || 'itinerary').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')
  return `${slug || 'itinerary'}-sosogon.pdf`
}

export function saveBlobAs(blob, filename) {
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()
  setTimeout(() => URL.revokeObjectURL(url), 60000)
}

/**
 * Lets the browser itself open `url`. A file the server sends as an attachment goes to the
 * browser's downloads (on Android, the Downloads folder) and the page stays where it is.
 */
export function startBrowserDownload(url) {
  const link = document.createElement('a')
  link.href = url
  link.rel = 'noopener'
  document.body.appendChild(link)
  link.click()
  link.remove()
}

/**
 * The app whose built-in browser this is (Messenger, Facebook, Instagram…), or null in a regular
 * browser. Built-in browsers often can't save files, so downloads are sent to a real browser.
 */
export function inAppBrowserName(ua = navigator.userAgent) {
  if (/FBAN|FBAV|FB_IAB|FB4A|FBIOS/i.test(ua)) return /Messenger|Orca/i.test(ua) ? 'Messenger' : 'Facebook'
  if (/Instagram/i.test(ua)) return 'Instagram'
  if (/\bLine\//i.test(ua)) return 'LINE'
  if (/MicroMessenger/i.test(ua)) return 'WeChat'
  if (/musical_ly|BytedanceWebview|TikTok/i.test(ua)) return 'TikTok'
  if (/Snapchat/i.test(ua)) return 'Snapchat'
  if (/Android.*; wv\)/i.test(ua)) return 'this app'
  return null
}

export const isAndroid = (ua = navigator.userAgent) => /Android/i.test(ua)

/** An Android link that opens `url` in Chrome; where Chrome is missing, it opens where it is. */
export function chromeIntentUrl(url) {
  const { protocol, host, pathname, search } = new URL(url)
  return `intent://${host}${pathname}${search}#Intent;scheme=${protocol.slice(0, -1)};package=com.android.chrome;S.browser_fallback_url=${encodeURIComponent(url)};end`
}

/**
 * Opens the tab the document will print from. Must run directly in the click, before any
 * waiting, or pop-up blockers refuse it. Null when blocked.
 */
export function openPrintWindow() {
  const win = window.open('', '_blank')
  if (win) win.document.write('<!doctype html><title>Preparing your itinerary…</title><p style="font-family:sans-serif;padding:24px">Preparing your itinerary…</p>')
  return win
}

/** Writes the standalone itinerary document into that tab and opens the print dialog. */
export async function printDocument(win, html) {
  win.document.open()
  win.document.write(html)
  win.document.close()
  await Promise.all([...win.document.images].map((image) => image.decode().catch(() => {})))
  win.focus()
  win.print()
}

export const canShareNatively = () => typeof navigator !== 'undefined' && typeof navigator.share === 'function'

export function canShareFile(file) {
  return canShareNatively() && typeof navigator.canShare === 'function' && navigator.canShare({ files: [file] })
}

/** Copies text, also where the Clipboard API is missing (plain http). True when it worked. */
export async function copyText(text) {
  try {
    await navigator.clipboard.writeText(text)
    return true
  } catch {
    const field = document.createElement('textarea')
    field.value = text
    field.setAttribute('readonly', '')
    field.style.position = 'fixed'
    field.style.opacity = '0'
    document.body.appendChild(field)
    field.select()
    try {
      return document.execCommand('copy')
    } catch {
      return false
    } finally {
      field.remove()
    }
  }
}
