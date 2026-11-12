import usePageTitle from '../../hooks/usePageTitle'
import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { FiMapPin, FiClock, FiSun, FiShoppingBag, FiPhone, FiUser } from 'react-icons/fi'
import { getEvent } from '../../api/events'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import PageHeader from '../../components/layout/PageHeader'
import DetailLayout from '../../components/detail/DetailLayout'
import DetailSection from '../../components/detail/DetailSection'
import FactsCard from '../../components/detail/FactsCard'
import { formatDate, getImageUrl } from '../../utils/helpers'
import LoadError from '../../components/common/LoadError'

export default function EventDetail() {
  const { id } = useParams()
  const [event, setEvent] = useState(null)
  usePageTitle(event?.title)
  const [loading, setLoading] = useState(true)
  // True when the request failed for a reason other than "not found"; attempt re-runs the load.
  const [loadFailed, setLoadFailed] = useState(false)
  const [attempt, setAttempt] = useState(0)

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setLoading(true)
    getEvent(id)
      .then((data) => {
        setEvent(data)
        setLoadFailed(false)
      })
      .catch((error) => {
        setEvent(null)
        setLoadFailed(error.response?.status !== 404)
      })
      .finally(() => setLoading(false))
  }, [id, attempt])

  const retry = () => setAttempt((n) => n + 1)

  if (loading) return <LoadingSpinner size="lg" />
  if (!event && loadFailed) return <div className="px-4 py-12 sm:py-16"><LoadError what="this event" onRetry={retry} className="max-w-xl mx-auto" /></div>
  if (!event) return <div className="p-6 sm:p-8 text-center text-gray-500">Event not found.</div>

  const dateLabel =
    event.end_date && event.end_date !== event.start_date
      ? `${formatDate(event.start_date)} – ${formatDate(event.end_date)}`
      : formatDate(event.start_date)

  return (
    <div className="bg-gray-50 min-h-screen">
      <div className="max-w-6xl mx-auto px-4 sm:px-6">
        <PageHeader
          back={{ to: '/events', label: 'Back to Events' }}
          eyebrow={
            <span className="inline-block text-xs font-semibold uppercase tracking-wider text-secondary-700 bg-secondary-50 border border-secondary-100 px-3 py-1 rounded-full">
              Event
            </span>
          }
          title={event.title}
          subtitle={
            <span className="flex items-center gap-1.5">
              <FiClock size={15} className="text-primary-600" aria-hidden="true" /> {dateLabel}
            </span>
          }
        />
        {/* Only the admin's uploaded cover is shown; events without one go straight to the details. */}
        {event.image_path && (
          <div className="aspect-video max-h-128 w-full rounded-2xl overflow-hidden mb-8 bg-gray-200">
            <img src={getImageUrl(event.image_path)} alt={event.title} className="w-full h-full object-cover" />
          </div>
        )}
      </div>

      <DetailLayout
        aside={
          <FactsCard
            title="Event details"
            facts={[
              { icon: FiMapPin, label: 'Location', value: event.municipality?.name || event.location || 'Province-wide' },
              { icon: FiClock, label: 'Date', value: dateLabel },
              { icon: FiSun, label: 'What To Wear', value: event.what_to_wear },
              { icon: FiShoppingBag, label: 'What To Bring', value: event.what_to_bring },
              { icon: FiUser, label: 'Contact', value: event.contact_name },
              {
                icon: FiPhone,
                label: 'Mobile',
                value: event.contact_phone && (
                  <a href={`tel:${event.contact_phone}`} className="text-primary-700 underline">
                    {event.contact_phone}
                  </a>
                ),
              },
            ]}
            name={event.title}
            latitude={event.latitude}
            longitude={event.longitude}
          />
        }
      >
        <DetailSection title="About this event">
          <p className="text-gray-700 leading-relaxed whitespace-pre-line">{event.description}</p>
        </DetailSection>

        {event.travel_tips?.length > 0 && (
          <DetailSection title="Travel Tips">
            <ul className="space-y-3">
              {event.travel_tips.map((tip, i) => (
                <li key={i} className="flex gap-3 text-gray-700">
                  <span className="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-secondary-500" aria-hidden="true" />
                  {tip}
                </li>
              ))}
            </ul>
          </DetailSection>
        )}
      </DetailLayout>
    </div>
  )
}
