import { Link } from 'react-router-dom'
import { FiMapPin, FiNavigation, FiStar } from 'react-icons/fi'
import SampleBadge from '../common/SampleBadge'
import { formatDistance, getImageUrl, IMAGE_FALLBACK_SVG } from '../../utils/helpers'

export default function ListingCard({ item, basePath, typeLabel, distanceKm }) {
  const image = item.images?.find((img) => img.is_primary) || item.images?.[0]
  const imageUrl = getImageUrl(image, { thumbnail: true })
  const rating = Number(item.average_rating) || 0

  return (
    <Link to={`${basePath}/${item.id}`} className="card group flex h-full flex-col hover:-translate-y-0.5 transition-[box-shadow,transform] duration-200">
      <div className="relative aspect-4/3 overflow-hidden bg-gray-200">
        <img
          src={imageUrl}
          alt={item.name}
          className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500"
          loading="lazy"
          onError={(e) => { e.target.onerror = null; e.target.src = IMAGE_FALLBACK_SVG }}
        />
        {typeLabel && (
          <span className="absolute top-3 left-3 text-xs font-semibold text-gray-900 bg-white/95 backdrop-blur-sm px-2.5 py-1 rounded-full shadow-sm">
            {typeLabel}
          </span>
        )}
        {rating > 0 && (
          <span className="absolute top-3 right-3 inline-flex items-center gap-1 text-xs font-semibold text-gray-900 bg-white/95 backdrop-blur-sm px-2 py-1 rounded-full shadow-sm">
            <FiStar size={12} className="fill-accent-400 text-accent-400" aria-hidden="true" />
            {rating.toFixed(1)}
          </span>
        )}
        {distanceKm != null && (
          <span className="absolute bottom-3 left-3 inline-flex items-center gap-1 text-xs font-medium text-white bg-black/60 backdrop-blur-sm px-2 py-0.5 rounded-full">
            <FiNavigation size={11} aria-hidden="true" /> {formatDistance(distanceKm)}
          </span>
        )}
      </div>
      <div className="flex flex-1 flex-col p-4">
        {item.is_sample && <div className="mb-1"><SampleBadge /></div>}
        <h3 className="font-heading font-semibold text-lg leading-snug text-gray-900 group-hover:text-primary-600 transition-colors line-clamp-2">
          {item.name}
        </h3>
        {item.municipality?.name && (
          <p className="mt-1 flex items-center gap-1 text-xs font-medium text-gray-500">
            <FiMapPin size={12} className="shrink-0" aria-hidden="true" /> {item.municipality.name}
          </p>
        )}
        {item.description && (
          <p className="mt-2 text-sm text-gray-600 line-clamp-2">{item.description}</p>
        )}
        {item.price_range && (
          <div className="mt-auto pt-3">
            <span className="text-xs font-medium text-secondary-700 capitalize bg-secondary-50 px-2.5 py-1 rounded-full">
              {item.price_range}
            </span>
          </div>
        )}
      </div>
    </Link>
  )
}
