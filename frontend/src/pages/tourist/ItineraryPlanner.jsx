import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import {
  generateItinerary,
  getGenerationQuota,
  generateGuestItinerary,
  getGuestGenerationQuota,
  claimItinerary,
} from '../../api/itineraries'
import { useAuth } from '../../contexts/AuthContext'
import { savePendingItinerary, readPendingItinerary, clearPendingItinerary } from '../../utils/pendingItinerary'
import ItineraryPreferenceForm from '../../components/itinerary/ItineraryPreferenceForm'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import toast from 'react-hot-toast'
import { getImageUrl, formatTripCost } from '../../utils/helpers'
import { TRAVEL_PACES, BUDGETS } from '../../utils/constants'
import {
  FiCompass,
  FiSliders,
  FiMap,
  FiCalendar,
  FiActivity,
  FiDollarSign,
  FiClock,
  FiArrowRight,
  FiRefreshCw,
  FiLock,
} from 'react-icons/fi'

function labelFor(list, value) {
  const match = list.find((item) => item.value === value)
  if (match) return match.label
  return value ? value.charAt(0).toUpperCase() + value.slice(1) : ''
}

const PREVIEW_LIMIT = 5

// Whichever listing a stop points at (destination, food place, stay or souvenir shop).
function listingFor(stop) {
  return stop.destination || stop.food_beverage || stop.accommodation || stop.retail_shop
}

// What a guest sees before logging in: the trip summary and each day's destination, with
// everything else (times, stay, meals, souvenirs, reasons) locked behind login.
function LockedPreview({ preview }) {
  return (
    <div>
      <h3 className="text-xl font-heading font-semibold text-gray-900 mb-3">{preview.title}</h3>

      <div className="flex flex-wrap gap-2 mb-6">
        <span className="inline-flex items-center gap-1 text-xs font-medium text-primary-600 bg-primary-50 px-2.5 py-1 rounded-full">
          <FiCalendar size={11} /> {preview.num_days} day{preview.num_days > 1 ? 's' : ''}
        </span>
        <span className="inline-flex items-center gap-1 text-xs font-medium text-primary-600 bg-primary-50 px-2.5 py-1 rounded-full">
          <FiActivity size={11} /> {labelFor(TRAVEL_PACES, preview.travel_pace)}
        </span>
        <span className="inline-flex items-center gap-1 text-xs font-medium text-primary-600 bg-primary-50 px-2.5 py-1 rounded-full">
          <FiDollarSign size={11} /> {formatTripCost(preview, labelFor(BUDGETS, preview.budget))}
        </span>
        {preview.municipality && (
          <span className="inline-flex items-center gap-1 text-xs font-medium text-primary-600 bg-primary-50 px-2.5 py-1 rounded-full">
            <FiMap size={11} /> {preview.municipality}
          </span>
        )}
      </div>

      <div className="space-y-3 mb-6">
        {preview.days.map((day) => {
          // Newer previews list every destination of the day; older ones only the first.
          const destinations = day.destinations || (day.destination ? [day.destination] : [])
          return (
            <div key={day.day_number} className="flex items-center gap-3 p-3 bg-gray-50 rounded-lg">
              <div className="w-14 h-14 rounded-lg overflow-hidden shrink-0 bg-gray-200">
                <img
                  src={getImageUrl(destinations[0]?.image_path)}
                  alt={destinations[0]?.name || 'Free day'}
                  className="w-full h-full object-cover"
                  loading="lazy"
                />
              </div>
              <div className="min-w-0 flex-1">
                <p className="text-xs font-medium text-primary-600">Day {day.day_number}</p>
                <p className="font-semibold text-gray-900 truncate">
                  {destinations.length ? destinations.map((d) => d.name).join(' → ') : 'Free day at your stay'}
                </p>
              </div>
            </div>
          )
        })}
      </div>

      <div className="rounded-lg border border-dashed border-primary-200 bg-primary-50 p-5 text-center">
        <FiLock size={20} className="mx-auto text-primary-500 mb-2" />
        <p className="font-heading font-semibold text-gray-900">Log in to see your full itinerary</p>
        <p className="text-sm text-gray-600 mt-1 mb-4">
          {preview.locked_stops > 0 && `${preview.locked_stops} more stops are ready: `}
          exact times,{' '}
          {preview.stays?.length > 1
            ? `a stay in each town (${preview.stays.map((s) => s.town).filter(Boolean).join(', ')})`
            : 'your stay'}
          , breakfast, lunch and dinner spots, souvenir shopping, and why we picked each place.
        </p>
        <div className="flex flex-row gap-2">
          <Link to="/login" className="btn-primary flex-1 justify-center px-3 whitespace-nowrap">
            Log in <FiArrowRight size={16} />
          </Link>
          <Link to="/register/tourist" className="btn-secondary flex-1 justify-center px-3 whitespace-nowrap">
            Create account
          </Link>
        </div>
      </div>
    </div>
  )
}

export default function ItineraryPlanner() {
  const navigate = useNavigate()
  const { user, isAuthenticated, loading: authLoading } = useAuth()
  const isTourist = isAuthenticated && user?.role === 'tourist'
  const [loading, setLoading] = useState(false)
  const [claiming, setClaiming] = useState(false)
  const [generatedItinerary, setGeneratedItinerary] = useState(null)
  // A guest's locked preview; restored from localStorage so it survives a refresh.
  const [guestPreview, setGuestPreview] = useState(() => readPendingItinerary()?.preview || null)
  const [quota, setQuota] = useState(null)
  const claimStarted = useRef(false)
  const limitReached = quota?.remaining === 0

  useEffect(() => {
    if (authLoading) return
    if (isTourist) getGenerationQuota().then(setQuota).catch(() => {})
    else if (!isAuthenticated) getGuestGenerationQuota().then(setQuota).catch(() => {})
  }, [authLoading, isTourist, isAuthenticated])

  const handleGenerate = async (preferences) => {
    if (isAuthenticated && !isTourist) {
      toast.error('Only tourist accounts can generate itineraries.')
      return
    }

    setLoading(true)
    try {
      if (isTourist) {
        const data = await generateItinerary(preferences)
        if (data.quota) setQuota(data.quota)
        setGeneratedItinerary(data.itinerary)
        toast.success('Itinerary generated successfully!')
      } else {
        const data = await generateGuestItinerary(preferences)
        if (data.quota) setQuota(data.quota)
        savePendingItinerary({ id: data.itinerary_id, token: data.claim_token, preview: data.preview })
        setGuestPreview(data.preview)
        toast.success('Your itinerary is ready — log in to see the full plan.')
      }
    } catch (e) {
      if (e.response?.data?.quota) setQuota(e.response.data.quota)
      toast.error(e.response?.data?.message || 'Failed to generate itinerary')
    } finally {
      setLoading(false)
    }
  }

  // A guest who generated an itinerary and then logged in: attach it to their account
  // and open it in full.
  useEffect(() => {
    if (authLoading || !isTourist || claimStarted.current) return
    const pending = readPendingItinerary()
    if (!pending) return
    claimStarted.current = true
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setClaiming(true)
    claimItinerary(pending.id, pending.token)
      .then((itinerary) => {
        clearPendingItinerary()
        toast.success('Your itinerary is saved to your account!')
        navigate(`/itineraries/${itinerary.id}`)
      })
      .catch((e) => {
        // Expired or already saved: nothing left to claim. Other errors keep it for a retry.
        if (e.response?.status === 404) clearPendingItinerary()
        setClaiming(false)
        toast.error(e.response?.data?.message || 'Could not open your saved itinerary. Please refresh to try again.')
      })
  }, [authLoading, isTourist, navigate])

  const showGuestPreview = !isAuthenticated && guestPreview !== null
  const stops = generatedItinerary?.items || []
  const previewStops = stops.slice(0, PREVIEW_LIMIT)
  const remaining = stops.length - previewStops.length

  return (
    <div className="bg-gray-50 min-h-screen">
      <div className="max-w-6xl mx-auto px-4 sm:px-6 py-12">
        <div className="mb-8">
          <p className="flex items-center gap-1.5 text-primary-600 font-medium mb-2">
            <FiCompass size={16} /> AI Planner
          </p>
          <h1 className="text-3xl sm:text-4xl font-heading font-bold text-gray-900">Plan Your Trip</h1>
          <p className="text-gray-600 mt-3 max-w-2xl">
            Enter your travel preferences and we'll generate a personalized itinerary using verified local destinations from your chosen municipalities in Sorsogon.
          </p>
        </div>

        <div className="grid grid-cols-1 lg:grid-cols-2 gap-8 items-start">
          <div className="bg-white rounded-xl p-6 sm:p-8 border border-gray-100">
            <div className="flex flex-wrap items-center justify-between gap-2 mb-6">
              <div className="flex items-center gap-2">
                <FiSliders size={18} className="text-primary-500" />
                <h2 className="font-heading font-semibold text-lg text-gray-900">Trip Preferences</h2>
              </div>
              {quota && (
                <span className={`text-xs font-medium ${limitReached ? 'text-red-500' : 'text-gray-500'}`}>
                  {limitReached
                    ? 'Daily limit reached — resets at midnight (PH time)'
                    : `${quota.remaining} of ${quota.limit} itineraries left today`}
                </span>
              )}
            </div>
            <ItineraryPreferenceForm onSubmit={handleGenerate} loading={loading || claiming} limitReached={limitReached} />
          </div>

          <div className="bg-white rounded-xl p-6 sm:p-8 border border-gray-100">
            <div className="flex items-center justify-between gap-2 mb-6">
              <div className="flex items-center gap-2">
                <FiMap size={18} className="text-primary-500" />
                <h2 className="font-heading font-semibold text-lg text-gray-900">Your Itinerary</h2>
              </div>
              {(generatedItinerary || showGuestPreview) && !loading && (
                <button
                  onClick={() => {
                    setGeneratedItinerary(null)
                    setGuestPreview(null)
                  }}
                  className="inline-flex items-center gap-1 text-xs font-medium text-gray-500 hover:text-primary-600 transition-colors"
                >
                  <FiRefreshCw size={12} /> Start over
                </button>
              )}
            </div>

            {loading || claiming ? (
              <div className="text-center py-16">
                <LoadingSpinner size="lg" />
                <p className="text-gray-500 mt-4">
                  {claiming ? 'Unlocking your full itinerary...' : 'Generating your itinerary...'}
                </p>
              </div>
            ) : showGuestPreview ? (
              <LockedPreview preview={guestPreview} />
            ) : generatedItinerary ? (
              <div>
                <h3 className="text-xl font-heading font-semibold text-gray-900 mb-3">
                  {generatedItinerary.title}
                </h3>

                <div className="flex flex-wrap gap-2 mb-6">
                  <span className="inline-flex items-center gap-1 text-xs font-medium text-primary-600 bg-primary-50 px-2.5 py-1 rounded-full">
                    <FiCalendar size={11} /> {generatedItinerary.num_days} day{generatedItinerary.num_days > 1 ? 's' : ''}
                  </span>
                  <span className="inline-flex items-center gap-1 text-xs font-medium text-primary-600 bg-primary-50 px-2.5 py-1 rounded-full">
                    <FiActivity size={11} /> {labelFor(TRAVEL_PACES, generatedItinerary.travel_pace)}
                  </span>
                  <span className="inline-flex items-center gap-1 text-xs font-medium text-primary-600 bg-primary-50 px-2.5 py-1 rounded-full">
                    <FiDollarSign size={11} /> {formatTripCost(generatedItinerary, labelFor(BUDGETS, generatedItinerary.budget))}
                  </span>
                  <span className="inline-flex items-center gap-1 text-xs font-medium text-primary-600 bg-primary-50 px-2.5 py-1 rounded-full">
                    <FiMap size={11} /> {generatedItinerary.total_distance_km} km
                  </span>
                </div>

                <div className="space-y-3 mb-6">
                  {previewStops.map((stop, index) => {
                    const listing = listingFor(stop)
                    return (
                      <div key={stop.id ?? index} className="flex items-center gap-3 p-3 bg-gray-50 rounded-lg">
                        <div className="w-14 h-14 rounded-lg overflow-hidden shrink-0 bg-gray-200">
                          <img
                            src={getImageUrl(listing?.images?.[0]?.image_path)}
                            alt={listing?.name || 'Stop'}
                            className="w-full h-full object-cover"
                            loading="lazy"
                          />
                        </div>
                        <div className="min-w-0 flex-1">
                          <p className="flex items-center gap-1 text-xs font-medium text-primary-600">
                            <FiClock size={10} /> Day {stop.day_number} • {stop.time_slot}
                          </p>
                          <p className="font-semibold text-gray-900 truncate">
                            {listing?.name || 'Listing no longer available'}
                          </p>
                          {stop.reason && <p className="text-xs text-gray-500 line-clamp-2">{stop.reason}</p>}
                        </div>
                      </div>
                    )
                  })}
                  {remaining > 0 && (
                    <p className="text-xs text-gray-400 text-center pt-1">
                      +{remaining} more stop{remaining !== 1 ? 's' : ''} in the full itinerary
                    </p>
                  )}
                </div>

                <button
                  onClick={() => navigate(`/itineraries/${generatedItinerary.id}`)}
                  className="btn-primary w-full justify-center"
                >
                  View Full Itinerary <FiArrowRight size={16} />
                </button>
              </div>
            ) : (
              <div className="text-center py-16">
                <FiMap size={40} className="mx-auto text-gray-300 mb-4" />
                <p className="text-gray-500">Your generated itinerary will appear here.</p>
                <p className="text-sm text-gray-400 mt-1">Fill out your preferences and generate to get started.</p>
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  )
}