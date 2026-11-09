// frontend/src/components/maps/mapsConfig.js
// Google Maps settings and coordinate helpers shared by the map view and the location picker.

export const GOOGLE_MAPS_API_KEY = import.meta.env.VITE_GOOGLE_MAPS_API_KEY || ''
// Optional: with a Map ID the map uses Advanced Markers; without one it uses classic markers.
export const GOOGLE_MAPS_MAP_ID = import.meta.env.VITE_GOOGLE_MAPS_MAP_ID || ''

/** Whole-province view, used by the picker before a location is chosen (no pin is placed). */
export const SORSOGON_PROVINCE_VIEW = { center: { lat: 12.86, lng: 124.0 }, zoom: 9 }

/** A real, usable coordinate pair (in range and not the 0,0 placeholder), or null. */
export function toCoordinates(latitude, longitude) {
  if (latitude === null || latitude === undefined || latitude === '' || longitude === null || longitude === undefined || longitude === '') {
    return null
  }
  const lat = Number(latitude)
  const lng = Number(longitude)
  if (!Number.isFinite(lat) || !Number.isFinite(lng) || Math.abs(lat) > 90 || Math.abs(lng) > 180 || (lat === 0 && lng === 0)) {
    return null
  }
  return { lat, lng }
}

/** Google Maps page showing exactly these coordinates. */
export const googleMapsPlaceUrl = ({ lat, lng }) => `https://www.google.com/maps/search/?api=1&query=${lat},${lng}`

/** Google Maps directions to exactly these coordinates. */
export const googleMapsDirectionsUrl = ({ lat, lng }) => `https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}`

/**
 * Google Maps directions between two coordinates (a public link: no API key or billing).
 * travelmode is one Google Maps supports — driving, walking, bicycling or transit — or empty
 * to let Google Maps choose and offer the others.
 */
export const googleMapsRouteUrl = (origin, destination, travelmode = '') =>
  `https://www.google.com/maps/dir/?api=1&origin=${origin.lat},${origin.lng}&destination=${destination.lat},${destination.lng}${travelmode ? `&travelmode=${travelmode}` : ''}`
