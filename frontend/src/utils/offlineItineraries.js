// Itinerary copies kept on this device, for places with no signal. public/offline.html reads the
// same database (keep the names and version in step), which is why it needs neither the app nor a
// login. Two stores: "itineraries" holds the HTML copies kept by Save Itinerary, "pdfs" the PDF
// files kept by Download PDF (see downloadedItineraries.js).
import { getItineraryDocument } from '../api/itineraries'

const DB_NAME = 'sosogon-offline'
const DB_VERSION = 2
const STORE = 'itineraries'
export const PDF_STORE = 'pdfs'

export function openOfflineDb() {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, DB_VERSION)
    request.onupgradeneeded = () => {
      const db = request.result
      if (!db.objectStoreNames.contains(STORE)) db.createObjectStore(STORE, { keyPath: 'id' })
      if (!db.objectStoreNames.contains(PDF_STORE)) db.createObjectStore(PDF_STORE, { keyPath: 'key' }).createIndex('userId', 'userId')
    }
    request.onsuccess = () => resolve(request.result)
    request.onerror = () => reject(request.error)
  })
}

/** Runs one request on a store and resolves once its transaction has been written. */
export async function runStore(storeName, mode, action) {
  const db = await openOfflineDb()
  try {
    return await new Promise((resolve, reject) => {
      const tx = db.transaction(storeName, mode)
      const request = action(tx.objectStore(storeName))
      tx.oncomplete = () => resolve(request.result)
      tx.onerror = () => reject(tx.error)
      tx.onabort = () => reject(tx.error)
    })
  } finally {
    db.close()
  }
}

const run = (mode, action) => runStore(STORE, mode, action)

export async function hasOfflineCopy(id) {
  try {
    return (await run('readonly', (store) => store.count(Number(id)))) > 0
  } catch {
    return false
  }
}

/** Downloads the itinerary's document and keeps it on this device, replacing any older copy. */
export async function keepOfflineCopy(itinerary) {
  const { html, dates } = await getItineraryDocument(itinerary.id)
  await run('readwrite', (store) =>
    store.put({
      id: Number(itinerary.id),
      title: itinerary.title,
      dates: dates || null,
      numDays: itinerary.num_days,
      keptAt: new Date().toISOString(),
      html,
    })
  )
}

/** After an edit: a copy on this device follows the itinerary, when there is one. */
export async function refreshOfflineCopy(itinerary) {
  if (await hasOfflineCopy(itinerary.id)) await keepOfflineCopy(itinerary)
}

export async function removeOfflineCopy(id) {
  await run('readwrite', (store) => store.delete(Number(id)))
}

/** Logging out on purpose also clears this device's copies and PDFs (it may be a shared device). */
export async function clearOfflineCopies() {
  await run('readwrite', (store) => store.clear())
  await runStore(PDF_STORE, 'readwrite', (store) => store.clear())
}
