// The budget is built from picked rooms (at the stay), dishes (at food stops) and souvenirs
// (at souvenir shops). These normalize the API's rows into picker options
// ({ id, name, price, image, subtitle }) and picks ({ id, quantity }).

function mergeById(...lists) {
  const byId = new Map()
  lists.flat().filter(Boolean).forEach((row) => byId.set(row.id, row))
  return [...byId.values()]
}

const firstImage = (row) => row.images?.[0]?.thumbnail_path || row.images?.[0]?.image_path || null

// Everything on the stop's menu, plus anything already picked that's no longer listed.
export function dishOptions(stop) {
  return mergeById(stop.food_beverage?.menu_items, stop.dishes).map((d) => ({
    id: d.id,
    name: d.name,
    price: Number(d.price) || 0,
    image: d.thumbnail_path || d.image_path,
    subtitle: d.description,
  }))
}

export function souvenirOptions(stop) {
  return mergeById(stop.retail_shop?.products, stop.souvenirs).map((p) => ({
    id: p.id,
    name: p.name,
    price: Number(p.price) || 0,
    image: firstImage(p),
    subtitle: p.product_type,
  }))
}

// "2 × Deluxe, 1 × Standard"
export function roomsSummary(rooms) {
  const counts = {}
  rooms.forEach((room) => {
    counts[room.category || 'Room'] = (counts[room.category || 'Room'] || 0) + 1
  })
  return Object.entries(counts)
    .map(([category, count]) => `${count} × ${category}`)
    .join(', ')
}

export function roomLabel(room) {
  // Owners often type the number as "Room 101" already; don't show "Room Room 101".
  const number = room.room_number && (/^room\b/i.test(room.room_number) ? room.room_number : `Room ${room.room_number}`)
  return [room.category, number].filter(Boolean).join(' · ')
}

// The itinerary's booked rooms that are at this stay's accommodation.
export function stayRooms(stay, itinerary) {
  const accommodationId = stay.accommodation?.id ?? stay.accommodation_id
  return (itinerary.rooms || []).filter((room) => room.accommodation_id === accommodationId)
}

// The stay's rooms to pick from, plus any already booked there that are no longer listed.
export function roomOptions(stay, itinerary) {
  return mergeById(stay.accommodation?.rooms, stayRooms(stay, itinerary)).map((r) => ({
    id: r.id,
    name: roomLabel(r),
    price: Number(r.price) || 0,
    image: firstImage(r),
    subtitle: r.description,
  }))
}

export function picksFrom(rows) {
  return (rows || []).map((row) => ({ id: row.id, quantity: row.pivot?.quantity ?? 1 }))
}

export function picksTotal(picks, options) {
  const prices = new Map(options.map((o) => [o.id, o.price]))
  return picks.reduce((sum, pick) => sum + (prices.get(pick.id) || 0) * pick.quantity, 0)
}

export function tripNights(itinerary) {
  return Math.max((Number(itinerary.num_days) || 1) - 1, 1)
}

// Nights spent at one stay (after which days: stay.night_days).
export function stayNights(stay) {
  return stay.night_days?.length ?? 0
}

/**
 * The trip's stays, one per town. Itineraries saved before stays existed get one stay built
 * from their single accommodation, for every night (its id is null: edits to it are sent as
 * the itinerary's accommodation).
 */
export function itineraryStays(itinerary) {
  if (itinerary.stays?.length) return itinerary.stays
  if (!itinerary.accommodation) return []
  const nights = tripNights(itinerary)
  return [
    {
      id: null,
      municipality_id: itinerary.accommodation.municipality_id,
      municipality: itinerary.accommodation.municipality,
      accommodation_id: itinerary.accommodation.id,
      accommodation: itinerary.accommodation,
      night_days: Array.from({ length: nights }, (_, i) => i + 1),
      reason: itinerary.accommodation_reason,
      note: itinerary.accommodation_note,
      cost_min: itinerary.accommodation_cost_min,
      cost_max: itinerary.accommodation_cost_max,
    },
  ]
}
