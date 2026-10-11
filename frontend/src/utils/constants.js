export const INTERESTS = [
  { value: 'nature', label: 'Nature' },
  { value: 'culture', label: 'Culture' },
  { value: 'food', label: 'Food' },
  { value: 'adventure', label: 'Adventure' },
  { value: 'shopping', label: 'Shopping' },
  { value: 'relaxation', label: 'Relaxation' },
]

// One destination a day; the pace sets when the day starts and how long each activity takes.
export const TRAVEL_PACES = [
  { value: 'relaxed', label: 'Relaxed', detail: 'Starts ~8:30 AM · 1½ hrs per activity' },
  { value: 'moderate', label: 'Moderate', detail: 'Starts ~7:30 AM · 1 hr per activity' },
  { value: 'packed', label: 'Packed', detail: 'Starts ~6:30 AM · 45 min per activity + merienda' },
]

export const BUDGETS = [
  { value: 'budget', label: 'Budget', range: '₱1,700 – ₱3,100' },
  { value: 'moderate', label: 'Moderate', range: '₱4,500 – ₱8,000' },
  { value: 'luxury', label: 'Luxury', range: '₱11,500 – ₱28,000+' },
]

export const CUISINE_PREFERENCES = [
  { value: 'filipino_bicolano', label: 'Filipino / Bicolano' },
  { value: 'seafood', label: 'Seafood' },
  { value: 'fast_food', label: 'Fast Food / Casual' },
  { value: 'fine_dining', label: 'Fine Dining' },
  { value: 'street_food', label: 'Street Food / Local' },
  { value: 'vegetarian', label: 'Vegetarian-friendly' },
  { value: 'no_preference', label: 'No Preference' },
]

export const ACCOMMODATION_TYPES = [
  { value: 'resort', label: 'Resort' },
  { value: 'hotel', label: 'Hotel' },
  { value: 'homestay', label: 'Homestay / BnB' },
  { value: 'budget', label: 'Budget / Backpacker' },
  { value: 'no_preference', label: 'No Preference' },
]

export const PRICE_RANGES = [
  { value: 'budget', label: 'Budget' },
  { value: 'moderate', label: 'Moderate' },
  { value: 'luxury', label: 'Luxury' },
]

export const ROOM_CATEGORIES = [
  { value: 'Standard', label: 'Standard' },
  { value: 'Deluxe', label: 'Deluxe' },
  { value: 'Suite', label: 'Suite' },
  { value: 'Family Room', label: 'Family Room' },
]

export const SUBMISSION_TYPES = [
  { value: 'accommodation', label: 'Accommodation' },
  { value: 'food_beverage', label: 'Food & Beverage' },
  { value: 'retail_product', label: 'Retail Product' },
]

export const STATUS_COLORS = {
  pending: 'bg-yellow-100 text-yellow-800',
  approved: 'bg-green-100 text-green-800',
  rejected: 'bg-red-100 text-red-800',
  removed: 'bg-gray-200 text-gray-700',
  published: 'bg-green-100 text-green-800',
  draft: 'bg-gray-100 text-gray-800',
  archived: 'bg-gray-100 text-gray-600',
}