import { useState } from 'react'
import {
  APIProvider, AdvancedMarker, InfoWindow, Map, Marker, useAdvancedMarkerRef, useApiIsLoaded, useApiLoadingStatus, useMarkerRef,
} from '@vis.gl/react-google-maps'
import { GOOGLE_MAPS_API_KEY, GOOGLE_MAPS_MAP_ID, googleMapsPlaceUrl } from './mapsConfig'
import MapMessage from './MapMessage'

// Kept for existing imports; the panel itself now lives in MapMessage.jsx.
export { MapMessage }

/** One pin; clicking it shows the place name (and optional line under it). */
function PlaceMarker({ marker }) {
  const [open, setOpen] = useState(false)
  const [advancedRef, advancedMarker] = useAdvancedMarkerRef()
  const [classicRef, classicMarker] = useMarkerRef()
  const position = { lat: Number(marker.lat), lng: Number(marker.lng) }

  return (
    <>
      {GOOGLE_MAPS_MAP_ID ? (
        <AdvancedMarker ref={advancedRef} position={position} title={marker.title} onClick={() => setOpen((v) => !v)} />
      ) : (
        <Marker ref={classicRef} position={position} title={marker.title} onClick={() => setOpen((v) => !v)} />
      )}
      {open && marker.title && (
        <InfoWindow anchor={GOOGLE_MAPS_MAP_ID ? advancedMarker : classicMarker} onCloseClick={() => setOpen(false)}>
          <p style={{ fontWeight: 600, margin: 0 }}>{marker.title}</p>
          {marker.subtitle && <p style={{ margin: '2px 0 0', color: '#5a534a' }}>{marker.subtitle}</p>}
        </InfoWindow>
      )}
    </>
  )
}

function MapCanvas({ center, zoom, height, markers, fallbackLink }) {
  const isLoaded = useApiIsLoaded()
  const status = useApiLoadingStatus()

  if (status === 'FAILED' || status === 'AUTH_FAILURE') {
    return (
      <MapMessage height={height} link={fallbackLink}>
        The map could not load. Check that the Google Maps API key is valid and the Maps JavaScript API is enabled.
      </MapMessage>
    )
  }
  if (!isLoaded) {
    return <MapMessage height={height}>Loading map…</MapMessage>
  }

  return (
    <div className="overflow-hidden rounded-xl">
      <Map
        // Remount when the place changes so the map re-centres on the new listing.
        key={`${center.lat},${center.lng}`}
        mapId={GOOGLE_MAPS_MAP_ID || undefined}
        defaultCenter={center}
        defaultZoom={zoom}
        style={{ width: '100%', height }}
        gestureHandling="cooperative"
        clickableIcons={false}
      >
        {markers.map((marker) => <PlaceMarker key={marker.id || `${marker.lat}-${marker.lng}`} marker={marker} />)}
      </Map>
    </div>
  )
}

/**
 * Google Map with place markers. Without an API key it says so (with a link to the same
 * point on Google Maps when one is given) instead of failing silently.
 */
export default function GoogleMapView({ markers = [], center, zoom = 15, height = '400px', fallbackLink }) {
  const link = fallbackLink || (center ? googleMapsPlaceUrl(center) : undefined)

  if (!GOOGLE_MAPS_API_KEY) {
    return (
      <MapMessage height={height} link={link}>
        The interactive map isn&apos;t available yet (the Google Maps API key has not been configured).
      </MapMessage>
    )
  }

  return (
    <APIProvider apiKey={GOOGLE_MAPS_API_KEY}>
      <MapCanvas center={center} zoom={zoom} height={height} markers={markers} fallbackLink={link} />
    </APIProvider>
  )
}
