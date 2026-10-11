import { Link } from 'react-router-dom'
import { FiCalendar, FiMapPin } from 'react-icons/fi'
import { formatShortDate, getImageUrl } from '../../utils/helpers'

export default function EventCard({ event }) {
  const dateLabel =
    event.end_date && event.end_date !== event.start_date
      ? `${formatShortDate(event.start_date)} – ${formatShortDate(event.end_date)}`
      : formatShortDate(event.start_date)

  return (
    <Link to={`/events/${event.id}`} className="card group border-2 border-gray-300">
      <div className="aspect-video overflow-hidden">
        <img
          src={getImageUrl(event.image_path)}
          alt={event.title}
          className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
          loading="lazy"
        />
      </div>
      <div className="p-4">
        <h3 className="font-heading font-bold text-lg text-primary-700 group-hover:text-primary-600 transition-colors mb-2">
          {event.title}
        </h3>
        <div className="flex items-center gap-1.5 text-sm text-gray-600 mb-1">
          <FiCalendar size={14} className="text-secondary-500 shrink-0" />
          {dateLabel}
        </div>
        {(event.municipality?.name || event.location) && (
          <div className="flex items-center gap-1.5 text-sm text-gray-600">
            <FiMapPin size={14} className="text-secondary-500 shrink-0" />
            {event.municipality?.name || event.location}
          </div>
        )}
      </div>
    </Link>
  )
}