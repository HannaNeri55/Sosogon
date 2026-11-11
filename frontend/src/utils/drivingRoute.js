// Driving routes between two saved map locations, from the public OSRM server (OpenStreetMap
// roads; free, no API key). Only real road routes are returned: when the server can't route,
// the caller gets an error and draws no line, never a straight-line stand-in.

const OSRM_URL = 'https://router.project-osrm.org/route/v1/driving'

// One request per leg per page load: reopening a leg's map reuses the route.
const cache = new Map()

/**
 * @param {{lat: number, lng: number}} origin
 * @param {{lat: number, lng: number}} destination
 * @returns {Promise<{ path: [number, number][], distanceKm: number, durationMin: number, offRoadM: [number, number] }>}
 *   path is [lat, lng] pairs along the road; offRoadM is how far each saved pin is from the
 *   road where the route starts and ends, in metres.
 */
export function fetchDrivingRoute(origin, destination) {
  const key = `${origin.lat},${origin.lng};${destination.lat},${destination.lng}`
  if (!cache.has(key)) {
    const request = fetch(`${OSRM_URL}/${origin.lng},${origin.lat};${destination.lng},${destination.lat}?overview=full&geometries=geojson`)
      .then((response) => (response.ok ? response.json() : Promise.reject(new Error(`Routing server answered ${response.status}`))))
      .then((data) => {
        const route = data?.code === 'Ok' ? data.routes?.[0] : null
        if (!route?.geometry?.coordinates?.length) throw new Error('No driving route found between these places')
        return {
          path: route.geometry.coordinates.map(([lng, lat]) => [lat, lng]),
          distanceKm: route.distance / 1000,
          durationMin: route.duration / 60,
          offRoadM: [data.waypoints?.[0]?.distance ?? 0, data.waypoints?.[1]?.distance ?? 0],
        }
      })
    // A failed request isn't kept, so Try again asks the server once more.
    request.catch(() => cache.delete(key))
    cache.set(key, request)
  }
  return cache.get(key)
}
