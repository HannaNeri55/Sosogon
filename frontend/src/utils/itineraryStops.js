// What a stop at the tourist's accommodation is for, read from how its notes start; anything
// else there (a free day) keeps the "Free day" badge.
const STAY_BADGES = [
  [/^Arrive and check in at/, 'Arrival & check-in'],
  // Arriving before the stay's listed check-in time: check-in is a later stop.
  [/^Arrive at/, 'Arrival'],
  [/^Check in at/, 'Check-in'],
  [/^Check out of/, 'Check-out'],
  [/^Back to .+ to rest/, 'Rest at your stay'],
  [/^Free time at/, 'Free time at your stay'],
]

// Stops can be a destination, the tourist's accommodation (arrival, check-in or check-out,
// rest, a free day or a meal there), a food place (breakfast, lunch or dinner) or a souvenir
// shop; this normalizes whichever listing the stop points at.
/**
 * The ids of stops at a place already shown earlier in the trip (the same stay for breakfast,
 * check-out and dinner…), whose description needn't be repeated.
 */
export function repeatedStopIds(items = []) {
  const seen = new Set()
  const repeated = new Set()
  ;[...items]
    .sort((a, b) => a.day_number - b.day_number || a.sequence - b.sequence)
    .forEach((item) => {
      const listing = placeFor(item).listing
      if (!listing?.id) return
      const key = `${item.item_type}-${listing.id}`
      if (seen.has(key)) repeated.add(item.id)
      seen.add(key)
    })
  return repeated
}

export function placeFor(stop) {
  // Older itineraries split destinations (and free days) into a morning and an afternoon block.
  const part = stop.notes?.match(/^(Morning|Afternoon)/)?.[1]
  const withPart = (label) => [part, label].filter(Boolean).join(' · ')

  switch (stop.item_type) {
    case 'accommodation': {
      const meal = stop.notes?.match(/^(Breakfast|Lunch|Dinner) at/)?.[1]
      const purpose = STAY_BADGES.find(([pattern]) => pattern.test(stop.notes || ''))?.[1]
      return {
        listing: stop.accommodation,
        link: stop.accommodation && `/accommodations/${stop.accommodation.id}`,
        badge: meal ? `${meal} at your stay` : purpose || withPart('Free day'),
      }
    }
    case 'food_beverage':
      return {
        listing: stop.food_beverage,
        link: stop.food_beverage && `/food-beverage/${stop.food_beverage.id}`,
        badge: stop.notes?.match(/^(Breakfast|Lunch|Dinner)/)?.[1] || 'Food stop',
      }
    case 'retail_shop':
      return {
        listing: stop.retail_shop,
        link: stop.retail_shop && `/shops/${stop.retail_shop.id}`,
        badge: 'Souvenir shopping',
      }
    default:
      return {
        listing: stop.destination,
        link: stop.destination && `/destinations/${stop.destination.id}`,
        badge: withPart(stop.destination?.category?.name),
      }
  }
}
