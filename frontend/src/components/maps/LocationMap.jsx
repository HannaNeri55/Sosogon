import LeafletMapView from './LeafletMapView'
import MapMessage from './MapMessage'
import { toCoordinates } from './mapsConfig'

/**
 * The Location section map of a detail page: a pin at this listing's own saved
 * coordinates. Without valid coordinates it says the location isn't verified yet,
 * never a guessed or town-centre pin.
 */
export default function LocationMap({ latitude, longitude, title, address, height = '400px' }) {
  const point = toCoordinates(latitude, longitude)

  if (!point) {
    return (
      <MapMessage height={height}>
        The exact location of {title || 'this place'} has not been verified yet.
        {address && <span className="mt-1 block text-gray-500">Address: {address}</span>}
      </MapMessage>
    )
  }

  return (
    <LeafletMapView
      center={point}
      zoom={16}
      height={height}
      markers={[{ id: 'place', lat: point.lat, lng: point.lng, title: title || 'Location', subtitle: address }]}
    />
  )
}
