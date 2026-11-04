// Estimated details (typical hours, fee and price ranges, usual check-in) for what a listing
// doesn't state itself. They come from the API's `estimates` and are always shown as
// approximate, never as the listing's own information; a real value always wins.

export function estimateOf(listing, field) {
  return listing?.estimates?.find((estimate) => estimate.field === field)?.value ?? null
}

const peso = (amount) => `₱${Number(amount).toLocaleString('en-PH')}`

/** "1500-3500" → "Est. ₱1,500–₱3,500 / night"; a zero estimate reads "Usually free". */
export function approxRange(value, suffix = '') {
  if (!value) return null
  const [min, max = min] = String(value).split('-').map(Number)
  if (max <= 0) return 'Usually free'
  return `Est. ${min === max ? peso(min) : `${peso(min)}–${peso(max)}`}${suffix}`
}

export function approxHours(value) {
  return value ? `${value} (typical hours, confirm before visiting)` : null
}

/** A stop's cost note that includes estimated prices ("Est. per person", "1 dish × 2 (incl. est. prices)"). */
export function costNoteIsEstimate(note) {
  return /^Est\.|incl\. est\. prices/i.test(note || '')
}

/** A stay costed from its price level rather than room prices: no rooms booked there, yet a cost. */
export function stayCostIsEstimate(stay, rooms) {
  return (rooms?.length ?? 0) === 0 && Number(stay?.cost_max) > 0
}
