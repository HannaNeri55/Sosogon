// Itinerary times are stored as labels like "7:30 AM – 11:30 AM"; <input type="time"> uses "07:30".
const SEPARATOR = ' – '

export function labelToInput(label) {
  const match = (label || '').trim().match(/^(\d{1,2}):(\d{2})\s*([AP])M$/i)
  if (!match) return ''
  const hour = (Number(match[1]) % 12) + (match[3].toUpperCase() === 'P' ? 12 : 0)
  return `${String(hour).padStart(2, '0')}:${match[2]}`
}

export function inputToLabel(value) {
  if (!value) return ''
  const [hour, minute] = value.split(':').map(Number)
  return `${hour % 12 || 12}:${String(minute).padStart(2, '0')} ${hour < 12 ? 'AM' : 'PM'}`
}

export function splitSlot(slot) {
  const [start = '', end = ''] = (slot || '').split(SEPARATOR)
  return { start: labelToInput(start), end: labelToInput(end) }
}

export function joinSlot(start, end) {
  return [inputToLabel(start), inputToLabel(end)].filter(Boolean).join(SEPARATOR)
}

// Why a time range can't be saved, or null when it's fine.
export function slotError(slot) {
  const { start, end } = splitSlot(slot)
  if (!start) return 'Set a start time'
  if (!end) return 'Set an end time'
  if (end <= start) return 'End must be after start'
  return null
}

// [start, end] in minutes after midnight, or null unless the slot is a full range.
export function slotMinutes(slot) {
  const { start, end } = splitSlot(slot)
  if (!start || !end || end <= start) return null
  const toMinutes = (value) => Number(value.slice(0, 2)) * 60 + Number(value.slice(3))
  return [toMinutes(start), toMinutes(end)]
}

export function minutesToSlot(start, end) {
  const toInput = (minutes) => `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`
  return joinSlot(toInput(start), toInput(end))
}

// Only a meal or shop stop may sit inside another stop's time (like a lunch break during a visit).
const NESTABLE_TYPES = ['food_beverage', 'retail_shop']

/**
 * Why a day's stops ([{ name, type, slot }]) can't be saved together, or null: two stops may
 * not overlap unless one sits fully inside the other and the inner one is a meal or shop stop.
 */
export function overlapProblem(stops, dayNumber) {
  const timed = stops.map((stop) => ({ ...stop, range: slotMinutes(stop.slot) })).filter((stop) => stop.range)
  for (let i = 0; i < timed.length; i++) {
    for (let j = i + 1; j < timed.length; j++) {
      const [a, b] = [timed[i], timed[j]]
      if (a.range[0] >= b.range[1] || b.range[0] >= a.range[1]) continue
      const aHoldsB = a.range[0] <= b.range[0] && b.range[1] <= a.range[1]
      const bHoldsA = b.range[0] <= a.range[0] && a.range[1] <= b.range[1]
      const inner = aHoldsB ? b : bHoldsA ? a : null
      if (!inner || !NESTABLE_TYPES.includes(inner.type)) {
        return `${a.name} (${a.slot}) overlaps ${b.name} (${b.slot}) on Day ${dayNumber}. Change one of their times.`
      }
    }
  }
  return null
}

// The first free stretch of `length` minutes between 8 AM and 6 PM, as a slot label, or ''.
export function firstFreeSlot(stops, length = 120) {
  const busy = stops.map((stop) => slotMinutes(stop.slot)).filter(Boolean).sort((a, b) => a[0] - b[0])
  let cursor = 8 * 60
  for (const [start, end] of busy) {
    if (start - cursor >= length) break
    cursor = Math.max(cursor, end)
  }
  return cursor + length <= 18 * 60 ? minutesToSlot(cursor, cursor + length) : ''
}
