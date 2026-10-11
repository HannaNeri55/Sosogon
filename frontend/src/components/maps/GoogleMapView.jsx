import { APIProvider, AdvancedMarker, Map, useApiIsLoaded } from '@vis.gl/react-google-maps'

const defaultCenter = {
  lat: 12.6744,
  lng: 123.8756,
}

function MapMessage({ height, children }) {
  return (
    <div className="bg-gray-100 rounded-xl flex items-center justify-center text-gray-500" style={{ height }}>
      {children}
    </div>
  )
}

function MapCanvas({ mapId, center, zoom, height, markers }) {
  const isLoaded = useApiIsLoaded()

  if (!isLoaded) {
    return <MapMessage height={height}>Loading map...</MapMessage>
  }

  return (
    <Map
      mapId={mapId}
      defaultCenter={center}
      defaultZoom={zoom}
      style={{ width: '100%', height }}
      gestureHandling="greedy"
    >
      {markers.map((marker) => (
        <AdvancedMarker
          key={marker.id || `${marker.lat}-${marker.lng}`}
          position={{ lat: parseFloat(marker.lat), lng: parseFloat(marker.lng) }}
          title={marker.title}
        />
      ))}
    </Map>
  )
}

export default function GoogleMapView({
  markers = [],
  center = defaultCenter,
  zoom = 12,
  height = '400px',
}) {
  const apiKey = import.meta.env.VITE_GOOGLE_MAPS_API_KEY || ''
  const mapId = import.meta.env.VITE_GOOGLE_MAPS_MAP_ID || ''

  if (!apiKey) {
    return <MapMessage height={height}>Google Maps API key not configured</MapMessage>
  }

  if (!mapId) {
    return <MapMessage height={height}>Google Maps Map ID not configured</MapMessage>
  }

  return (
    <APIProvider apiKey={apiKey}>
      <MapCanvas mapId={mapId} center={center} zoom={zoom} height={height} markers={markers} />
    </APIProvider>
  )
}
