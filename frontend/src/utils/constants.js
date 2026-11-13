export const INTERESTS = [
  { value: 'nature', label: 'Nature' },
  { value: 'beaches', label: 'Beaches' },
  { value: 'culture', label: 'Culture' },
  { value: 'food', label: 'Food' },
  { value: 'adventure', label: 'Adventure' },
  { value: 'shopping', label: 'Shopping' },
  { value: 'relaxation', label: 'Relaxation' },
]

// Destination category IDs each interest plans from — the same map as the backend's
// ItineraryService::INTEREST_CATEGORIES (keep them in step). Food and Shopping plan restaurants
// and souvenir shops, not destinations.
export const INTEREST_CATEGORY_IDS = {
  nature: [1, 2, 3, 7, 8],
  beaches: [1, 8],
  culture: [4, 5],
  adventure: [2, 3, 7, 8],
  relaxation: [1, 6, 8, 9],
  food: [],
  shopping: [],
}

// How many travelers one room of each type sleeps — the backend's ItineraryService::ROOM_CAPACITY
// (keep them in step); any other room type sleeps 2.
export const ROOM_CAPACITY = { Standard: 2, Deluxe: 2, Suite: 4, 'Family Room': 6 }

// The pace sets how many attractions a day holds (one fewer on the arrival day), when breakfast
// is and how unhurried each visit is; meals, rest and travel are planned around them.
export const TRAVEL_PACES = [
  { value: 'relaxed', label: 'Relaxed', detail: 'Up to 2 attractions a day · breakfast ~7:15 AM · longer visits' },
  { value: 'moderate', label: 'Moderate', detail: 'Up to 3 attractions a day · breakfast ~7:00 AM' },
  { value: 'packed', label: 'Packed', detail: 'Up to 4 attractions a day · breakfast ~6:30 AM · afternoon merienda' },
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

// How the tourist plans to get around (one choice). Recorded with the request for later
// transportation guidance; it doesn't change the plan yet.
export const TRANSPORT_PREFERENCES = [
  { value: 'private_car', label: 'Private Car', detail: 'Your own car' },
  { value: 'motorcycle', label: 'Motorcycle', detail: 'Your own motorbike' },
  { value: 'rented_vehicle', label: 'Rented Vehicle', detail: 'A car, van or motorbike you rent' },
  { value: 'public_transport', label: 'Public Transportation', detail: 'Jeepneys, tricycles, buses or vans' },
  { value: 'no_preference', label: 'No Preference', detail: 'Let SOSOGON suggest practical options' },
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