import { useEffect, useMemo, useState } from 'react'
import L from 'leaflet'
import { MapContainer, Marker, Polyline, TileLayer, Tooltip, useMap } from 'react-leaflet'
import 'leaflet/dist/leaflet.css'
import { FiRefreshCw } from 'react-icons/fi'
import { OSM_TILES, TOUCH_DEVICE } from './leafletConfig'
import { fetchDrivingRoute } from '../../utils/drivingRoute'

const ROUTE_COLOR = '#0b7285'

// Lettered pins for the two ends of the leg: A where the trip starts, B where it ends.
function endIcon(letter, color) {
  return L.divIcon({
    className: '',
    html: `<span style="display:flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:9999px;background:${color};color:#fff;font:700 13px/1 sans-serif;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.35)">${letter}</span>`,
    iconSize: [28, 28],
    iconAnchor: [14, 14],
  })
}
const START_ICON = endIcon('A', '#2f7d4f')
const END_ICON = endIcon('B', '#b23b3b')

// Small arrowheads along the route showing which way it runs.
function arrowIcon(degrees) {
  return L.divIcon({
    className: '',
    html: `<span style="display:block;width:0;height:0;border-left:6px solid transparent;border-right:6px solid transparent;border-bottom:11px solid ${ROUTE_COLOR};transform:rotate(${degrees}deg)"></span>`,
    iconSize: [12, 11],
    iconAnchor: [6, 6],
  })
}

function bearing([lat1, lng1], [lat2, lng2]) {
  const rad = Math.PI / 180
  const y = Math.sin((lng2 - lng1) * rad) * Math.cos(lat2 * rad)
  const x = Math.cos(lat1 * rad) * Math.sin(lat2 * rad) - Math.sin(lat1 * rad) * Math.cos(lat2 * rad) * Math.cos((lng2 - lng1) * rad)
  return (Math.atan2(y, x) / rad + 360) % 360
}

/** Arrows at a quarter, half and three quarters of the way, pointing along the road there. */
function directionArrows(path) {
  if (path.length < 3) return []
  return [0.25, 0.5, 0.75].map((share) => {
    const i = Math.min(path.length - 2, Math.floor(path.length * share))
    return { position: path[i], degrees: bearing(path[i], path[i + 1]) }
  })
}

function FitRoute({ points }) {
  const map = useMap()
  useEffect(() => {
    map.fitBounds(L.latLngBounds(points), { padding: [36, 36], maxZoom: 16 })
  }, [map, points])
  return null
}

// A saved pin further than this from any road gets a note saying where the route really starts or ends.
const OFF_ROAD_NOTE_M = 150

function formatMetres(metres) {
  return metres < 1000 ? `${Math.round(metres / 10) * 10} m` : `${(metres / 1000).toFixed(1)} km`
}

function formatDuration(minutes) {
  const total = Math.max(1, Math.round(minutes))
  if (total < 60) return `${total} min`
  const hours = Math.floor(total / 60)
  const rest = total % 60
  return rest ? `${hours} hr ${rest} min` : `${hours} hr`
}

/**
 * The driving route between two itinerary stops on an OpenStreetMap map, with the road
 * distance and an estimated drive time. Draws the road route only; while it loads or if it
 * can't be found, the two stops are shown without a line.
 */
export default function RouteMap({ origin, destination, fromName, toName, height = '320px' }) {
  const [attempt, setAttempt] = useState(0)
  // The answer is kept with the request it belongs to; a new leg or Try again starts as loading.
  const [answer, setAnswer] = useState({ request: null })

  // Plain numbers as dependencies, so a re-render with equal coordinates doesn't refetch.
  const { lat: fromLat, lng: fromLng } = origin
  const { lat: toLat, lng: toLng } = destination
  const request = `${fromLat},${fromLng};${toLat},${toLng}#${attempt}`

  useEffect(() => {
    let current = true
    fetchDrivingRoute({ lat: fromLat, lng: fromLng }, { lat: toLat, lng: toLng })
      .then((found) => current && setAnswer({ request, route: found }))
      .catch(() => current && setAnswer({ request, failed: true }))
    return () => {
      current = false
    }
  }, [fromLat, fromLng, toLat, toLng, request])

  const route = answer.request === request ? answer.route ?? null : null
  const failed = answer.request === request && Boolean(answer.failed)

  const ends = useMemo(() => [[fromLat, fromLng], [toLat, toLng]], [fromLat, fromLng, toLat, toLng])
  const bounds = useMemo(() => (route ? [...ends, ...route.path] : ends), [ends, route])
  const arrows = useMemo(() => (route ? directionArrows(route.path) : []), [route])

  return (
    <div>
      <div className="relative isolate overflow-hidden rounded-xl bg-gray-100" style={{ height }}>
        <MapContainer
          center={ends[0]}
          zoom={12}
          scrollWheelZoom={false}
          dragging={!TOUCH_DEVICE}
          style={{ width: '100%', height: '100%' }}
          aria-label={`Driving route from ${fromName} to ${toName}`}
        >
          <TileLayer url={OSM_TILES.url} attribution={`${OSM_TILES.attribution} · Routing <a href="https://project-osrm.org/">OSRM</a>`} maxZoom={OSM_TILES.maxZoom} />
          <FitRoute points={bounds} />
          {route && <Polyline positions={route.path} pathOptions={{ color: ROUTE_COLOR, weight: 5, opacity: 0.85 }} />}
          {arrows.map(({ position, degrees }) => (
            <Marker key={`${position[0]},${position[1]}`} position={position} icon={arrowIcon(degrees)} interactive={false} keyboard={false} />
          ))}
          <Marker position={ends[0]} icon={START_ICON} title={`Start: ${fromName}`}>
            <Tooltip direction="top" offset={[0, -14]}>Start: {fromName}</Tooltip>
          </Marker>
          <Marker position={ends[1]} icon={END_ICON} title={`End: ${toName}`}>
            <Tooltip direction="top" offset={[0, -14]}>End: {toName}</Tooltip>
          </Marker>
        </MapContainer>

        {!route && (
          <div className="pointer-events-none absolute inset-x-3 top-3 z-1000 flex justify-center">
            <p className="pointer-events-auto rounded-lg bg-white/95 px-3 py-2 text-center text-xs text-gray-700 shadow" role="status">
              {failed ? (
                <>
                  The driving route couldn&apos;t be loaded right now.{' '}
                  <button type="button" onClick={() => setAttempt((n) => n + 1)} className="inline-flex items-center gap-1 font-semibold text-primary-700 hover:text-primary-800">
                    <FiRefreshCw size={11} aria-hidden="true" /> Try again
                  </button>
                </>
              ) : (
                'Finding the driving route…'
              )}
            </p>
          </div>
        )}
      </div>

      <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-600">
        <span className="inline-flex items-center gap-1.5"><span className="h-2.5 w-2.5 rounded-full bg-[#2f7d4f]" aria-hidden="true" /> A · {fromName}</span>
        <span className="inline-flex items-center gap-1.5"><span className="h-2.5 w-2.5 rounded-full bg-[#b23b3b]" aria-hidden="true" /> B · {toName}</span>
        {route && (
          <span className="font-medium text-gray-800">
            {route.distanceKm.toFixed(1)} km by road · about {formatDuration(route.durationMin)} by car
          </span>
        )}
      </div>
      {route && route.offRoadM.some((metres) => metres > OFF_ROAD_NOTE_M) && (
        <p className="mt-1 text-xs text-amber-800">
          {[fromName, toName]
            .map((name, i) => (route.offRoadM[i] > OFF_ROAD_NOTE_M ? `${name}'s map pin is about ${formatMetres(route.offRoadM[i])} from the nearest road` : null))
            .filter(Boolean)
            .join(', and ')}
          , so the route {route.offRoadM[0] > OFF_ROAD_NOTE_M && route.offRoadM[1] > OFF_ROAD_NOTE_M ? 'starts and ends' : route.offRoadM[0] > OFF_ROAD_NOTE_M ? 'starts' : 'ends'} at that road.
        </p>
      )}
      {route && (
        <p className="mt-1 text-[11px] text-gray-400">
          Route from OpenStreetMap road data. Drive time is an estimate without traffic; check local road conditions.
        </p>
      )}
    </div>
  )
}
