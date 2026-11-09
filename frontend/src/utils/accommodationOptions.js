// A stay's accommodation options: rooms, villas, cottages or the entire property. SOSOGON shows
// what a property offers for information; it doesn't track availability or take bookings.
import { formatPesoRange, getImageUrl, IMAGE_FALLBACK_SVG } from './helpers'

export const CONTACT_FOR_RATES = 'Contact the property for rates.'

export const UNIT_TYPES = [
  { value: 'room', label: 'Room' },
  { value: 'villa', label: 'Villa' },
  { value: 'cottage', label: 'Cottage' },
  { value: 'cabin', label: 'Cabin' },
  { value: 'guest_house', label: 'Guest house' },
  { value: 'other', label: 'Other' },
]

export const OFFERING_ARRANGEMENTS = [
  { value: 'units', label: 'Individual units', hint: 'Rooms, villas, cottages or other units' },
  { value: 'entire_property', label: 'Entire property', hint: 'The whole place for exclusive use' },
  { value: 'both', label: 'Both', hint: 'Individual units, or the whole place' },
]

export const CLASSIFICATIONS = ['Hotel', 'Resort', 'Beach Resort', 'Inn', 'Guesthouse', 'Homestay', 'Villa', 'Cottage']

// The text on a stay's type badge, joined from its categories in the order above:
// Hotel + Resort reads "Hotel & Resort", three read "Hotel, Resort & Inn". Display only;
// each category stays separate in the data and in the filters. Without categories, its main type.
export function accommodationTypeLabel(item) {
  const rank = (name) => (CLASSIFICATIONS.includes(name) ? CLASSIFICATIONS.indexOf(name) : CLASSIFICATIONS.length)
  const names = item?.classifications?.length
    ? [...new Set(item.classifications)].sort((a, b) => rank(a) - rank(b))
    : [item?.type].filter(Boolean)
  if (names.length < 2) return names[0] || ''
  return `${names.slice(0, -1).join(', ')} & ${names[names.length - 1]}`
}

export const PRICING_MODES = [
  { value: 'indicative', label: 'Indicative price' },
  { value: 'varies', label: 'Price varies' },
  { value: 'contact', label: 'Contact property for rates' },
]

export const optionName = (option) => option?.name || option?.room_number || 'Accommodation option'

export const isEntireProperty = (option) => option?.unit_type === 'entire_property'

export const unitTypeLabel = (value) =>
  value === 'entire_property' ? 'Entire property' : UNIT_TYPES.find((t) => t.value === value)?.label || 'Room'

export const optionFeatures = (option) => (Array.isArray(option?.room_features) ? option.room_features : [])

export const hasPrice = (option) => Number(option?.price) > 0

/** A photo of this option, or a neutral placeholder: never another place's photo standing in for it. */
export const optionImage = (image) => (image ? getImageUrl(image) : IMAGE_FALLBACK_SVG)

/**
 * What to show for an option's price: the amount and its note when it has one (always marked as
 * subject to confirmation), else why there's none. Never a guess, and never zero.
 *
 * @returns {{ amount: string|null, note: string }}
 */
export function optionPrice(option) {
  if (hasPrice(option)) {
    return {
      amount: formatPesoRange(option.price, option.price_max || option.price),
      note: option.price_note || 'Per night. Indicative; confirm with the property.',
    }
  }
  if (option?.pricing_mode === 'varies') {
    return { amount: null, note: `${option.price_note ? `${option.price_note}. ` : 'Rates vary by guests or dates. '}${CONTACT_FOR_RATES}` }
  }
  return { amount: null, note: CONTACT_FOR_RATES }
}

/** "Sleeps up to 4", "2 bedrooms" and the like, for only what the property stated. */
export function optionFacts(option) {
  const count = Number(option?.unit_count)
  const bedrooms = Number(option?.bedrooms)
  const guests = Number(option?.max_guests)
  return [
    count > 1 && `${count} ${isRoomLike(option) ? 'rooms' : 'units'} of this type`,
    guests > 0 && `Up to ${guests} guest${guests > 1 ? 's' : ''}`,
    bedrooms > 0 && `${bedrooms} bedroom${bedrooms > 1 ? 's' : ''}`,
    option?.bed_configuration,
    Number(option?.size_sqm) > 0 && `${Number(option.size_sqm)} sqm`,
  ].filter(Boolean)
}

const isRoomLike = (option) => !option?.unit_type || option.unit_type === 'room'
