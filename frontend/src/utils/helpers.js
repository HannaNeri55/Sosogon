const FALLBACK_IMAGE = 'https://images.unsplash.com/photo-1506929562872-bb421503ef21?w=400&q=80'

export const IMAGE_FALLBACK_SVG =
  "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='400' height='300'%3E%3Crect width='400' height='300' fill='%23E8F5F0'/%3E%3Ctext x='50%25' y='50%25' font-family='sans-serif' font-size='20' fill='%230E6B4F' text-anchor='middle' dominant-baseline='middle'%3ESorsogon%3C/text%3E%3C/svg%3E"

export function formatDate(dateString) {
  if (!dateString) return ''
  return new Date(dateString).toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  })
}

export function formatShortDate(dateString) {
  if (!dateString) return ''
  return new Date(dateString).toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  })
}

/** Whole days from now until the given date; negative once it has passed. */
export function daysUntil(dateString) {
  if (!dateString) return null
  return Math.ceil((new Date(dateString).getTime() - Date.now()) / 86400000)
}

export function truncate(str, length = 100) {
  if (!str) return ''
  return str.length > length ? str.substring(0, length) + '...' : str
}

function extractImagePath(image, { thumbnail = false } = {}) {
  if (typeof image === 'string') return image
  if (!image || typeof image !== 'object') return null
  return (thumbnail && image.thumbnail_path) ? image.thumbnail_path : image.image_path
}

// Public file storage: the R2 bucket's public URL in production, the Vite /storage proxy locally.
const STORAGE_URL = (import.meta.env.VITE_STORAGE_URL || '/storage').replace(/\/+$/, '')

export function getImageUrl(image, options = {}) {
  const path = extractImagePath(image, options)
  if (typeof path !== 'string' || path.length === 0) return FALLBACK_IMAGE
  return path.startsWith('http') ? path : `${STORAGE_URL}/${path}`
}

export function normalizeUrl(url) {
  if (!url) return null
  const trimmed = url.trim()
  if (/^https?:\/\//i.test(trimmed)) return trimmed
  // Explicitly reject any other URI scheme (javascript:, data:, vbscript:, file:, etc.)
  // rather than relying on string-prepending to neutralize it.
  if (/^[a-z][a-z0-9+.-]*:/i.test(trimmed)) return null
  return `https://${trimmed}`
}

export function getStatusBadgeClass(status) {
  const map = {
    pending: 'bg-yellow-100 text-yellow-800',
    approved: 'bg-green-100 text-green-800',
    rejected: 'bg-red-100 text-red-800',
    published: 'bg-green-100 text-green-800',
    draft: 'bg-gray-100 text-gray-800',
    archived: 'bg-gray-100 text-gray-600',
    active: 'bg-green-100 text-green-800',
    suspended: 'bg-red-100 text-red-800',
  }
  return map[status] || 'bg-gray-100 text-gray-800'
}

// Laravel serializes date-cast columns inconsistently (plain date vs ISO datetime) —
// slicing the first 10 chars normalizes either shape to YYYY-MM-DD.
export function toDateOnly(value) {
  if (!value) return null
  return String(value).slice(0, 10)
}

export function eventCoversDate(event, dateKey) {
  const start = toDateOnly(event.start_date)
  const end = toDateOnly(event.end_date) || start
  return dateKey >= start && dateKey <= end
}

export function formatDateKey(date) {
  const y = date.getFullYear()
  const m = String(date.getMonth() + 1).padStart(2, '0')
  const d = String(date.getDate()).padStart(2, '0')
  return `${y}-${m}-${d}`
}

export function getMonthMatrix(year, month) {
  // month is 1-indexed (1 = January). Returns Monday-first weeks covering the full month.
  const firstOfMonth = new Date(year, month - 1, 1)
  const firstWeekday = (firstOfMonth.getDay() + 6) % 7 // 0=Mon..6=Sun
  const gridStart = new Date(year, month - 1, 1 - firstWeekday)

  const cells = []
  for (let i = 0; i < 42; i++) {
    const date = new Date(gridStart)
    date.setDate(gridStart.getDate() + i)
    cells.push({
      date,
      dateKey: formatDateKey(date),
      day: date.getDate(),
      inMonth: date.getMonth() === month - 1,
    })
  }
  while (cells.length > 35 && cells.slice(-7).every((c) => !c.inMonth)) {
    cells.splice(-7, 7)
  }
  return cells
}
export function formatPeso(value) {
  return `₱${Math.round(Number(value)).toLocaleString('en-PH')}`
}

// "₱6,400 – ₱12,900", or a single amount when both ends match; null when there's no estimate.
export function formatPesoRange(min, max) {
  if (min == null || max == null) return null
  return Number(min) === Number(max) ? formatPeso(min) : `${formatPeso(min)} – ${formatPeso(max)}`
}

// An itinerary's estimated total for its whole group, e.g. "₱6,400 – ₱12,900 for 2 travelers".
// Itineraries generated before cost estimates existed get $fallback (their budget label).
export function formatTripCost(itinerary, fallback) {
  const range = formatPesoRange(itinerary?.estimated_cost_min, itinerary?.estimated_cost_max)
  if (!range) return fallback
  const travelers = Number(itinerary.travelers) || 1
  return `${range} for ${travelers} traveler${travelers > 1 ? 's' : ''}`
}
