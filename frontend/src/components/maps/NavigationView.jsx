import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import L from 'leaflet'
import { Circle, MapContainer, Marker, Polyline, TileLayer, useMap, useMapEvents } from 'react-leaflet'
import 'leaflet/dist/leaflet.css'
import {
  FiArrowUp, FiArrowUpLeft, FiArrowUpRight, FiCornerUpLeft, FiCornerUpRight, FiCrosshair, FiFlag, FiList,
  FiNavigation2, FiRefreshCw, FiRotateCcw, FiVolume2, FiVolumeX, FiX, FiAlertTriangle, FiChevronRight,
} from 'react-icons/fi'
import { OSM_TILES } from './leafletConfig'
import {
  MAX_USABLE_ACCURACY_M, RECALC_COOLDOWN_MS, distanceM, fetchNavigationRoute, formatDistance, formatDuration,
  instructionFor, locateOnRoute, nextArrivalState, nextOffRouteState, progressAt, spokenApproach,
} from '../../utils/navigation'

const ROUTE_COLOR = '#0b7285'
const DONE_COLOR = '#9aa5a3'
const ROUTE_TIMEOUT_MS = 15000
const STALE_FIX_MS = 20000
// A phone's first fix is often rough (Wi-Fi or cell towers). Route from a fix this accurate, or
// from the best one available after waiting this long, so the start point is really where they are.
const START_ACCURACY_M = MAX_USABLE_ACCURACY_M
const START_WAIT_MS = 10000
const START_MAX_ACCURACY_M = 1000
/** A last fix older than this isn't used to start a new route; the next fresh fix is. */
const FRESH_FIX_MS = 10000
/** Stops closer together than this count as the same place. */
const SAME_PLACE_M = 50
// OpenStreetMap data credit plus the routing service, as their usage policies ask.
const ATTRIBUTION = `${OSM_TILES.attribution} · Routing <a href="https://project-osrm.org/">OSRM</a>`

const GPS_MESSAGES = {
  insecure: 'Live navigation needs a secure (https) connection to use your location.',
  unsupported: "This browser can't share your location, so live navigation isn't available here.",
  denied: 'Location access is blocked for SOSOGON. Allow location for this site in your browser settings, then tap Try again.',
  unavailable: "Your location isn't available right now. Check that location (GPS) is turned on, then tap Try again.",
  timeout: 'Finding your location is taking too long. Move to an open area, then tap Try again.',
}

/** Icon for a maneuver, from its type and direction. */
function ManeuverIcon({ step, size = 28 }) {
  if (!step) return <FiNavigation2 size={size} aria-hidden="true" />
  const mod = step.modifier || ''
  if (step.type === 'arrive') return <FiFlag size={size} aria-hidden="true" />
  if (step.type === 'depart') return <FiNavigation2 size={size} aria-hidden="true" />
  if (step.type === 'roundabout' || step.type === 'rotary') return <FiRefreshCw size={size} aria-hidden="true" />
  if (mod === 'uturn') return <FiRotateCcw size={size} aria-hidden="true" />
  if (mod.startsWith('slight')) return mod.endsWith('left') ? <FiArrowUpLeft size={size} aria-hidden="true" /> : <FiArrowUpRight size={size} aria-hidden="true" />
  if (mod.endsWith('left')) return <FiCornerUpLeft size={size} aria-hidden="true" />
  if (mod.endsWith('right')) return <FiCornerUpRight size={size} aria-hidden="true" />
  return <FiArrowUp size={size} aria-hidden="true" />
}

function userIcon(heading) {
  const arrow = heading == null
    ? ''
    : `<span style="position:absolute;left:50%;top:-9px;width:0;height:0;margin-left:-6px;border-left:6px solid transparent;border-right:6px solid transparent;border-bottom:10px solid #1a73e8"></span>`
  return L.divIcon({
    className: '',
    html: `<span style="position:relative;display:block;width:22px;height:22px;transform:rotate(${heading ?? 0}deg)">${arrow}<span style="position:absolute;inset:0;border-radius:9999px;background:#1a73e8;border:3px solid #fff;box-shadow:0 1px 5px rgba(0,0,0,.4)"></span></span>`,
    iconSize: [22, 22],
    iconAnchor: [11, 11],
  })
}

const DESTINATION_ICON = L.divIcon({
  className: '',
  html: '<span style="display:flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:9999px;background:#b23b3b;color:#fff;font:700 13px/1 sans-serif;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.35)">B</span>',
  iconSize: [30, 30],
  iconAnchor: [15, 15],
})

/** Keeps the map on the tourist while following; panning by hand stops following. */
function FollowCamera({ fix, follow, onManualPan, route }) {
  const map = useMap()
  const fitted = useRef(false)
  const zoomed = useRef(false)
  useMapEvents({ dragstart: onManualPan })
  useEffect(() => {
    // Keeps whatever zoom the tourist chose; only the first follow zooms in to street level.
    if (follow && fix) map.setView([fix.lat, fix.lng], zoomed.current ? map.getZoom() : Math.max(map.getZoom(), 16), { animate: true })
    if (follow && fix) zoomed.current = true
  }, [map, fix, follow])
  useEffect(() => {
    // Before the first fix arrives, show the whole route once.
    if (!fix && route && !fitted.current) {
      fitted.current = true
      map.fitBounds(L.latLngBounds(route.path), { padding: [30, 30] })
    }
  }, [map, fix, route])
  return null
}

/** Splits the route at the tourist's progress: travelled part and part still ahead. */
function splitRoute(route, along) {
  if (!route) return { done: [], ahead: [] }
  const { path, cum } = route
  let i = 0
  while (i < cum.length - 2 && cum[i + 1] < along) i++
  const t = Math.max(0, Math.min(1, (along - cum[i]) / Math.max(1e-9, cum[i + 1] - cum[i])))
  const cut = [path[i][0] + t * (path[i + 1][0] - path[i][0]), path[i][1] + t * (path[i + 1][1] - path[i][1])]
  return { done: [...path.slice(0, i + 1), cut], ahead: [cut, ...path.slice(i + 1)] }
}

/**
 * Live turn-by-turn driving navigation over the page, from the device's real GPS position to an
 * itinerary stop's saved map location. GPS stays in the browser; only route requests (start
 * and destination) go to the public OSRM routing server. Tracking stops when this closes.
 *
 * @param {{ stops: { name: string, point: {lat:number,lng:number} }[], startIndex: number, onClose: () => void }} props
 */
export default function NavigationView({ stops, startIndex, onClose }) {
  const [targetIndex, setTargetIndex] = useState(startIndex)
  const target = stops[targetIndex]
  // The next stop that's somewhere else (a day often returns to the same stay several times).
  const nextIndex = stops.findIndex((stop, i) => i > targetIndex && distanceM(stop.point, target.point) > SAME_PLACE_M)
  const nextStop = nextIndex >= 0 ? stops[nextIndex] : null

  const [fix, setFix] = useState(null)
  const [gpsError, setGpsError] = useState(null)
  const [watchKey, setWatchKey] = useState(0)
  const [route, setRoute] = useState(null)
  const [routeError, setRouteError] = useState(null)
  const [recalculating, setRecalculating] = useState(false)
  const [progress, setProgress] = useState(null)
  const [arrived, setArrived] = useState(false)
  const [follow, setFollow] = useState(true)
  const [muted, setMuted] = useState(false)
  const [showList, setShowList] = useState(false)
  const [confirmEnd, setConfirmEnd] = useState(false)
  const [now, setNow] = useState(() => Date.now())

  // Navigation state read by the GPS callback, which is registered once.
  const live = useRef({
    route: null, target, along: 0, off: { count: 0, since: null, confirmed: false },
    arrival: { count: 0, since: null, arrived: false }, arrived: false, requesting: null,
    lastRequestAt: 0, lastFix: null, firstFixAt: null, spoken: new Set(), muted: false, routeId: 0,
  })

  const speak = useCallback((text) => {
    if (live.current.muted || typeof window === 'undefined' || !('speechSynthesis' in window)) return
    try {
      window.speechSynthesis.cancel()
      const utterance = new SpeechSynthesisUtterance(text)
      utterance.lang = 'en-US'
      window.speechSynthesis.speak(utterance)
    } catch {
      // Voice is optional; the on-screen instruction is always shown.
    }
  }, [])

  const requestRoute = useCallback(async (from, { force = false } = {}) => {
    const state = live.current
    if (state.requesting) return
    if (!force && Date.now() - state.lastRequestAt < RECALC_COOLDOWN_MS) return
    const controller = new AbortController()
    const timer = setTimeout(() => controller.abort(), ROUTE_TIMEOUT_MS)
    state.requesting = controller
    state.lastRequestAt = Date.now()
    if (state.route) setRecalculating(true)
    try {
      const fresh = await fetchNavigationRoute(from, state.target.point, { signal: controller.signal })
      if (state.requesting !== controller) return
      state.route = fresh
      state.routeId += 1
      state.along = 0
      state.off = { count: 0, since: null, confirmed: false }
      setRoute(fresh)
      setRouteError(null)
      const located = locateOnRoute(fresh, from, 0)
      state.along = located.along
      setProgress({ ...progressAt(fresh, located.along), along: located.along })
      if (state.routeId === 1) speak(`Starting navigation to ${state.target.name}. ${instructionFor(fresh.steps[0], state.target.name)}.`)
    } catch {
      if (state.requesting !== controller) return
      // Keep the last good route; say why there's no new one.
      setRouteError(state.route
        ? "Couldn't recalculate the route. Showing the last route; it will try again shortly."
        : "Couldn't get a driving route right now. Check your internet connection, then tap Try again.")
    } finally {
      clearTimeout(timer)
      if (state.requesting === controller) state.requesting = null
      setRecalculating(false)
    }
  }, [speak])

  const handlePosition = useCallback((position) => {
    const { latitude, longitude, accuracy, heading, speed } = position.coords
    const time = position.timestamp || Date.now()
    const current = {
      lat: latitude,
      lng: longitude,
      accuracy: accuracy ?? 9999,
      // Heading is only meaningful while moving.
      heading: heading != null && !Number.isNaN(heading) && (speed ?? 0) > 1 ? heading : null,
      time,
    }
    const state = live.current
    state.lastFix = current
    state.firstFixAt ??= Date.now()
    setFix(current)
    setGpsError(null)

    if (!state.route) {
      const waitedLongEnough = Date.now() - state.firstFixAt >= START_WAIT_MS
      const goodEnough = current.accuracy <= START_ACCURACY_M || (waitedLongEnough && current.accuracy <= START_MAX_ACCURACY_M)
      if (!state.requesting && goodEnough) requestRoute(current, { force: state.lastRequestAt === 0 })
      return
    }
    if (state.arrived || current.accuracy > MAX_USABLE_ACCURACY_M) return

    const located = locateOnRoute(state.route, current, state.along)
    state.along = located.along
    const p = progressAt(state.route, located.along)
    setProgress({ ...p, along: located.along })

    state.arrival = nextArrivalState(state.arrival, {
      fix: current, accuracy: current.accuracy, time, destination: state.target.point, remainingM: p.remainingM, offset: located.offset,
    })
    if (state.arrival.arrived) {
      state.arrived = true
      setArrived(true)
      speak(`You have arrived at ${state.target.name}.`)
      return
    }

    state.off = nextOffRouteState(state.off, { offset: located.offset, accuracy: current.accuracy, time })
    if (state.off.confirmed) {
      state.off = { count: 0, since: null, confirmed: false }
      requestRoute(current)
      return
    }

    // Spoken guidance: once on the approach, once at the turn. Keyed by the turn's place, so a
    // recalculated route doesn't announce a turn that was already announced.
    if (p.next) {
      const key = `${p.next.type}@${p.next.location.lat.toFixed(4)},${p.next.location.lng.toFixed(4)}`
      if (p.distanceToNext <= 500 && p.distanceToNext > 150 && !state.spoken.has(`${key}-far`)) {
        state.spoken.add(`${key}-far`)
        speak(spokenApproach(p.next, p.distanceToNext, state.target.name))
      } else if (p.distanceToNext <= 60 && !state.spoken.has(`${key}-near`)) {
        state.spoken.add(`${key}-near`)
        speak(instructionFor(p.next, state.target.name))
      }
    }
  }, [requestRoute, speak])

  // GPS: starts when this view opens (the tourist tapped Start navigation), stops when it closes.
  const cannotLocate = !window.isSecureContext ? 'insecure' : !('geolocation' in navigator) ? 'unsupported' : null
  useEffect(() => {
    if (cannotLocate) return undefined
    const id = navigator.geolocation.watchPosition(
      handlePosition,
      (error) => {
        // Once navigation has a position, a slow or missing update only shows "GPS signal lost";
        // a blocked permission always stops it.
        if (error.code !== 1 && live.current.lastFix) return
        setGpsError(error.code === 1 ? 'denied' : error.code === 3 ? 'timeout' : 'unavailable')
      },
      { enableHighAccuracy: true, maximumAge: 2000, timeout: 20000 },
    )
    return () => navigator.geolocation.clearWatch(id)
  }, [handlePosition, watchKey, cannotLocate])

  // Clock for the arrival time and for noticing a lost GPS signal.
  useEffect(() => {
    const id = setInterval(() => setNow(Date.now()), 5000)
    return () => clearInterval(id)
  }, [])

  // While navigating: keep the screen on where supported, stop the page scrolling behind,
  // and stop any speech and pending route request on close.
  useEffect(() => {
    let lock = null
    let closed = false
    const acquire = async () => {
      try {
        const granted = await navigator.wakeLock?.request('screen')
        // Closed while the request was pending: give the lock straight back.
        if (closed) granted?.release?.().catch(() => {})
        else lock = granted
      } catch {
        lock = null
      }
    }
    const onVisible = () => document.visibilityState === 'visible' && acquire()
    acquire()
    document.addEventListener('visibilitychange', onVisible)
    const overflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    const state = live.current
    return () => {
      closed = true
      document.removeEventListener('visibilitychange', onVisible)
      lock?.release?.().catch(() => {})
      document.body.style.overflow = overflow
      state.requesting?.abort()
      state.requesting = null
      if ('speechSynthesis' in window) window.speechSynthesis.cancel()
    }
  }, [])

  const toggleMute = () => {
    const next = !muted
    live.current.muted = next
    setMuted(next)
    if (next && 'speechSynthesis' in window) window.speechSynthesis.cancel()
  }

  const goToNextStop = () => {
    const state = live.current
    const index = nextIndex
    state.target = stops[index]
    state.route = null
    state.along = 0
    state.arrived = false
    state.arrival = { count: 0, since: null, arrived: false }
    state.off = { count: 0, since: null, confirmed: false }
    state.routeId = 0
    state.spoken = new Set()
    state.lastRequestAt = 0
    state.requesting?.abort()
    state.requesting = null
    setTargetIndex(index)
    setRoute(null)
    setProgress(null)
    setArrived(false)
    setRouteError(null)
    setFollow(true)
    // Start from where the tourist is now, not from the previous stop: a recent accurate fix if
    // there is one, otherwise the next fix that arrives (the route-less GPS handler asks then).
    const recent = state.lastFix && Date.now() - state.lastFix.time <= FRESH_FIX_MS && state.lastFix.accuracy <= START_ACCURACY_M
    if (recent) requestRoute(state.lastFix, { force: true })
  }

  const retry = () => {
    setGpsError(null)
    if (live.current.lastFix && !live.current.route) requestRoute(live.current.lastFix, { force: true })
    else setWatchKey((k) => k + 1)
  }

  const askToEnd = () => (route && !arrived ? setConfirmEnd(true) : onClose())

  const gpsProblem = cannotLocate || gpsError
  const along = progress?.along ?? 0
  const { done, ahead } = useMemo(() => splitRoute(route, along), [route, along])
  const stale = fix && now - fix.time > STALE_FIX_MS
  const weak = fix && fix.accuracy > MAX_USABLE_ACCURACY_M
  const nextStep = progress?.next
  const eta = progress ? new Date(now + progress.remainingS * 1000).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : null
  const upcoming = route && progress ? route.steps.slice(progress.stepIndex + 1) : []
  const center = fix ? [fix.lat, fix.lng] : [target.point.lat, target.point.lng]

  return createPortal(
    <div className="fixed inset-0 z-3000 isolate flex flex-col bg-white" role="dialog" aria-modal="true" aria-label={`Navigation to ${target.name}`}>
      {/* Next maneuver */}
      <div className="bg-primary-700 px-4 pb-3 pt-[max(12px,env(safe-area-inset-top))] text-white shadow-md">
        {route && progress && !arrived ? (
          <div className="flex items-center gap-3">
            <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl bg-white/15">
              <ManeuverIcon step={nextStep || route.steps.at(-1)} size={32} />
            </div>
            <div className="min-w-0" aria-live="polite">
              {nextStep && <p className="text-3xl font-bold leading-tight">{formatDistance(progress.distanceToNext)}</p>}
              <p className="text-lg font-semibold leading-snug">{instructionFor(nextStep || route.steps.at(-1), target.name)}</p>
            </div>
          </div>
        ) : (
          <div className="flex items-center gap-3">
            <FiNavigation2 size={26} aria-hidden="true" />
            <div className="min-w-0">
              <p className="text-sm opacity-80">Navigating to</p>
              <p className="text-lg font-semibold leading-snug truncate">{target.name}</p>
            </div>
          </div>
        )}
      </div>

      {/* Map */}
      <div className="relative flex-1">
        <MapContainer center={center} zoom={16} zoomControl={false} style={{ width: '100%', height: '100%' }} aria-label="Navigation map">
          <TileLayer url={OSM_TILES.url} attribution={ATTRIBUTION} maxZoom={OSM_TILES.maxZoom} />
          <FollowCamera fix={fix} follow={follow} route={route} onManualPan={() => setFollow(false)} />
          {done.length > 1 && <Polyline positions={done} pathOptions={{ color: DONE_COLOR, weight: 6, opacity: 0.8 }} />}
          {ahead.length > 1 && <Polyline positions={ahead} pathOptions={{ color: ROUTE_COLOR, weight: 7, opacity: 0.9 }} />}
          <Marker position={[target.point.lat, target.point.lng]} icon={DESTINATION_ICON} title={target.name} />
          {fix && (
            <>
              {fix.accuracy < 500 && <Circle center={[fix.lat, fix.lng]} radius={fix.accuracy} pathOptions={{ color: '#1a73e8', weight: 1, fillOpacity: 0.12 }} />}
              <Marker position={[fix.lat, fix.lng]} icon={userIcon(fix.heading)} interactive={false} keyboard={false} />
            </>
          )}
        </MapContainer>

        {/* Status messages */}
        <div className="pointer-events-none absolute inset-x-3 top-3 z-1000 flex flex-col items-center gap-2" role="status">
          {!fix && !gpsProblem && <Chip>Finding your location…</Chip>}
          {fix && !route && !routeError && <Chip>Getting the driving route…</Chip>}
          {recalculating && <Chip><FiRefreshCw size={13} className="animate-spin" aria-hidden="true" /> Recalculating route…</Chip>}
          {stale && <Chip tone="warn">GPS signal lost. Waiting for your location…</Chip>}
          {!stale && weak && <Chip tone="warn">Weak GPS signal (±{Math.round(fix.accuracy)} m). Directions update when it improves.</Chip>}
          {routeError && <Chip tone="warn">{routeError}</Chip>}
        </div>

        {/* Map controls */}
        <div className="absolute bottom-4 right-3 z-1000 flex flex-col gap-2">
          {!follow && fix && (
            <MapButton label="Recenter on my location" onClick={() => setFollow(true)}><FiCrosshair size={20} /></MapButton>
          )}
          {'speechSynthesis' in window && (
            <MapButton label={muted ? 'Unmute voice guidance' : 'Mute voice guidance'} pressed={muted} onClick={toggleMute}>
              {muted ? <FiVolumeX size={20} /> : <FiVolume2 size={20} />}
            </MapButton>
          )}
          {route && <MapButton label="View directions" onClick={() => setShowList(true)}><FiList size={20} /></MapButton>}
        </div>

        {/* GPS or route problem that stops navigation */}
        {(gpsProblem || (routeError && !route)) && (
          <Sheet>
            <p className="flex items-start gap-2 text-sm text-gray-800">
              <FiAlertTriangle size={18} className="mt-0.5 shrink-0 text-amber-500" aria-hidden="true" />
              {gpsProblem ? GPS_MESSAGES[gpsProblem] : routeError}
            </p>
            <div className="mt-3 flex gap-2">
              {!cannotLocate && (
                <button type="button" onClick={retry} className="btn-primary flex-1">Try again</button>
              )}
              <button type="button" onClick={onClose} className="btn-secondary flex-1">Close</button>
            </div>
          </Sheet>
        )}

        {/* Arrival */}
        {arrived && (
          <Sheet>
            <p className="flex items-center gap-2 text-lg font-semibold text-gray-900"><FiFlag className="text-primary-600" aria-hidden="true" /> You have arrived!</p>
            <p className="mt-1 text-sm text-gray-600">{target.name}</p>
            <div className="mt-4 flex flex-col gap-2 sm:flex-row">
              {nextStop && (
                <button type="button" onClick={goToNextStop} className="btn-primary flex-1">
                  Next: {nextStop.name} <FiChevronRight aria-hidden="true" />
                </button>
              )}
              <button type="button" onClick={onClose} className={`${nextStop ? 'btn-secondary' : 'btn-primary'} flex-1`}>End navigation</button>
            </div>
          </Sheet>
        )}

        {/* Upcoming directions */}
        {showList && route && (
          <div className="absolute inset-x-0 bottom-0 z-1100 max-h-[70%] overflow-y-auto rounded-t-2xl bg-white shadow-[0_-4px_20px_rgba(0,0,0,0.15)]">
            <div className="sticky top-0 flex items-center justify-between border-b border-gray-100 bg-white px-4 py-3">
              <p className="font-semibold text-gray-900">Directions to {target.name}</p>
              <button type="button" onClick={() => setShowList(false)} className="rounded-md p-2 text-gray-500 hover:bg-gray-100" aria-label="Close directions list"><FiX size={20} /></button>
            </div>
            <ol className="divide-y divide-gray-100">
              {upcoming.map((step, i) => (
                <li key={`${step.start}-${i}`} className="flex items-center gap-3 px-4 py-3">
                  <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-700"><ManeuverIcon step={step} size={18} /></span>
                  <span className="min-w-0 flex-1 text-sm text-gray-800">{instructionFor(step, target.name)}</span>
                  {step.type !== 'arrive' && <span className="shrink-0 text-xs text-gray-500">{formatDistance(step.distance)}</span>}
                </li>
              ))}
            </ol>
          </div>
        )}
      </div>

      {/* Trip summary and end */}
      <div className="flex items-center gap-3 border-t border-gray-200 bg-white px-4 pt-3 pb-[max(12px,env(safe-area-inset-bottom))]">
        <div className="min-w-0 flex-1">
          {progress && !arrived ? (
            <>
              <p className="text-2xl font-bold text-primary-700">{formatDuration(progress.remainingS)}</p>
              <p className="text-sm text-gray-600">{formatDistance(progress.remainingM)} · arrive {eta}</p>
            </>
          ) : (
            <p className="text-sm text-gray-600 truncate">{target.name}</p>
          )}
        </div>
        <button type="button" onClick={askToEnd} className="rounded-full bg-red-600 px-5 py-3 text-sm font-semibold text-white hover:bg-red-700">
          End
        </button>
      </div>

      {confirmEnd && (
        <div className="absolute inset-0 z-1200 flex items-end justify-center bg-black/40 p-4 sm:items-center">
          <div className="w-full max-w-sm rounded-2xl bg-white p-5 shadow-xl" role="alertdialog" aria-label="End navigation?">
            <p className="text-lg font-semibold text-gray-900">End navigation?</p>
            <p className="mt-1 text-sm text-gray-600">Directions to {target.name} will stop, and SOSOGON will stop using your location.</p>
            <div className="mt-4 flex gap-2">
              <button type="button" onClick={() => setConfirmEnd(false)} className="btn-secondary flex-1">Keep navigating</button>
              <button type="button" onClick={onClose} className="flex-1 rounded-lg bg-red-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-red-700">End</button>
            </div>
          </div>
        </div>
      )}
    </div>,
    document.body,
  )
}

function Chip({ children, tone }) {
  return (
    <p className={`pointer-events-auto inline-flex max-w-full items-center gap-1.5 rounded-full px-3 py-1.5 text-center text-xs font-medium shadow ${tone === 'warn' ? 'bg-amber-50 text-amber-900' : 'bg-white/95 text-gray-800'}`}>
      {children}
    </p>
  )
}

function MapButton({ label, onClick, pressed, children }) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-label={label}
      aria-pressed={pressed}
      title={label}
      className="flex h-12 w-12 items-center justify-center rounded-full bg-white text-gray-800 shadow-lg hover:bg-gray-50"
    >
      {children}
    </button>
  )
}

function Sheet({ children }) {
  return <div className="absolute inset-x-3 bottom-4 z-1100 mx-auto max-w-md rounded-2xl bg-white p-4 shadow-xl">{children}</div>
}
