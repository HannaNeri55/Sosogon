import { Link } from 'react-router-dom'
import { FiNavigation } from 'react-icons/fi'
import { StarDisplay } from '../common/StarRating'
import { getImageUrl, IMAGE_FALLBACK_SVG } from '../../utils/helpers'

export default function ListingCard({ item, basePath, typeLabel, distanceKm }) {
  const image = item.images?.[0]
  const imageUrl = getImageUrl(image, { thumbnail: true })

  return (
    <Link to={`${basePath}/${item.id}`} className="card group">
      <div className="relative aspect-4/3 overflow-hidden">
        {distanceKm != null && (
          <span className="absolute bottom-2 left-2 z-10 inline-flex items-center gap-1 text-xs font-medium text-white bg-black/60 backdrop-blur-sm px-2 py-0.5 rounded-full">
            <FiNavigation size={11} /> {Number(distanceKm).toFixed(1)} km away
          </span>
        )}
        <img
          src={imageUrl}
          alt={item.name}
          className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
          loading="lazy"
          onError={(e) => { e.target.onerror = null; e.target.src = IMAGE_FALLBACK_SVG }}
        />
      </div>
      <div className="p-4">
        {typeLabel && (
          <span className="text-xs font-medium text-secondary-500 bg-secondary-50 px-2 py-0.5 rounded">
            {typeLabel}
          </span>
        )}
        <h3 className="font-heading font-semibold text-gray-900 mt-1 mb-1 group-hover:text-primary-600 transition-colors">
          {item.name}
        </h3>
        {item.municipality?.name && (
          <p className="text-xs text-gray-500 mb-1">{item.municipality.name}</p>
        )}
        <p className="text-sm text-gray-500 line-clamp-2 mb-2">
          {item.description?.substring(0, 100)}...
        </p>
        <div className="flex items-center justify-between">
          {item.average_rating !== undefined && <StarDisplay rating={item.average_rating} size={14} />}
          {item.price_range && (
            <span className="text-xs text-gray-500 capitalize bg-gray-100 px-2 py-0.5 rounded">
              {item.price_range}
            </span>
          )}
        </div>
      </div>
    </Link>
  )
}