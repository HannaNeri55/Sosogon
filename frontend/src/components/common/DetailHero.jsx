import { Link } from 'react-router-dom'
import { FiArrowLeft, FiMapPin, FiNavigation, FiGlobe } from 'react-icons/fi'
import { StarDisplay } from './StarRating'
import { IMAGE_FALLBACK_SVG, normalizeUrl } from '../../utils/helpers'

export default function DetailHero({
  backTo,
  backLabel,
  badge,
  badgeClassName = 'bg-primary-500',
  title,
  subtitle,
  rating,
  reviewCount,
  imageUrl,
  imageAlt,
  latitude,
  longitude,
  website,
}) {
  const directionsUrl = latitude && longitude
    ? `https://www.google.com/maps/dir/?api=1&destination=${latitude},${longitude}`
    : null
  const websiteUrl = normalizeUrl(website)

  return (
    <div className="max-w-6xl mx-auto px-4 sm:px-6 pt-8">
      <Link
        to={backTo}
        className="inline-flex items-center gap-1.5 text-gray-500 hover:text-primary-600 text-sm font-medium mb-6 transition-colors"
      >
        <FiArrowLeft size={14} /> {backLabel}
      </Link>

      <div className="mb-5">
        {badge && (
          <span className={`inline-block text-white text-sm px-3 py-1 rounded-full mb-3 ${badgeClassName}`}>
            {badge}
          </span>
        )}
        <h1 className="text-3xl sm:text-4xl font-heading font-bold text-gray-900 leading-tight">{title}</h1>
        {subtitle && (
          <p className="text-gray-500 flex items-center gap-1.5 mt-2">
            <FiMapPin size={15} className="shrink-0" /> {subtitle}
          </p>
        )}
        {rating != null && (
          <div className="flex items-center gap-2 mt-3">
            <StarDisplay rating={rating} size={16} />
            <span className="text-sm text-gray-500">
              {Number(rating || 0).toFixed(1)} ({reviewCount ?? 0} {reviewCount === 1 ? 'review' : 'reviews'})
            </span>
          </div>
        )}
      </div>

      <div className="rounded-2xl overflow-hidden h-72 sm:h-96 lg:h-112 bg-gray-100 mb-6">
        <img
          src={imageUrl}
          alt={imageAlt}
          className="w-full h-full object-cover"
          fetchPriority="high"
          onError={(e) => { e.target.onerror = null; e.target.src = IMAGE_FALLBACK_SVG }}
        />
      </div>

      {(directionsUrl || websiteUrl) && (
        <div className="flex flex-wrap gap-3 mb-10">
          {directionsUrl && (
            <a href={directionsUrl} target="_blank" rel="noopener noreferrer" className="btn-primary">
              <FiNavigation size={16} /> Get Directions
            </a>
          )}
          {websiteUrl && (
            <a href={websiteUrl} target="_blank" rel="noopener noreferrer" className="btn-secondary">
              <FiGlobe size={16} /> Visit Website
            </a>
          )}
        </div>
      )}
    </div>
  )
}
