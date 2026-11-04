import { Link } from 'react-router-dom'
import { FiMapPin, FiStar } from 'react-icons/fi'
import { getImageUrl, IMAGE_FALLBACK_SVG } from '../../utils/helpers'

export default function DestinationCard({ destination }) {
  const image =
    destination.images?.find((img) => img.is_primary) ||
    destination.images?.[0]
  const imageUrl = getImageUrl(image, { thumbnail: true })
  const location = destination.municipality?.name || ''
  const rating = Number(destination.average_rating) || 0

  return (
    <Link
      to={`/destinations/${destination.id}`}
      className="card group flex h-full flex-col hover:-translate-y-0.5 transition-[box-shadow,transform] duration-200"
    >
      <div className="relative aspect-4/3 overflow-hidden bg-gray-200">
        <img
          src={imageUrl}
          alt={destination.name}
          className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500"
          loading="lazy"
          onError={(e) => { e.target.onerror = null; e.target.src = IMAGE_FALLBACK_SVG }}
        />
        {destination.category?.name && (
          <span className="absolute top-3 left-3 text-xs font-semibold text-gray-900 bg-white/95 backdrop-blur-sm px-2.5 py-1 rounded-full shadow-sm">
            {destination.category.name}
          </span>
        )}
        {rating > 0 && (
          <span className="absolute top-3 right-3 inline-flex items-center gap-1 text-xs font-semibold text-gray-900 bg-white/95 backdrop-blur-sm px-2 py-1 rounded-full shadow-sm">
            <FiStar size={12} className="fill-accent-400 text-accent-400" aria-hidden="true" />
            {rating.toFixed(1)}
          </span>
        )}
      </div>
      <div className="flex flex-1 flex-col p-4">
        <h3 className="font-heading font-semibold text-lg leading-snug text-gray-900 group-hover:text-primary-600 transition-colors line-clamp-2">
          {destination.name}
        </h3>
        {location && (
          <p className="mt-1 flex items-center gap-1 text-xs font-medium text-gray-500">
            <FiMapPin size={12} className="shrink-0" aria-hidden="true" />
            {location}
          </p>
        )}
        {destination.description && (
          <p className="mt-2 text-sm text-gray-600 line-clamp-2">{destination.description}</p>
        )}
      </div>
    </Link>
  )
}
