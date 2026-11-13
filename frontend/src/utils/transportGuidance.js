// Directions between consecutive itinerary stops, matched to how the tourist said they'd get
// around (the itinerary's transport_preference). Every mode opens a road route map inside
// SOSOGON (OpenStreetMap roads, see drivingRoute.js); for public transport that is the road a
// jeepney or tricycle takes, without the routes or fares the routing can't know. All use each
// place's saved coordinates. SOSOGON has no transport data of its own, so this never names a route,
// operator, fare or schedule; distances and times from the saved itinerary say whether Google
// Maps measured them or they're estimates.
import { toCoordinates, googleMapsRouteUrl } from '../components/maps/mapsConfig'
import { placeFor } from './itineraryStops'

export const TRANSPORT_GUIDES = {
  private_car: {
    label: 'Private Car',
    travelmode: 'driving',
    inSiteRoute: true,
    linkLabel: 'Driving directions',
    time: 'drive',
    overview: 'Tap Driving directions between two stops to see the driving route on a map here, from their saved map locations.',
  },
  rented_vehicle: {
    label: 'Rented Vehicle',
    travelmode: 'driving',
    inSiteRoute: true,
    linkLabel: 'Driving directions',
    time: 'drive',
    overview:
      "Tap Driving directions between two stops to see the driving route on a map here, from their saved map locations. SOSOGON doesn't list rental providers or rates, so arrange your rental with a provider you trust.",
  },
  motorcycle: {
    label: 'Motorcycle',
    travelmode: 'driving',
    inSiteRoute: true,
    linkLabel: 'Road directions',
    time: 'by road (car estimate)',
    overview:
      "Tap Road directions between two stops to see the road route on a map here. It's a car route, not a motorcycle-specific one, so check road conditions and local rules.",
  },
  // Jeepneys, tricycles, buses and vans use the same roads, so the road route and live navigation
  // are shown here too; the routing has no transit data, so routes, fares and schedules aren't.
  public_transport: {
    label: 'Public Transportation',
    travelmode: 'transit',
    inSiteRoute: true,
    linkLabel: 'Road directions',
    time: 'by public transport, incl. waiting (estimate)',
    overview:
      "Travel times between stops are public-transport estimates that include waiting and transfers (a tricycle for short hops, a jeepney, bus or van plus a tricycle for longer ones). Tap Road directions between two stops to see the road route on a map here, or Navigate here to follow it live. SOSOGON doesn't list routes, fares or schedules: ask your accommodation, the municipal tourism office or locals, and confirm the fare and the last trip before you ride.",
  },
  no_preference: {
    label: 'No Preference',
    travelmode: '',
    inSiteRoute: true,
    linkLabel: 'Road directions',
    time: 'by car (estimate)',
    overview:
      "Tap Road directions between two stops to see the road route on a map here. SOSOGON doesn't recommend a vehicle or list fares; confirm local transport options before you go.",
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
 * Guidance for the trip from stop $from to stop $to: the two saved map points, how to show the
 * way between them, and what the saved itinerary says about the leg. Null when either place has
 * no usable coordinates (no route is better than a wrong one) or both are the same spot.
 *
 * @returns {{ origin, destination, inSiteRoute: boolean, url: string|null, linkLabel: string, facts: string[] } | null}
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

  return {
    origin,
    destination,
    inSiteRoute: guide.inSiteRoute,
    url: guide.inSiteRoute ? null : googleMapsRouteUrl(origin, destination, guide.travelmode),
    linkLabel: guide.linkLabel,
    facts,
  }
}
