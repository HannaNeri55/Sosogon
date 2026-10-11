import GoogleMapView from './GoogleMapView'

export default function LocationMap({ latitude, longitude, title }) {
  const center = {
    lat: parseFloat(latitude) || 12.6744,
    lng: parseFloat(longitude) || 123.8756,
  }

  const markers = [
    {
      id: 1,
      lat: center.lat,
      lng: center.lng,
      title: title || 'Location',
    },
  ]

  return <GoogleMapView center={center} markers={markers} zoom={14} />
}