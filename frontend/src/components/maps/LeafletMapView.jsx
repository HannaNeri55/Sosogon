import { useEffect, useMemo, useState } from 'react'
import L from 'leaflet'
import { MapContainer, Marker, Popup, TileLayer, useMap, useMapEvents } from 'react-leaflet'
import 'leaflet/dist/leaflet.css'
import { FiExternalLink } from 'react-icons/fi'
import { googleMapsPlaceUrl, toCoordinates } from './mapsConfig'
import { OSM_TILES, PIN_ICON, TOUCH_DEVICE } from './leafletConfig'
import MapMessage from './MapMessage'

/** Shows every marker when there are several; one marker keeps the given center and zoom. */
function FitMarkers({ points }) {
  const map = useMap()
  useEffect(() => {
    if (points.length > 1) {
      map.fitBounds(L.latLngBounds(points.map((p) => [p.lat, p.lng])), { padding: [40, 40], maxZoom: 16 })
    }
  }, [map, points])
  return null
}

/** Reports when map tiles fail to load (offline, blocked, or the tile server is down). */
function TileErrorWatcher({ onError }) {
  useMapEvents({ tileerror: onError })
  return null
}

/**
 * OpenStreetMap map (Leaflet) with place markers. Takes the same props as GoogleMapView.
 * Markers without valid coordinates are skipped; with none left and no center it shows a
 * message instead of a map, so no pin is ever guessed.
 */
export default function LeafletMapView({ markers = [], center, zoom = 15, height = '400px', fallbackLink }) {
  const [tilesFailed, setTilesFailed] = useState(false)

  const points = useMemo(
    () => markers
      .map((marker) => ({ ...marker, point: toCoordinates(marker.lat, marker.lng) }))
      .filter((marker) => marker.point)
      .map(({ point, ...marker }) => ({ ...marker, lat: point.lat, lng: point.lng })),
    [markers],
  )
  const middle = toCoordinates(center?.lat, center?.lng) || (points[0] && { lat: points[0].lat, lng: points[0].lng })
  const link = fallbackLink || (middle ? googleMapsPlaceUrl(middle) : undefined)

  if (!middle) {
    return <MapMessage height={height}>No verified location to show on the map yet.</MapMessage>
  }

  return (
    // `isolate` keeps Leaflet's high z-index panes below the site header and modals.
    <div className="relative isolate overflow-hidden rounded-xl bg-gray-100" style={{ height }}>
      <MapContainer
        // Remount when the place changes so the map re-centres on the new listing.
        key={`${middle.lat},${middle.lng}`}
        center={[middle.lat, middle.lng]}
        zoom={zoom}
        scrollWheelZoom={false}
        dragging={!TOUCH_DEVICE}
        style={{ width: '100%', height: '100%' }}
        aria-label="Map"
      >
        <TileLayer url={OSM_TILES.url} attribution={OSM_TILES.attribution} maxZoom={OSM_TILES.maxZoom} />
        <TileErrorWatcher onError={() => setTilesFailed(true)} />
        <FitMarkers points={points} />
        {points.map((marker) => (
          <Marker key={marker.id || `${marker.lat}-${marker.lng}`} position={[marker.lat, marker.lng]} icon={PIN_ICON} title={marker.title}>
            {marker.title && (
              <Popup>
                <p style={{ fontWeight: 600, margin: 0 }}>{marker.title}</p>
                {marker.subtitle && <p style={{ margin: '2px 0 0', color: '#5a534a' }}>{marker.subtitle}</p>}
              </Popup>
            )}
          </Marker>
        ))}
      </MapContainer>

      {tilesFailed && (
        <div className="pointer-events-none absolute inset-x-3 top-3 z-1000 flex justify-center">
          <p className="pointer-events-auto rounded-lg bg-white/95 px-3 py-2 text-center text-xs text-gray-700 shadow">
            Some map images could not load.{' '}
            {link && (
              <a href={link} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 font-semibold text-primary-700 hover:text-primary-800">
                View this location on Google Maps <FiExternalLink size={11} aria-hidden="true" />
              </a>
            )}
          </p>
        </div>
      )}
    </div>
  )
}
