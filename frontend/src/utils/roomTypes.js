import { ROOM_CAPACITY } from './constants'

const sleepsIn = (category) => ROOM_CAPACITY[category] ?? 2

/**
 * A stay's rooms by room type: how many can be booked (approved, priced, not marked occupied),
 * what one sleeps, the cheapest price, how many rooms the group needs and what that costs a night.
 */
export function roomTypes(stay, travelers) {
  const groups = {}
  for (const room of stay?.rooms || []) {
    const key = room.category || 'Room'
    groups[key] ??= { category: key, rooms: [], bookable: [] }
    groups[key].rooms.push(room)
    if (room.occupancy_status === 'unoccupied' && Number(room.price) > 0) groups[key].bookable.push(room)
  }
  return Object.values(groups).map((group) => {
    const sleeps = sleepsIn(group.category)
    const bookable = [...group.bookable].sort((a, b) => Number(a.price) - Number(b.price) || a.id - b.id)
    const needed = Math.ceil(travelers / sleeps)
    return {
      ...group,
      bookable,
      sleeps,
      needed,
      fits: bookable.length * sleeps >= travelers,
      perNight: bookable.slice(0, needed).reduce((sum, r) => sum + Number(r.price), 0),
      from: bookable[0] ? Number(bookable[0].price) : null,
      features: (() => {
        try {
          const raw = group.rooms.find((r) => r.room_features)?.room_features
          return Array.isArray(raw) ? raw : JSON.parse(raw || '[]')
        } catch {
          return []
        }
      })(),
    }
  })
}
