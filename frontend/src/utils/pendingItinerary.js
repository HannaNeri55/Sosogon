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

// A guest's planner answers, saved when they press Generate and are asked to log in first; the
// planner restores them after login and generates the itinerary for their account.
const DRAFT_KEY = 'pending_trip_draft'
const DRAFT_MAX_AGE_MS = 24 * 60 * 60 * 1000

export function saveTripDraft({ form, payload }) {
  try {
    localStorage.setItem(DRAFT_KEY, JSON.stringify({ form, payload, savedAt: Date.now() }))
  } catch {
    // Storage unavailable: the guest re-enters their answers after logging in.
  }
}

export function readTripDraft() {
  try {
    const stored = JSON.parse(localStorage.getItem(DRAFT_KEY))
    if (stored?.form && stored.payload && Date.now() - stored.savedAt < DRAFT_MAX_AGE_MS) return stored
    if (stored) localStorage.removeItem(DRAFT_KEY)
  } catch {
    // Unreadable or unavailable: no draft.
  }
  return null
}

export function clearTripDraft() {
  try {
    localStorage.removeItem(DRAFT_KEY)
  } catch {
    // Nothing to clear if storage is unavailable.
  }
}

// Where a user should land after logging in or registering.
export function postAuthPath(user) {
  // Suspended or deactivated accounts can only see their Account Health page.
  if (['suspended', 'deactivated'].includes(user.account_state)) return '/account/status'
  if (user.role === 'admin') return '/admin'
  if (user.role === 'business_owner') return '/business'
  return readPendingItinerary() || readTripDraft() ? '/plan-trip' : '/dashboard'
}
