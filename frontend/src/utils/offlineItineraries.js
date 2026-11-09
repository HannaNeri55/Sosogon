// Itinerary copies kept on this device, for places with no signal. public/offline.html reads the
// same database (keep the names in step), which is why it needs neither the app nor a login.
import { getItineraryDocument } from '../api/itineraries'

const DB_NAME = 'sosogon-offline'
const STORE = 'itineraries'

function openDb() {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, 1)
    request.onupgradeneeded = () => request.result.createObjectStore(STORE, { keyPath: 'id' })
    request.onsuccess = () => resolve(request.result)
    request.onerror = () => reject(request.error)
  })
}

async function run(mode, action) {
  const db = await openDb()
  try {
    return await new Promise((resolve, reject) => {
      const tx = db.transaction(STORE, mode)
      const request = action(tx.objectStore(STORE))
      tx.oncomplete = () => resolve(request.result)
      tx.onerror = () => reject(tx.error)
      tx.onabort = () => reject(tx.error)
    })
  } finally {
    db.close()
  }
}

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

/** Logging out on purpose also clears this device's copies (it may be a shared device). */
export async function clearOfflineCopies() {
  await run('readwrite', (store) => store.clear())
}
