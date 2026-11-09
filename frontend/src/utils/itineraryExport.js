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
