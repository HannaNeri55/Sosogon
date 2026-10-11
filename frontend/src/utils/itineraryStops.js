// Stops can be a destination, the tourist's accommodation (a free day or a meal there), a
// food place (breakfast, lunch or dinner) or a souvenir shop; this normalizes whichever
// listing the stop points at.
export function placeFor(stop) {
  // Older itineraries split destinations (and free days) into a morning and an afternoon block.
  const part = stop.notes?.match(/^(Morning|Afternoon)/)?.[1]
  const withPart = (label) => [part, label].filter(Boolean).join(' · ')

  switch (stop.item_type) {
    case 'accommodation': {
      const meal = stop.notes?.match(/^(Breakfast|Lunch|Dinner) at/)?.[1]
      return {
        listing: stop.accommodation,
        link: stop.accommodation && `/accommodations/${stop.accommodation.id}`,
        badge: meal ? `${meal} at your stay` : withPart('Free day'),
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
