import { useEffect, useRef, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { FiActivity, FiCalendar, FiDownload, FiHome, FiLoader, FiNavigation } from 'react-icons/fi'
import toast from 'react-hot-toast'
import { downloadSharedItineraryPdf, getSharedItinerary } from '../../api/itineraries'
import ItineraryDayCard from '../../components/itinerary/ItineraryDayCard'
import StayCard from '../../components/itinerary/StayCard'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import { BUDGETS, TRAVEL_PACES } from '../../utils/constants'
import { formatTripCost } from '../../utils/helpers'
import { itineraryStays } from '../../utils/selections'
import { transportGuide } from '../../utils/transportGuidance'
import { pdfFilename, saveBlobAs } from '../../utils/itineraryExport'

function labelFor(list, value) {
  const match = list.find((item) => item.value === value)
  if (match) return match.label
  return value ? value.charAt(0).toUpperCase() + value.slice(1) : ''
}

/** The read-only view of an itinerary its owner shared by link. */
export default function SharedItinerary() {
  const { token } = useParams()
  const [itinerary, setItinerary] = useState(null)
  const [status, setStatus] = useState('loading')
  const [downloading, setDownloading] = useState(false)
  const running = useRef(false)

  useEffect(() => {
    getSharedItinerary(token)
      .then((data) => {
        setItinerary(data)
        setStatus('ready')
      })
      .catch((error) => setStatus(error.response?.status === 404 ? 'gone' : 'offline'))
  }, [token])

  const handleDownload = async () => {
    if (running.current) return
    running.current = true
    setDownloading(true)
    try {
      saveBlobAs(await downloadSharedItineraryPdf(token), pdfFilename(itinerary.title))
      toast.success('PDF downloaded. It opens without internet.')
    } catch {
      toast.error('Could not create the PDF. Check your connection.')
    } finally {
      running.current = false
      setDownloading(false)
    }
  }

  if (status === 'loading') return <LoadingSpinner size="lg" />

  if (status !== 'ready') {
    return (
      <div className="max-w-lg mx-auto px-4 py-20 text-center">
        <h1 className="text-2xl font-heading font-bold text-gray-900 mb-2">
          {status === 'gone' ? 'This itinerary isn’t available' : 'Can’t load this itinerary'}
        </h1>
        <p className="text-gray-600 mb-6">
          {status === 'gone'
            ? 'The link may be wrong, or its owner may have stopped sharing it.'
            : 'A share link needs internet to open. Check your connection and try again.'}
        </p>
        <Link to="/plan-trip" className="btn-primary">Plan Your Own Trip</Link>
      </div>
    )
  }

  const stays = itineraryStays(itinerary)
  const guide = transportGuide(itinerary.transport_preference)
  const grouped = {}
  itinerary.items?.forEach((item) => {
    grouped[item.day_number] = grouped[item.day_number] || { stops: [] }
    grouped[item.day_number].stops.push(item)
  })
  const dayNumbers = Object.keys(grouped).map(Number).sort((a, b) => a - b)
  const stats = [
    { icon: <FiCalendar size={18} />, label: `${itinerary.num_days} Day${itinerary.num_days > 1 ? 's' : ''}` },
    { icon: <FiActivity size={18} />, label: `${labelFor(TRAVEL_PACES, itinerary.travel_pace)} pace` },
    { label: formatTripCost(itinerary, labelFor(BUDGETS, itinerary.budget)) },
    { icon: <FiNavigation size={18} />, label: `${itinerary.total_distance_km} km total` },
  ]

  return (
    <div className="bg-gray-50 min-h-screen">
      <div className="max-w-6xl mx-auto px-4 sm:px-6 pt-6 sm:pt-8 pb-12">
        <div className="bg-white rounded-xl p-5 sm:p-8 border border-gray-100 mb-6">
          <p className="text-primary-600 font-medium mb-2">Shared Itinerary</p>
          <h1 className="text-3xl sm:text-4xl font-heading font-bold text-gray-900 mb-4">{itinerary.title}</h1>
          <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 pt-5 border-t border-gray-100">
            {stats.map((stat) => (
              <div key={stat.label} className="flex items-center gap-2.5">
                {stat.icon && <span className="text-primary-500 shrink-0">{stat.icon}</span>}
                <span className="text-sm font-medium text-gray-700">{stat.label}</span>
              </div>
            ))}
          </div>
          <div className="flex flex-wrap items-center gap-3 mt-5 pt-5 border-t border-gray-100">
            <button
              type="button"
              onClick={handleDownload}
              disabled={downloading}
              className="inline-flex items-center justify-center gap-1.5 px-4 py-2.5 min-h-11 text-sm font-medium text-white bg-primary-600 rounded-lg hover:bg-primary-700 transition-colors disabled:opacity-60"
            >
              {downloading ? <FiLoader size={15} className="animate-spin" aria-hidden="true" /> : <FiDownload size={15} aria-hidden="true" />}
              {downloading ? 'Preparing…' : 'Download PDF'}
            </button>
            <p className="text-xs text-gray-500">This page needs internet. Download the PDF to have the plan offline.</p>
          </div>
        </div>

        {stays.length > 0 && (
          <div className="bg-white rounded-xl p-4 sm:p-6 border border-gray-100 mb-6">
            <p className="flex items-center gap-1.5 text-primary-600 font-medium mb-4">
              <FiHome size={16} /> {stays.length > 1 ? 'Stays' : 'Stay'}
            </p>
            <div className="space-y-4">
              {stays.map((stay) => <StayCard key={stay.id ?? 'main'} stay={stay} itinerary={itinerary} editMode={false} />)}
            </div>
          </div>
        )}

        <div className="bg-white rounded-xl p-4 sm:p-5 border border-gray-100 mb-6">
          <p className="flex items-center gap-1.5 text-primary-600 font-medium">
            <FiNavigation size={16} /> Getting around · {guide.label}
          </p>
          <p className="text-sm text-gray-600 mt-1.5 leading-relaxed">{guide.overview}</p>
        </div>

        <div className="space-y-6">
          {dayNumbers.map((dayNumber) => (
            <ItineraryDayCard
              key={dayNumber}
              day={grouped[dayNumber]}
              dayNumber={dayNumber}
              startDate={itinerary.start_date}
              overnight={stays.find((stay) => stay.night_days?.includes(dayNumber))}
              transportPreference={itinerary.transport_preference}
            />
          ))}
        </div>
      </div>
    </div>
  )
}
