import { useEffect, useState } from 'react'
import { useParams, Link } from 'react-router-dom'
import { FiArrowLeft, FiMapPin, FiClock, FiSun, FiShoppingBag, FiPhone } from 'react-icons/fi'
import { getEvent } from '../../api/events'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import { formatDate, getImageUrl } from '../../utils/helpers'

export default function EventDetail() {
  const { id } = useParams()
  const [event, setEvent] = useState(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setLoading(true)
    getEvent(id)
      .then(setEvent)
      .catch(() => setEvent(null))
      .finally(() => setLoading(false))
  }, [id])

  if (loading) return <LoadingSpinner size="lg" />
  if (!event) return <div className="p-6 sm:p-8 text-center text-gray-500">Event not found.</div>

  const dateLabel =
    event.end_date && event.end_date !== event.start_date
      ? `${formatDate(event.start_date)} – ${formatDate(event.end_date)}`
      : formatDate(event.start_date)

  return (
    <div className="bg-gray-50 min-h-screen">
      <div className="max-w-4xl mx-auto px-4 sm:px-6 py-10">
        <div className="flex items-start justify-between gap-4 mb-6">
          <h1 className="min-w-0 wrap-break-word font-heading font-extrabold text-3xl sm:text-4xl uppercase text-secondary-700">
            {event.title}
          </h1>
          <Link
            to="/events"
            className="inline-flex items-center gap-1.5 font-semibold text-secondary-700 hover:text-secondary-800 shrink-0 mt-2"
          >
            <FiArrowLeft size={16} /> Back
          </Link>
        </div>

        <div className="aspect-video rounded-xl overflow-hidden mb-8">
          <img
            src={getImageUrl(event.image_path)}
            alt={event.title}
            className="w-full h-full object-cover"
          />
        </div>

        <p className="text-gray-700 leading-relaxed mb-8">{event.description}</p>

        <div className="space-y-3 mb-10">
          <InfoRow icon={<FiMapPin size={18} />} label="Location" value={event.municipality?.name || event.location || 'Province-wide'} />
          <InfoRow icon={<FiClock size={18} />} label="Date" value={dateLabel} />
          {event.what_to_wear && <InfoRow icon={<FiSun size={18} />} label="What To Wear" value={event.what_to_wear} />}
          {event.what_to_bring && <InfoRow icon={<FiShoppingBag size={18} />} label="What To Bring" value={event.what_to_bring} />}
        </div>

        {event.travel_tips?.length > 0 && (
          <section className="mb-10">
            <h2 className="font-heading font-extrabold text-2xl uppercase text-secondary-700 mb-3">
              Travel Tips
            </h2>
            <ul className="list-disc pl-5 space-y-2 text-gray-700">
              {event.travel_tips.map((tip, i) => (
                <li key={i}>{tip}</li>
              ))}
            </ul>
          </section>
        )}

        {(event.contact_name || event.contact_phone) && (
          <section>
            <h2 className="font-heading font-extrabold text-2xl uppercase text-secondary-700 mb-3">
              Travel Info
            </h2>
            <div className="flex items-start gap-3">
              <FiPhone size={18} className="text-secondary-600 mt-1 shrink-0" />
              <div>
                <p className="font-semibold text-gray-900">Contacts:</p>
                {event.contact_name && <p className="text-gray-700">{event.contact_name}</p>}
                {event.contact_phone && (
                  <p className="text-gray-700">
                    Mobile: <a href={`tel:${event.contact_phone}`} className="text-secondary-600 underline">{event.contact_phone}</a>
                  </p>
                )}
              </div>
            </div>
          </section>
        )}
      </div>
    </div>
  )
}

function InfoRow({ icon, label, value }) {
  return (
    <div className="flex items-center gap-3">
      <span className="text-secondary-600 shrink-0">{icon}</span>
      <span className="font-semibold text-gray-900 w-36 shrink-0">{label}:</span>
      <span className="text-gray-700">{value}</span>
    </div>
  )
}