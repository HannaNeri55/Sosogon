// Directions between consecutive itinerary stops, matched to how the tourist said they'd get
// around (the itinerary's transport_preference). Links are public Google Maps directions
// between each place's saved coordinates. SOSOGON has no transport data of its own, so this
// never names a route, operator, fare or schedule; distances and times come from the saved
// itinerary and say whether Google Maps measured them or they're estimates.
import { toCoordinates, googleMapsRouteUrl } from '../components/maps/mapsConfig'
import { placeFor } from './itineraryStops'

export const TRANSPORT_GUIDES = {
  private_car: {
    label: 'Private Car',
    travelmode: 'driving',
    linkLabel: 'Driving directions',
    time: 'drive',
    overview: "Each link opens Google Maps' driving route between two stops, from their saved map locations.",
  },
  rented_vehicle: {
    label: 'Rented Vehicle',
    travelmode: 'driving',
    linkLabel: 'Driving directions',
    time: 'drive',
    overview:
      "Each link opens Google Maps' driving route between two stops, from their saved map locations. SOSOGON doesn't list rental providers or rates, so arrange your rental with a provider you trust.",
  },
  motorcycle: {
    label: 'Motorcycle',
    travelmode: 'driving',
    linkLabel: 'Road directions',
    time: 'by road (car estimate)',
    overview:
      "Each link opens Google Maps' driving route, the closest it offers. It isn't a motorcycle-specific route, so check road conditions and local rules.",
  },
  public_transport: {
    label: 'Public Transportation',
    travelmode: 'transit',
    linkLabel: 'Public transport in Google Maps',
    time: null,
    overview:
      "Each link opens Google Maps' public transport directions. Local jeepney, tricycle, bus and van routes in Sorsogon may not be listed there, and SOSOGON doesn't list routes, fares or schedules: ask your accommodation, the municipal tourism office or locals, and confirm the fare before you ride.",
  },
  no_preference: {
    label: 'No Preference',
    travelmode: '',
    linkLabel: 'Directions in Google Maps',
    time: 'by car (estimate)',
    overview:
      "Each link opens Google Maps, where you can compare driving, walking and public transport. SOSOGON doesn't recommend a vehicle or list fares; confirm local transport options before you go.",
  },
}

/** The guide for an itinerary's preference; older itineraries without one get No Preference. */
export function transportGuide(preference) {
  return TRANSPORT_GUIDES[preference] || TRANSPORT_GUIDES.no_preference
}

/** A stop's place on the map (its listing's saved coordinates), or null. */
export function stopCoordinates(stop) {
  const listing = placeFor(stop).listing
  return listing ? toCoordinates(listing.latitude, listing.longitude) : null
}

/**
 * Guidance for the trip from stop $from to stop $to: the directions link and what the saved
 * itinerary says about the leg. Null when either place has no usable coordinates (no link is
 * better than a wrong one) or both are the same spot.
 *
 * @returns {{ url: string, linkLabel: string, facts: string[] } | null}
 */
export function legGuidance(from, to, preference) {
  const origin = stopCoordinates(from)
  const destination = stopCoordinates(to)
  if (!origin || !destination || (origin.lat === destination.lat && origin.lng === destination.lng)) return null

  const guide = transportGuide(preference)
  // The planner notes whether Google Maps measured the leg or it was estimated from the map distance.
  const measured = /driving time from Google Maps/.test(to.notes || '')
  const facts = []
  const km = Number(to.distance_from_prev_km)
  if (km > 0) facts.push(measured ? `${km} km by road (Google Maps)` : `${km} km apart (straight line)`)
  const minutes = Number(to.travel_time_from_prev)
  if (guide.time && minutes > 0) {
    facts.push(measured && guide.time === 'drive' ? `~${minutes} min drive (Google Maps)` : `~${minutes} min ${guide.time === 'drive' ? 'drive (estimate)' : guide.time}`)
  }

  return { url: googleMapsRouteUrl(origin, destination, guide.travelmode), linkLabel: guide.linkLabel, facts }
}
