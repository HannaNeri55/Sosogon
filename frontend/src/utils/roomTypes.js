import { ROOM_CAPACITY } from './constants'
import { isEntireProperty } from './accommodationOptions'

/** Guests one unit sleeps: as the property stated, else assumed from its category, else unknown. */
export const unitSleeps = (option) => Number(option?.max_guests) || ROOM_CAPACITY[option?.category] || null

const unitsOf = (option) => Math.max(Number(option?.unit_count) || 1, 1)

/**
 * How many units of each picked option a group uses (the backend's ItineraryService::roomQuantities;
 * keep them in step): one of a single unit, or as many of a grouped option as those left need.
 *
 * @returns {Map<number, number>} option id => units
 */
export function roomQuantities(options, travelers) {
  let left = travelers
  const quantities = new Map()
  for (const option of options) {
    const sleeps = unitSleeps(option) ?? 2
    const units = Math.max(1, Math.min(unitsOf(option), Math.ceil(Math.max(left, 1) / sleeps)))
    quantities.set(option.id, units)
    left -= units * sleeps
  }
  return quantities
}

/**
 * A stay's options for a group. Rooms of one category can share a group (the backend's
 * ItineraryService::roomsForGroup; keep them in step): the option first, then the others of its
 * category. An option whose capacity isn't known never blocks a choice: the property confirms it.
 */
export function stayOptions(stay, travelers) {
  const options = stay?.rooms || []
  return options.map((option) => {
    const priced = Number(option.price) > 0
    const peers = option.category && !isEntireProperty(option)
      ? options
          .filter((o) => o.id !== option.id && o.category === option.category && (!priced || Number(o.price) > 0))
          .sort((a, b) => (Number(a.price) || 0) - (Number(b.price) || 0) || a.id - b.id)
      : []

    const chosen = []
    let holds = 0
    let known = true
    for (const candidate of [option, ...peers]) {
      if (holds >= travelers) break
      chosen.push(candidate)
      const sleeps = unitSleeps(candidate)
      if (sleeps === null) {
        known = false
        break
      }
      holds += sleeps * unitsOf(candidate)
    }

    const quantities = roomQuantities(chosen, travelers)
    const perNight = chosen.every((o) => Number(o.price) > 0)
      ? chosen.reduce((sum, o) => sum + Number(o.price) * quantities.get(o.id), 0)
      : null

    return {
      option,
      sleeps: unitSleeps(option),
      holds,
      capacityKnown: known,
      fits: !known || holds >= travelers,
      needed: [...quantities.values()].reduce((sum, n) => sum + n, 0),
      perNight,
    }
  })
}
