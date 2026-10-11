// An itinerary a guest generated before logging in. Only the preview and the claim
// token live here; the full itinerary stays on the server until the guest logs in as a
// tourist and claims it.
const KEY = 'pending_itinerary'
// Matches ItineraryController::CLAIM_DAYS on the server.
const MAX_AGE_MS = 7 * 24 * 60 * 60 * 1000

export function savePendingItinerary({ id, token, preview }) {
  try {
    localStorage.setItem(KEY, JSON.stringify({ id, token, preview, savedAt: Date.now() }))
  } catch {
    // Storage unavailable (private mode, blocked site data): the guest can't claim it later.
  }
}

export function readPendingItinerary() {
  try {
    const stored = JSON.parse(localStorage.getItem(KEY))
    if (stored?.id && stored.token && Date.now() - stored.savedAt < MAX_AGE_MS) return stored
    if (stored) localStorage.removeItem(KEY)
  } catch {
    // Unreadable or unavailable: treat as nothing pending.
  }
  return null
}

export function clearPendingItinerary() {
  try {
    localStorage.removeItem(KEY)
  } catch {
    // Nothing to clear if storage is unavailable.
  }
}

// Where a user should land after logging in or registering.
export function postAuthPath(user) {
  if (user.role === 'admin') return '/admin'
  if (user.role === 'business_owner') return '/business'
  return readPendingItinerary() ? '/plan-trip' : '/dashboard'
}
