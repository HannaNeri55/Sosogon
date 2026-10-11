import { Link } from 'react-router-dom'
import { FiMapPin } from 'react-icons/fi'
import { StarDisplay } from '../common/StarRating'
import { getImageUrl } from '../../utils/helpers'

export default function DestinationCard({ destination }) {
  const image = destination.images?.[0]
  const imageUrl = getImageUrl(image, { thumbnail: true })
  const location = destination.municipality?.name || ''

  return (
    <Link to={`/destinations/${destination.id}`} className="card group block">
      <div className="aspect-4/3 overflow-hidden">
        <img
          src={imageUrl}
          alt={destination.name}
          className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
          loading="lazy"
        />
      </div>
      <div className="p-4">
        <div className="flex items-center justify-between gap-2 mb-2">
          <span className="text-xs font-medium text-primary-600 bg-primary-50 px-2 py-0.5 rounded">
            {destination.category?.name}
          </span>
          <StarDisplay rating={destination.average_rating} size={14} />
        </div>
        <h3 className="font-heading font-semibold text-gray-900 mb-1 group-hover:text-primary-600 transition-colors">
          {destination.name}
        </h3>
        {location && (
          <p className="flex items-center gap-1 text-xs text-gray-400 mb-2">
            <FiMapPin size={11} className="shrink-0" />
            {location}
          </p>
        )}
        <p className="text-sm text-gray-500 line-clamp-2">
          {destination.description?.substring(0, 100)}...
        </p>
      </div>
    </Link>
  )
}