// Itinerary PDFs downloaded on this device, kept so they open again without internet: in the app
// (My Downloads) and on public/offline.html, which the service worker shows when there is no
// connection. Each PDF belongs to the tourist account that downloaded it. The file is stored as
// bytes (an ArrayBuffer), which every browser keeps reliably in IndexedDB.
import { PDF_STORE, runStore } from './offlineItineraries'

const run = (mode, action) => runStore(PDF_STORE, mode, action)
const keyFor = (userId, itineraryId) => `${userId}:${itineraryId}`

/** Whether a response really is a PDF file (an error page or an empty body is not). */
export async function isPdfBlob(blob) {
  if (!(blob instanceof Blob) || blob.size < 5) return false
  const head = new Uint8Array(await blob.slice(0, 5).arrayBuffer())
  return String.fromCharCode(...head) === '%PDF-'
}

/**
 * Keeps an itinerary's PDF on this device for `userId`, replacing an older copy, then reads it
 * back: it resolves with the stored record only once the whole file is there.
 */
export async function savePdfCopy(userId, itinerary, blob, filename) {
  const data = await blob.arrayBuffer()
  const record = {
    key: keyFor(userId, itinerary.id),
    userId: Number(userId),
    itineraryId: Number(itinerary.id),
    title: itinerary.title || 'Itinerary',
    startDate: itinerary.start_date || null,
    numDays: itinerary.num_days || null,
    towns: (itinerary.municipalities || []).map((town) => town.name).filter(Boolean),
    filename,
    size: data.byteLength,
    savedAt: new Date().toISOString(),
    data,
  }
  await run('readwrite', (store) => store.put(record))

  const stored = await run('readonly', (store) => store.get(record.key))
  if (!stored || stored.data?.byteLength !== record.size) throw new Error('The PDF was not stored completely.')
  return stored
}

/** This tourist's downloaded PDFs on this device, newest first. */
export async function listPdfCopies(userId) {
  const records = await run('readonly', (store) => store.index('userId').getAll(Number(userId)))
  return records.sort((a, b) => b.savedAt.localeCompare(a.savedAt))
}

export async function hasPdfCopy(userId, itineraryId) {
  try {
    return (await run('readonly', (store) => store.count(keyFor(userId, itineraryId)))) > 0
  } catch {
    return false
  }
}

export async function removePdfCopy(userId, itineraryId) {
  await run('readwrite', (store) => store.delete(keyFor(userId, itineraryId)))
}

export const pdfBlobOf = (record) => new Blob([record.data], { type: 'application/pdf' })

/**
 * Opens a stored PDF in a new tab (the browser's or phone's PDF viewer); no request is made.
 * Must run in the click. False when the browser refused the new tab.
 */
export function openPdfCopy(record) {
  const url = URL.createObjectURL(pdfBlobOf(record))
  const win = window.open(url, '_blank')
  setTimeout(() => URL.revokeObjectURL(url), 5 * 60 * 1000)
  return Boolean(win)
}

/** "1.2 MB" */
export function fileSize(bytes) {
  if (!bytes) return ''
  return bytes >= 1024 * 1024 ? `${(bytes / (1024 * 1024)).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`
}
