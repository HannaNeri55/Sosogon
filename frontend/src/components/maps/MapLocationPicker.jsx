// frontend/src/components/maps/MapLocationPicker.jsx
import { useEffect, useState } from 'react'
import {
  APIProvider, AdvancedMarker, Map, Marker, useApiLoadingStatus, useMap, useMapsLibrary,
} from '@vis.gl/react-google-maps'
import { FiCrosshair, FiExternalLink, FiSearch } from 'react-icons/fi'
import {
  GOOGLE_MAPS_API_KEY, GOOGLE_MAPS_MAP_ID, SORSOGON_PROVINCE_VIEW, googleMapsPlaceUrl, toCoordinates,
} from './mapsConfig'

/** Pans the map to the chosen point when it changes from outside the map (search or typed numbers). */
function FollowPoint({ point }) {
  const map = useMap()
  useEffect(() => {
    if (map && point) {
      map.panTo(point)
      if ((map.getZoom() ?? 0) < 15) map.setZoom(17)
    }
  }, [map, point])
  return null
}

/** Address / place-name search (Google Geocoding), biased to Sorsogon, Philippines. */
function PlaceSearch({ initialQuery, onPick }) {
  const geocoding = useMapsLibrary('geocoding')
  const [query, setQuery] = useState(initialQuery || '')
  const [results, setResults] = useState([])
  const [message, setMessage] = useState(null)
  const [busy, setBusy] = useState(false)

  const search = async (event) => {
    event?.preventDefault()
    if (!geocoding || !query.trim()) return
    setBusy(true)
    setMessage(null)
    try {
      const text = /sorsogon/i.test(query) ? query : `${query}, Sorsogon, Philippines`
      const { results: found } = await new geocoding.Geocoder().geocode({ address: text, region: 'ph' })
      setResults(found.slice(0, 5))
      if (found.length === 0) setMessage('No match found. Try the barangay or town name, or place the pin on the map.')
    } catch {
      setResults([])
      setMessage('Search is unavailable (the Geocoding API may not be enabled for this key). Place the pin on the map instead.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div>
      {/* Not a <form>: the picker usually sits inside the listing's own form. */}
      <div className="flex gap-2">
        <div className="relative flex-1">
          <FiSearch className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={16} aria-hidden="true" />
          <input
            className="input-field pl-9"
            placeholder="Search the place name or address…"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            onKeyDown={(e) => e.key === 'Enter' && search(e)}
            aria-label="Search location"
          />
        </div>
        <button type="button" onClick={search} disabled={busy || !geocoding} className="btn-secondary px-4 shrink-0">
          {busy ? 'Searching…' : 'Search'}
        </button>
      </div>
      {results.length > 0 && (
        <ul className="mt-2 overflow-hidden rounded-xl border border-gray-200 bg-white">
          {results.map((result) => (
            <li key={result.place_id}>
              <button
                type="button"
                onClick={() => {
                  onPick({ lat: result.geometry.location.lat(), lng: result.geometry.location.lng() }, result.formatted_address)
                  setResults([])
                }}
                className="block w-full px-4 py-2.5 text-left text-sm text-gray-700 hover:bg-primary-50"
              >
                {result.formatted_address}
              </button>
            </li>
          ))}
        </ul>
      )}
      {message && <p className="mt-2 text-xs text-gray-600">{message}</p>}
    </div>
  )
}

function PickerMap({ point, onPoint, height }) {
  const status = useApiLoadingStatus()
  if (status === 'FAILED' || status === 'AUTH_FAILURE') {
    return (
      <div className="flex items-center justify-center rounded-xl bg-gray-100 px-6 text-center text-sm text-gray-600" style={{ height }}>
        The map could not load. Check the Google Maps API key, or enter the coordinates below.
      </div>
    )
  }

  const fromEvent = (latLng) => latLng && onPoint({ lat: latLng.lat(), lng: latLng.lng() })

  return (
    <div className="overflow-hidden rounded-xl border border-gray-200">
      <Map
        mapId={GOOGLE_MAPS_MAP_ID || undefined}
        defaultCenter={point || SORSOGON_PROVINCE_VIEW.center}
        defaultZoom={point ? 17 : SORSOGON_PROVINCE_VIEW.zoom}
        style={{ width: '100%', height }}
        gestureHandling="greedy"
        clickableIcons={false}
        onClick={(event) => event.detail.latLng && onPoint(event.detail.latLng)}
      >
        {point && (GOOGLE_MAPS_MAP_ID ? (
          <AdvancedMarker position={point} draggable onDragEnd={(event) => fromEvent(event.latLng)} title="Drag to adjust" />
        ) : (
          <Marker position={point} draggable onDragEnd={(event) => fromEvent(event.latLng)} title="Drag to adjust" />
        ))}
        <FollowPoint point={point} />
      </Map>
    </div>
  )
}

const fixed = (value) => (Number.isFinite(value) ? value.toFixed(7) : '')

/**
 * Pick a listing's exact location: search a place or address, click the map or drag the
 * pin, or type latitude/longitude. Reports `{ latitude, longitude }` strings through
 * onChange (and a found address through onAddressFound). No pin is shown until a real
 * location is chosen; works with typed coordinates even without a Maps API key.
 */
export default function MapLocationPicker({ latitude, longitude, onChange, onAddressFound, searchHint, height = '360px', required = false }) {
  const point = toCoordinates(latitude, longitude)
  const latNum = Number(latitude)
  const lngNum = Number(longitude)
  const latInvalid = latitude !== '' && latitude != null && (!Number.isFinite(latNum) || Math.abs(latNum) > 90)
  const lngInvalid = longitude !== '' && longitude != null && (!Number.isFinite(lngNum) || Math.abs(lngNum) > 180)
  const outsideSorsogon = point && (point.lat < 12.0 || point.lat > 13.4 || point.lng < 123.4 || point.lng > 124.4)

  const setPoint = ({ lat, lng }) => onChange({ latitude: fixed(lat), longitude: fixed(lng) })

  return (
    <div className="space-y-3">
      {GOOGLE_MAPS_API_KEY ? (
        <APIProvider apiKey={GOOGLE_MAPS_API_KEY}>
          <PlaceSearch
            initialQuery={searchHint}
            onPick={(picked, address) => {
              setPoint(picked)
              if (address && onAddressFound) onAddressFound(address)
            }}
          />
          <PickerMap point={point} onPoint={setPoint} height={height} />
          <p className="flex items-center gap-1.5 text-xs text-gray-500">
            <FiCrosshair size={13} aria-hidden="true" /> Click the map to place the pin, then drag it to the exact entrance or spot.
          </p>
        </APIProvider>
      ) : (
        <p className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
          The interactive map isn&apos;t available until the Google Maps API key is configured. You can still enter the coordinates below
          (in Google Maps, right-click the exact spot and click the numbers to copy them).
        </p>
      )}

      <div className="grid gap-3 sm:grid-cols-2">
        <label className="block">
          <span className="block text-sm font-medium text-gray-700 mb-1">Latitude{required && ' *'}</span>
          <input
            name="latitude"
            className="input-field"
            aria-invalid={latInvalid || undefined}
            inputMode="decimal"
            placeholder="e.g. 12.9718000"
            value={latitude ?? ''}
            onChange={(e) => onChange({ latitude: e.target.value.trim(), longitude: longitude ?? '' })}
            required={required}
          />
          {latInvalid && <span className="field-error block">Latitude must be between -90 and 90.</span>}
        </label>
        <label className="block">
          <span className="block text-sm font-medium text-gray-700 mb-1">Longitude{required && ' *'}</span>
          <input
            name="longitude"
            className="input-field"
            aria-invalid={lngInvalid || undefined}
            inputMode="decimal"
            placeholder="e.g. 124.0055000"
            value={longitude ?? ''}
            onChange={(e) => onChange({ latitude: latitude ?? '', longitude: e.target.value.trim() })}
            required={required}
          />
          {lngInvalid && <span className="field-error block">Longitude must be between -180 and 180.</span>}
        </label>
      </div>

      {outsideSorsogon && (
        <p className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
          This point is outside Sorsogon province. Please double-check it before saving.
        </p>
      )}
      {point && (
        <a href={googleMapsPlaceUrl(point)} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1.5 text-sm font-semibold text-primary-700 hover:text-primary-800">
          Check this point on Google Maps <FiExternalLink size={13} aria-hidden="true" />
        </a>
      )}
    </div>
  )
}
