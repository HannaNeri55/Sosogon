// Labels shared by the admin Guest Reviews page and its reported-reviews panel.
export const LISTING_TYPES = [
  { value: '', label: 'All listings' },
  { value: 'destination', label: 'Destinations', path: '/destinations' },
  { value: 'accommodation', label: 'Accommodations', path: '/accommodations' },
  { value: 'food_beverage', label: 'Restaurants', path: '/food-beverage' },
  { value: 'retail_shop', label: 'Shops', path: '/shops' },
  // Products have no public detail page, so they are listed without a link.
  { value: 'retail_product', label: 'Products' },
]

export const TYPE_BY_VALUE = Object.fromEntries(LISTING_TYPES.map((type) => [type.value, type]))

export const REPORT_STATUS = {
  pending: { label: 'Pending Review', className: 'bg-amber-50 text-amber-800 border-amber-200' },
  reviewed: { label: 'Reviewed', className: 'bg-sky-50 text-sky-800 border-sky-200' },
  action_taken: { label: 'Action Taken', className: 'bg-red-50 text-red-700 border-red-200' },
  dismissed: { label: 'Dismissed', className: 'bg-gray-100 text-gray-700 border-gray-200' },
}
