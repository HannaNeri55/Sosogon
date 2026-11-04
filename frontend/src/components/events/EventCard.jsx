import { Link } from 'react-router-dom'
import { FiCalendar, FiMapPin } from 'react-icons/fi'
import { formatDateKey, formatShortDate, getImageUrl } from '../../utils/helpers'

/** Month/day badge for an event's start date. */
function DateBadge({ date, className = '' }) {
  return (
    <span className={`flex w-14 flex-col items-center rounded-xl bg-white/95 py-1.5 text-center shadow-sm backdrop-blur-sm ${className}`}>
      <span className="text-[11px] font-semibold uppercase tracking-wider text-secondary-500">
        {date.toLocaleDateString('en-US', { month: 'short' })}
      </span>
      <span className="font-heading text-xl font-semibold leading-none text-gray-900">{date.getDate()}</span>
    </span>
  )
}

export default function EventCard({ event }) {
  const dateLabel =
    event.end_date && event.end_date !== event.start_date
      ? `${formatShortDate(event.start_date)} – ${formatShortDate(event.end_date)}`
      : formatShortDate(event.start_date)
  const start = new Date(String(event.start_date).slice(0, 10) + 'T00:00:00')
  const validStart = !Number.isNaN(start.getTime())
  // Same-format date strings compare correctly as text.
  const todayKey = formatDateKey(new Date())
  const happeningNow =
    String(event.start_date).slice(0, 10) <= todayKey &&
    String(event.end_date || event.start_date).slice(0, 10) >= todayKey

  return (
    <Link to={`/events/${event.id}`} className="card group flex h-full flex-col">
      <div className="relative aspect-video overflow-hidden bg-gray-200">
        {event.image_path ? (
          <img
            src={getImageUrl(event.image_path)}
            alt={event.title}
            className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500"
            loading="lazy"
          />
        ) : (
          // No cover uploaded yet: a plain branded panel, never a stand-in photo.
          <div className="flex h-full w-full items-center justify-center bg-linear-to-br from-primary-700 to-secondary-700">
            <FiCalendar size={40} className="text-white/25" aria-hidden="true" />
          </div>
        )}
        {validStart && <DateBadge date={start} className="absolute left-3 top-3" />}
        {happeningNow && (
          <span className="absolute right-3 top-3 inline-flex items-center gap-1.5 rounded-full bg-secondary-500 px-2.5 py-1 text-xs font-semibold text-white shadow-sm">
            <span className="h-1.5 w-1.5 rounded-full bg-white animate-pulse" aria-hidden="true" /> Happening now
          </span>
        )}
      </div>
      <div className="flex-1 p-4">
        <h3 className="font-heading font-semibold text-lg text-gray-900 group-hover:text-primary-600 transition-colors mb-2 line-clamp-2">
          {event.title}
        </h3>
        <div className="flex items-center gap-1.5 text-sm text-gray-600 mb-1">
          <FiCalendar size={14} className="text-secondary-500 shrink-0" aria-hidden="true" />
          {dateLabel}
        </div>
        {(event.municipality?.name || event.location) && (
          <div className="flex items-center gap-1.5 text-sm text-gray-600">
            <FiMapPin size={14} className="text-secondary-500 shrink-0" aria-hidden="true" />
            {event.municipality?.name || event.location}
          </div>
        )}
      </div>
    </Link>
  )
}
