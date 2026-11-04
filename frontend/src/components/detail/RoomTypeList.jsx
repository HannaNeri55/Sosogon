import { FiUsers, FiCoffee, FiMoon } from 'react-icons/fi'
import { formatPesoRange, getImageUrl, IMAGE_FALLBACK_SVG } from '../../utils/helpers'

/**
 * The room types a stay offers, for information: name, beds, guests, features and any
 * published rate (with what it covers). These aren't bookable rooms or live availability.
 */
export default function RoomTypeList({ types }) {
  return (
    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
      {types.map((type) => {
        const price = formatPesoRange(type.price_min, type.price_max)
        return (
          <div key={type.id ?? type.name} className="rounded-xl border border-gray-200 bg-white p-4">
            {/* Credits for these photos are on the Photo Credits page, not under each image. */}
            {type.image_path && (
              <div className="-mx-4 -mt-4 mb-4">
                <img
                  src={getImageUrl(type.thumbnail_path || type.image_path)}
                  alt={`${type.name} (representative photo)`}
                  className="block aspect-video w-full rounded-t-xl object-cover bg-gray-100"
                  loading="lazy"
                  onError={(e) => { e.target.onerror = null; e.target.src = IMAGE_FALLBACK_SVG }}
                />
              </div>
            )}
            <div className="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
              <h3 className="font-heading font-semibold text-gray-900">{type.name}</h3>
              {price && <span className="text-sm font-semibold text-gray-900">{price}</span>}
            </div>
            {price && type.price_note && <p className="mt-0.5 text-xs text-gray-500">{type.price_note}</p>}
            {type.description && <p className="mt-2 text-sm text-gray-600 leading-relaxed">{type.description}</p>}
            <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-600">
              {type.max_guests > 0 && (
                <span className="inline-flex items-center gap-1">
                  <FiUsers size={12} aria-hidden="true" /> Up to {type.max_guests} guest{type.max_guests > 1 ? 's' : ''}
                </span>
              )}
              {type.bed_configuration && (
                <span className="inline-flex items-center gap-1">
                  <FiMoon size={12} aria-hidden="true" /> {type.bed_configuration}
                </span>
              )}
              {type.breakfast_included && (
                <span className="inline-flex items-center gap-1 font-medium text-secondary-700">
                  <FiCoffee size={12} aria-hidden="true" /> Breakfast included (as listed)
                </span>
              )}
            </div>
            {type.amenities?.length > 0 && (
              <ul className="mt-3 flex flex-wrap gap-1.5">
                {type.amenities.map((amenity) => (
                  <li key={amenity} className="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs text-gray-700">
                    {amenity}
                  </li>
                ))}
              </ul>
            )}
          </div>
        )
      })}
    </div>
  )
}
