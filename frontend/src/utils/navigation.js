// Turn-by-turn navigation helpers: the route and its steps from OSRM, where the tourist is
// along it, what the next maneuver is, whether they've left the route or arrived. Pure
// functions on plain data (no React, no GPS), so the rules are easy to test. Everything runs in
// the browser; positions are never sent to SOSOGON's server.

const OSRM_URL = 'https://router.project-osrm.org/route/v1/driving'
const EARTH_M = 6371000
const RAD = Math.PI / 180

// ---------- thresholds (metres / milliseconds) ----------
/** Fixes less accurate than this don't move progress or trigger recalculation. */
export const MAX_USABLE_ACCURACY_M = 100
/** How far from the route a fix must be before it counts as off route (grows with poor accuracy). */
export const OFF_ROUTE_MIN_M = 50
/** Off-route fixes needed in a row, and over at least this long, before recalculating. */
export const OFF_ROUTE_FIXES = 3
export const OFF_ROUTE_MIN_MS = 8000
/** Minimum time between route requests. */
export const RECALC_COOLDOWN_MS = 20000
/** Within this of the destination (or of the route's end on the road) counts as being there. */
export const ARRIVAL_RADIUS_M = 35
/** Arrival needs this many consecutive qualifying fixes, over at least this long. */
export const ARRIVAL_FIXES = 2
export const ARRIVAL_MIN_MS = 3000

/** Great-circle distance in metres between {lat, lng} points. */
export function distanceM(a, b) {
  const dLat = (b.lat - a.lat) * RAD
  const dLng = (b.lng - a.lng) * RAD
  const h = Math.sin(dLat / 2) ** 2 + Math.cos(a.lat * RAD) * Math.cos(b.lat * RAD) * Math.sin(dLng / 2) ** 2
  return 2 * EARTH_M * Math.asin(Math.min(1, Math.sqrt(h)))
}

/**
 * Asks OSRM for a driving route with turn-by-turn steps.
 * @returns {Promise<Route>} path: [lat, lng][]; cum: metres along the path at each point;
 *   steps: { type, modifier, exit, name, ref, distance, duration, start (metres along) };
 *   distance (m) and duration (s) for the whole route.
 */
export async function fetchNavigationRoute(origin, destination, { signal } = {}) {
  const response = await fetch(
    `${OSRM_URL}/${origin.lng},${origin.lat};${destination.lng},${destination.lat}?overview=full&geometries=geojson&steps=true`,
    { signal },
  )
  if (!response.ok) throw new Error(`Routing server answered ${response.status}`)
  const data = await response.json()
  const route = data?.code === 'Ok' ? data.routes?.[0] : null
  if (!route?.geometry?.coordinates?.length || !route.legs?.[0]?.steps?.length) {
    throw new Error('No driving route found to this place')
  }
  return buildRoute(route)
}

/** Turns an OSRM route into the structure the navigation uses. Exported for tests. */
export function buildRoute(osrmRoute) {
  const path = osrmRoute.geometry.coordinates.map(([lng, lat]) => [lat, lng])
  const cum = [0]
  for (let i = 1; i < path.length; i++) {
    cum.push(cum[i - 1] + distanceM({ lat: path[i - 1][0], lng: path[i - 1][1] }, { lat: path[i][0], lng: path[i][1] }))
  }
  // Each step starts at its maneuver point; find it along the path, moving forward only.
  let searchFrom = 0
  const steps = osrmRoute.legs[0].steps.map((step) => {
    const [lng, lat] = step.maneuver.location
    const at = nearestOnPath(path, cum, { lat, lng }, searchFrom, path.length - 1)
    searchFrom = at.index
    return {
      type: step.maneuver.type,
      modifier: step.maneuver.modifier || '',
      exit: step.maneuver.exit || null,
      bearingAfter: step.maneuver.bearing_after ?? null,
      name: step.name || '',
      ref: step.ref || '',
      distance: step.distance,
      duration: step.duration,
      start: at.along,
      location: { lat, lng },
    }
  })
  return { path, cum, steps, distance: cum[cum.length - 1], duration: osrmRoute.duration }
}

/** Closest point on path segments [from, to] to p: { index (segment start), along, offset }. */
function nearestOnPath(path, cum, p, from = 0, to = path.length - 1) {
  // Flat-earth projection around p is accurate to well under a metre at these distances.
  const kx = Math.cos(p.lat * RAD) * RAD * EARTH_M
  const ky = RAD * EARTH_M
  let best = { index: from, along: cum[from] ?? 0, offset: Infinity }
  const last = Math.min(to, path.length - 1)
  if (last === 0) return { index: 0, along: 0, offset: distanceM(p, { lat: path[0][0], lng: path[0][1] }) }
  for (let i = Math.max(0, from); i < last; i++) {
    const ax = (path[i][1] - p.lng) * kx
    const ay = (path[i][0] - p.lat) * ky
    const bx = (path[i + 1][1] - p.lng) * kx
    const by = (path[i + 1][0] - p.lat) * ky
    const dx = bx - ax
    const dy = by - ay
    const len2 = dx * dx + dy * dy
    const t = len2 ? Math.max(0, Math.min(1, -(ax * dx + ay * dy) / len2)) : 0
    const offset = Math.hypot(ax + t * dx, ay + t * dy)
    if (offset < best.offset) best = { index: i, along: cum[i] + t * (cum[i + 1] - cum[i]), offset }
  }
  return best
}

/**
 * Where a GPS fix lies on the route. Searches near the previous progress first, so a road that
 * passes the same spot twice doesn't make progress jump ahead; falls back to the whole route.
 * Progress never moves back more than a little (GPS jitter), unless the fix is far off route.
 */
export function locateOnRoute(route, fix, previousAlong = 0) {
  const { path, cum } = route
  let startIndex = 0
  while (startIndex < cum.length - 2 && cum[startIndex + 1] < previousAlong - 200) startIndex++
  let endIndex = startIndex
  while (endIndex < cum.length - 1 && cum[endIndex] < previousAlong + 1500) endIndex++
  let hit = nearestOnPath(path, cum, fix, startIndex, Math.max(endIndex, startIndex + 1))
  if (hit.offset > OFF_ROUTE_MIN_M) {
    const anywhere = nearestOnPath(path, cum, fix)
    if (anywhere.offset < hit.offset - 20) hit = anywhere
  }
  const along = hit.offset <= OFF_ROUTE_MIN_M ? Math.max(hit.along, previousAlong - 30) : hit.along
  return { along: Math.min(along, route.distance), offset: hit.offset }
}

/**
 * Navigation figures for a position along the route: the current step, the next maneuver and
 * how far to it, and what's left. Times come from OSRM's own step durations.
 */
export function progressAt(route, along) {
  const { steps } = route
  let current = 0
  while (current < steps.length - 1 && steps[current + 1].start <= along + 5) current++
  const next = steps[current + 1] || null
  const stepEnd = next ? next.start : route.distance
  const stepLength = Math.max(1, stepEnd - steps[current].start)
  const leftInStep = Math.max(0, stepEnd - along)
  let remainingS = steps[current].duration * Math.min(1, leftInStep / stepLength)
  for (let i = current + 1; i < steps.length; i++) remainingS += steps[i].duration
  return {
    stepIndex: current,
    next,
    distanceToNext: next ? leftInStep : null,
    remainingM: Math.max(0, route.distance - along),
    remainingS,
  }
}

/** Off-route tracker: true only after several clearly off-route fixes over some seconds. */
export function nextOffRouteState(state, { offset, accuracy, time }) {
  const limit = Math.max(OFF_ROUTE_MIN_M, accuracy * 1.5)
  if (accuracy > MAX_USABLE_ACCURACY_M) return { ...state, confirmed: false }
  if (offset <= limit) return { count: 0, since: null, confirmed: false }
  const since = state.since ?? time
  const count = state.count + 1
  return { count, since, confirmed: count >= OFF_ROUTE_FIXES && time - since >= OFF_ROUTE_MIN_MS }
}

/**
 * Arrival tracker: near the saved destination, or at the end of the road route (for places whose
 * pin is off the road), on several consecutive usable fixes over a few seconds.
 */
export function nextArrivalState(state, { fix, accuracy, time, destination, remainingM, offset }) {
  if (accuracy > MAX_USABLE_ACCURACY_M) return state
  const radius = Math.max(ARRIVAL_RADIUS_M, Math.min(accuracy, 60))
  const near = distanceM(fix, destination) <= radius || (remainingM <= ARRIVAL_RADIUS_M && offset <= OFF_ROUTE_MIN_M)
  if (!near) return { count: 0, since: null, arrived: false }
  const since = state.since ?? time
  const count = state.count + 1
  return { count, since, arrived: count >= ARRIVAL_FIXES && time - since >= ARRIVAL_MIN_MS }
}

// ---------- instructions ----------
const ORDINALS = ['first', 'second', 'third', 'fourth', 'fifth', 'sixth', 'seventh', 'eighth', 'ninth', 'tenth']
const COMPASS = ['north', 'northeast', 'east', 'southeast', 'south', 'southwest', 'west', 'northwest']

function roadOf(step) {
  return step.name || step.ref || ''
}

function side(modifier) {
  return modifier.includes('left') ? 'left' : modifier.includes('right') ? 'right' : ''
}

/** A plain-language instruction for an OSRM step, from its maneuver type, direction and road name. */
export function instructionFor(step, destinationName = 'your destination') {
  const road = roadOf(step)
  const onto = road ? ` onto ${road}` : ''
  const mod = step.modifier
  switch (step.type) {
    case 'depart': {
      const heading = step.bearingAfter != null ? COMPASS[Math.round(step.bearingAfter / 45) % 8] : ''
      return `Head ${heading || 'out'}${road ? ` on ${road}` : ''}`.replace('Head out on', 'Start on')
    }
    case 'arrive':
      return `Arrive at ${destinationName}`
    case 'roundabout':
    case 'rotary':
      return step.exit ? `At the roundabout, take the ${ORDINALS[step.exit - 1] || `${step.exit}th`} exit${onto}` : `Go through the roundabout${onto}`
    case 'exit roundabout':
    case 'exit rotary':
      return `Exit the roundabout${onto}`
    case 'fork':
      return `Keep ${side(mod) || 'straight'}${road ? ` toward ${road}` : ''}`
    case 'merge':
      return `Merge${side(mod) ? ` ${side(mod)}` : ''}${onto}`
    case 'on ramp':
      return `Take the ramp${side(mod) ? ` on the ${side(mod)}` : ''}${onto}`
    case 'off ramp':
      return `Take the exit${side(mod) ? ` on the ${side(mod)}` : ''}${onto}`
    case 'end of road':
      return `At the end of the road, turn ${side(mod) || mod}${onto}`
    case 'new name':
      return road ? `Continue onto ${road}` : 'Continue straight'
    case 'continue':
    case 'turn':
    default:
      if (mod === 'uturn') return `Make a U-turn${onto}`
      if (mod === 'straight' || !mod) return `Continue straight${onto}`
      if (step.type === 'continue' && mod.startsWith('slight')) return `Keep ${side(mod)}${onto}`
      if (mod.startsWith('slight')) return `Turn slightly ${side(mod)}${onto}`
      if (mod.startsWith('sharp')) return `Turn sharply ${side(mod)}${onto}`
      return `Turn ${mod}${onto}`
  }
}

/** Short spoken form for the approach ("In 300 meters, turn left onto Rizal Street"). */
export function spokenApproach(step, metres, destinationName) {
  const text = instructionFor(step, destinationName)
  if (step.type === 'arrive') return `In ${roundForSpeech(metres)}, you will arrive at ${destinationName}`
  return `In ${roundForSpeech(metres)}, ${text.charAt(0).toLowerCase()}${text.slice(1)}`
}

function roundForSpeech(metres) {
  if (metres >= 1000) return `${(Math.round(metres / 100) / 10).toString()} kilometers`
  return `${Math.max(50, Math.round(metres / 50) * 50)} meters`
}

/** "350 m", "1.2 km", "18 km". */
export function formatDistance(metres) {
  if (metres < 1000) return `${Math.max(10, Math.round(metres / 10) * 10)} m`
  return metres < 10000 ? `${(metres / 1000).toFixed(1)} km` : `${Math.round(metres / 1000)} km`
}

/** "8 min", "1 hr 5 min". */
export function formatDuration(seconds) {
  const minutes = Math.max(1, Math.round(seconds / 60))
  if (minutes < 60) return `${minutes} min`
  const hours = Math.floor(minutes / 60)
  const rest = minutes % 60
  return rest ? `${hours} hr ${rest} min` : `${hours} hr`
}
