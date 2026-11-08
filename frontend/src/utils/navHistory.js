// frontend/src/utils/navHistory.js
// Which in-app page each history entry was opened from, keyed by React Router's location key.
// Kept in sessionStorage so a refresh on a detail page still knows where the visitor came from.

const STORAGE_KEY = "sosogon:nav-from";
let cache = null;
const listeners = new Set();

function read() {
  if (cache) return cache;
  try {
    cache = JSON.parse(sessionStorage.getItem(STORAGE_KEY)) || {};
  } catch {
    cache = {};
  }
  return cache;
}

/** Record that the entry `key` was opened from `from` ({ pathname, search }). */
export function rememberOrigin(key, from) {
  const map = read();
  if (map[key]) return;
  map[key] = from;
  const keys = Object.keys(map);
  if (keys.length > 200) delete map[keys[0]];
  try {
    sessionStorage.setItem(STORAGE_KEY, JSON.stringify(map));
  } catch {
    // Not saved across a refresh; this tab still remembers it.
  }
  listeners.forEach((listener) => listener());
}

/** For useSyncExternalStore: call `listener` whenever an origin is recorded. */
export function subscribeOrigins(listener) {
  listeners.add(listener);
  return () => listeners.delete(listener);
}

/** The page the entry `key` was opened from, or null when it was opened directly. */
export function originOf(key) {
  return read()[key] || null;
}

const LABELS = [
  [/^\/itineraries\/[^/]+$/, "Back to Itinerary"],
  [/^\/plan-trip$/, "Back to Trip Planner"],
  [/^\/my-itineraries$/, "Back to My Itineraries"],
  [/^\/dashboard$/, "Back to Dashboard"],
  [/^\/destinations\/[^/]+$/, "Back to Destination"],
  [/^\/destinations$/, "Back to Destinations"],
  [/^\/accommodations\/[^/]+$/, "Back to Accommodation"],
  [/^\/accommodations$/, "Back to Accommodations"],
  [/^\/food-beverage\/[^/]+$/, "Back to Restaurant"],
  [/^\/food-beverage$/, "Back to Food & Beverage"],
  [/^\/shops\/[^/]+$/, "Back to Shop"],
  [/^\/shops$/, "Back to Pasalubong Shops"],
  [/^\/events\/[^/]+$/, "Back to Event"],
  [/^\/events$/, "Back to Events"],
  [/^\/activities\/[^/]+$/, "Back to Activity"],
  [/^\/my-reviews$/, "Back to My Reviews"],
  [/^\/$/, "Back to Home"],
];

/** A "Back to …" label for returning to `pathname`, or null for pages without one. */
export function backLabelFor(pathname) {
  const match = LABELS.find(([pattern]) => pattern.test(pathname));
  return match ? match[1] : null;
}
