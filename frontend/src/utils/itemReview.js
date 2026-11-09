// frontend/src/utils/itemReview.js
import { CONTACT_FOR_RATES, unitTypeLabel } from './accommodationOptions'
import { formatPeso, formatPesoRange, formatProductPrice } from './helpers'

/** What differs between rooms, menu items and products in the admin review queue. */
export const ITEM_KINDS = {
  rooms: {
    type: 'Room', plural: 'Rooms',
    parent: (row) => row.accommodation,
    owner: (row) => row.accommodation?.user,
    municipality: (row) => row.accommodation?.municipality,
    title: (row) => [row.name || row.room_number, row.category || (row.unit_type && unitTypeLabel(row.unit_type))].filter(Boolean).join(' · '),
    images: (row) => row.images || [],
    fields: [['name', 'Name'], ['unit_type', 'Type'], ['category', 'Category'], ['unit_count', 'How many'], ['max_guests', 'Maximum guests'], ['bedrooms', 'Bedrooms'], ['bed_configuration', 'Beds'], ['size_sqm', 'Size (sqm)'], ['pricing_mode', 'Rates'], ['price', 'Price'], ['price_max', 'Up to'], ['price_note', 'What it covers'], ['description', 'Description'], ['notes', 'Notes'], ['room_features', 'Amenities in this unit']],
  },
  menu: {
    type: 'Menu Item', plural: 'Menu Items',
    parent: (row) => row.food_beverage,
    owner: (row) => row.food_beverage?.user,
    municipality: (row) => row.food_beverage?.municipality,
    title: (row) => row.name,
    images: (row) => (row.image_path ? [row] : []),
    fields: [['name', 'Name'], ['price', 'Price'], ['description', 'Description']],
  },
  products: {
    type: 'Product', plural: 'Products',
    parent: (row) => row.shop,
    owner: (row) => row.shop?.user || row.user,
    municipality: (row) => row.municipality,
    title: (row) => row.name,
    images: (row) => row.images || [],
    fields: [['name', 'Name'], ['product_type', 'Type'], ['price', 'Price'], ['description', 'Description'], ['website', 'Website']],
  },
}

export const REVIEW_TABS = [
  { value: 'new', label: 'New items' },
  { value: 'changes', label: 'Proposed changes' },
  { value: 'rejected', label: 'Rejected new items' },
]

export function displayValue(value) {
  if (value === null || value === undefined || value === '') return '—'
  if (Array.isArray(value)) return value.length ? value.join(', ') : '—'
  return String(value)
}

export function sameValue(a, b) {
  if (Array.isArray(a) || Array.isArray(b)) return JSON.stringify(a ?? []) === JSON.stringify(b ?? [])
  if (!Number.isNaN(Number(a)) && !Number.isNaN(Number(b)) && a !== '' && b !== '' && a !== null && b !== null) return Number(a) === Number(b)
  return (a ?? '') === (b ?? '')
}

export function reviewError(error, fallback) {
  const data = error?.response?.data
  return Object.values(data?.errors || {}).flat()[0] || data?.message || fallback
}

function priceOf(kind, source) {
  if (kind !== 'rooms') return formatProductPrice(source.price) ?? '—'
  if (source.pricing_mode === 'contact' || !(Number(source.price) > 0)) return CONTACT_FOR_RATES.replace('.', '')
  return Number(source.price_max) > Number(source.price) ? formatPesoRange(source.price, source.price_max) : formatPeso(source.price)
}

/** The price cell: a proposed change shows old and new when the price is part of it. */
export function priceLabel(kind, row, isChange) {
  const current = priceOf(kind, row)
  if (!isChange) return current
  const proposed = priceOf(kind, { ...row, ...(row.pending_changes?.values || {}) })
  return proposed === current ? current : `${current} → ${proposed}`
}

/** The fields a proposed change really alters, with their approved and proposed values. */
export function changedFields(kind, row) {
  const values = row.pending_changes?.values || {}
  return ITEM_KINDS[kind].fields
    .filter(([field]) => field in values && !sameValue(row[field], values[field]))
    .map(([field, label]) => ({ field, label, current: row[field], proposed: values[field] }))
}

/** The photos tourists would see once a proposed change is approved. */
export function photosAfterChange(kind, row) {
  const pending = row.pending_changes || {}
  const current = ITEM_KINDS[kind].images(row)
  const added = pending.new_images || []
  if (kind !== 'rooms') return added.length ? added : current
  return [...current.filter((image) => !(pending.remove_image_ids || []).includes(image.id)), ...added]
}

export function photosChanged(row) {
  const pending = row.pending_changes || {}
  return (pending.new_images || []).length > 0 || (pending.remove_image_ids || []).length > 0
}
