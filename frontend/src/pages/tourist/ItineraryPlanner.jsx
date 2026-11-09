import usePageTitle from '../../hooks/usePageTitle'
import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { generateItinerary, getGenerationQuota, claimItinerary } from '../../api/itineraries'
import { useAuth } from '../../contexts/AuthContext'
import { readPendingItinerary, clearPendingItinerary, saveTripDraft, readTripDraft, clearTripDraft } from '../../utils/pendingItinerary'
import ItineraryPreferenceForm from '../../components/itinerary/ItineraryPreferenceForm'
import toast from 'react-hot-toast'
import { getImageUrl, formatTripCost } from '../../utils/helpers'
import { TRAVEL_PACES, BUDGETS } from '../../utils/constants'
import {
  FiCompass,
  FiSliders,
  FiMap,
  FiCalendar,
  FiActivity,
  FiClock,
  FiArrowRight,
  FiRefreshCw,
  FiLock,
  FiLogIn,
  FiUserPlus,
} from 'react-icons/fi'
import { showApiError } from '../../utils/apiErrors'

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
function LockedPreview({ preview, onUnlock }) {
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
          {formatTripCost(preview, labelFor(BUDGETS, preview.budget))}
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

      <button type="button" onClick={onUnlock} className="btn-primary w-full justify-center">
        <FiLock size={16} aria-hidden="true" /> Unlock your full itinerary
      </button>
    </div>
  )
}

// "12 more stops are ready: exact times, a stay in each town (…), breakfast, …"
function lockedDetail(preview) {
  // A trip planned without accommodation has no stays to mention.
  const stay = !preview.stays || preview.stays.length === 1
    ? 'your stay, '
    : preview.stays.length > 1
      ? `a stay in each town (${preview.stays.map((s) => s.town).filter(Boolean).join(', ')}), `
      : ''
  return `${preview.locked_stops > 0 ? `${preview.locked_stops} more stops are ready: ` : ''}exact times, ${stay}breakfast, lunch and dinner spots, souvenir shopping, and why we picked each place.`
}

/**
 * A centred popup over the page asking a guest to log in or create a tourist account. Escape,
 * the dismiss button or a click outside close it; logging in comes back to the planner.
 */
function AuthPrompt({ title, message, dismissLabel, onClose }) {
  const loginRef = useRef(null)

  useEffect(() => {
    const onKey = (event) => event.key === 'Escape' && onClose()
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    window.addEventListener('keydown', onKey)
    loginRef.current?.focus()
    return () => {
      document.body.style.overflow = previousOverflow
      window.removeEventListener('keydown', onKey)
    }
  }, [onClose])

  return (
    <div
      className="fixed inset-0 z-100 flex items-center justify-center bg-gray-900/60 p-4 backdrop-blur-[2px]"
      onClick={onClose}
      role="dialog"
      aria-modal="true"
      aria-labelledby="auth-prompt-title"
    >
      <div
        className="w-full max-w-md rounded-2xl bg-white p-6 sm:p-8 text-center shadow-xl animate-[fadeSlideUp_0.25s_ease-out_both]"
        onClick={(event) => event.stopPropagation()}
      >
        <span className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-primary-50 text-primary-600">
          <FiLock size={24} aria-hidden="true" />
        </span>
        <h2 id="auth-prompt-title" className="text-xl font-heading font-bold text-gray-900">{title}</h2>
        <p className="mt-2 text-sm leading-relaxed text-gray-600">{message}</p>
        <div className="mt-6 flex flex-col gap-2.5">
          <Link ref={loginRef} to="/login" state={{ from: '/plan-trip' }} className="btn-primary w-full justify-center">
            <FiLogIn size={16} aria-hidden="true" /> Log in
          </Link>
          <Link to="/register/tourist" className="btn-secondary w-full justify-center">
            <FiUserPlus size={16} aria-hidden="true" /> Create a free tourist account
          </Link>
          <button type="button" onClick={onClose} className="mt-1 py-1.5 text-sm font-semibold text-gray-500 hover:text-primary-700 transition-colors">
            {dismissLabel}
          </button>
        </div>
      </div>
    </div>
  )
}

const PLANNER_STEPS = ['Tell us your trip', 'AI builds a day-by-day plan', 'Adjust it and save']

// Progress copy shown while the request runs; it cycles, it does not reflect server progress.
const GENERATING_MESSAGES = [
  'Reading your preferences…',
  'Matching verified places in your chosen towns…',
  'Arranging stops, meals and stays day by day…',
  'Checking travel times between stops…',
]

function GeneratingState({ claiming }) {
  const [step, setStep] = useState(0)

  useEffect(() => {
    if (claiming) return
    const timer = setInterval(() => setStep((i) => (i + 1) % GENERATING_MESSAGES.length), 2200)
    return () => clearInterval(timer)
  }, [claiming])

  return (
    <div className="py-6" role="status" aria-live="polite">
      <div className="flex items-center gap-3 mb-6">
        <span className="relative flex h-10 w-10 items-center justify-center rounded-full bg-primary-50 text-primary-600">
          <span className="absolute inset-0 rounded-full border-2 border-primary-200 border-t-primary-500 animate-spin" aria-hidden="true" />
          <FiCompass size={17} aria-hidden="true" />
        </span>
        <div>
          <p className="font-heading font-bold text-gray-900">
            {claiming ? 'Unlocking your full itinerary…' : 'Generating your itinerary…'}
          </p>
          {!claiming && <p className="text-sm text-gray-500">{GENERATING_MESSAGES[step]}</p>}
        </div>
      </div>
      <div className="space-y-3" aria-hidden="true">
        {[0, 1, 2].map((i) => (
          <div key={i} className="flex items-center gap-3 p-3 rounded-xl bg-gray-50">
            <div className="skeleton h-14 w-14 shrink-0 rounded-lg" />
            <div className="flex-1 space-y-2">
              <div className="skeleton h-3 w-1/4" />
              <div className="skeleton h-4 w-2/3" />
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}

export default function ItineraryPlanner() {
  usePageTitle('Plan Your Trip')
  const navigate = useNavigate()
  const { user, isAuthenticated, loading: authLoading } = useAuth()
  const isTourist = isAuthenticated && user?.role === 'tourist'
  const [loading, setLoading] = useState(false)
  const [claiming, setClaiming] = useState(false)
  const [generatedItinerary, setGeneratedItinerary] = useState(null)
  // A guest's locked preview; restored from localStorage so it survives a refresh.
  const [guestPreview, setGuestPreview] = useState(() => readPendingItinerary()?.preview || null)
  const [quota, setQuota] = useState(null)
  // Answers a guest saved when asked to log in before generating (restored into the form).
  const [draft] = useState(() => readTripDraft())
  // The login popup: 'generate' (a guest pressed Generate) or 'unlock' (an older guest preview).
  const [authPrompt, setAuthPrompt] = useState(() => (readPendingItinerary() ? 'unlock' : null))
  const claimStarted = useRef(false)
  const draftStarted = useRef(false)
  const limitReached = isTourist && quota?.remaining === 0
  const closePrompt = useCallback(() => setAuthPrompt(null), [])

  useEffect(() => {
    if (authLoading) return
    if (isTourist) getGenerationQuota().then(setQuota).catch(() => {})
  }, [authLoading, isTourist])

  // Guests log in first: their answers are kept, and the itinerary is generated for their account.
  const handleGenerate = useCallback(async (preferences, form) => {
    if (isAuthenticated && !isTourist) {
      toast.error('Only tourist accounts can generate itineraries.')
      return
    }
    if (!isTourist) {
      saveTripDraft({ form, payload: preferences })
      setAuthPrompt('generate')
      return
    }

    setLoading(true)
    try {
      const data = await generateItinerary(preferences)
      if (data.quota) setQuota(data.quota)
      setGeneratedItinerary(data.itinerary)
      toast.success('Itinerary generated successfully!')
    } catch (e) {
      if (e.response?.data?.quota) setQuota(e.response.data.quota)
      showApiError(e, 'Failed to generate itinerary')
    } finally {
      setLoading(false)
    }
  }, [isAuthenticated, isTourist])

  // Back from logging in with saved answers: generate the trip they asked for (once). The
  // answers stay in the form, so after a failure (e.g. a date now past) they can edit and retry.
  useEffect(() => {
    if (authLoading || !isTourist || !draft || draftStarted.current || readPendingItinerary()) return
    draftStarted.current = true
    clearTripDraft()
    handleGenerate(draft.payload)
  }, [authLoading, isTourist, draft, handleGenerate])

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
        showApiError(e, 'Could not open your saved itinerary. Please refresh to try again.')
      })
  }, [authLoading, isTourist, navigate])

  const showGuestPreview = !isAuthenticated && guestPreview !== null
  const stops = generatedItinerary?.items || []
  const previewStops = stops.slice(0, PREVIEW_LIMIT)
  const remaining = stops.length - previewStops.length

  return (
    <div className="bg-gray-50 min-h-screen">
      {/* ==================== HEADER ==================== */}
      <section className="relative overflow-hidden bg-primary-900 text-white">
        <FiCompass className="pointer-events-none absolute -right-16 -top-16 text-white/5" size={320} aria-hidden="true" />
        <div className="relative max-w-6xl mx-auto px-4 sm:px-6 py-12 sm:py-16">
          <p className="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.18em] text-accent-400 mb-3">
            <FiCompass size={14} aria-hidden="true" /> AI Itinerary Planner
          </p>
          <h1 className="text-3xl sm:text-5xl font-heading font-bold">Plan Your Trip</h1>
          <p className="text-white/80 mt-4 max-w-2xl leading-relaxed">
            Enter your travel preferences and we'll generate a personalized itinerary using verified local destinations from your chosen municipalities in Sorsogon.
          </p>
          <ol className="mt-8 grid gap-3 sm:grid-cols-3">
            {PLANNER_STEPS.map((step, i) => (
              <li key={step} className="flex items-center gap-3 rounded-2xl border border-white/15 bg-white/5 px-4 py-3">
                <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-accent-400 text-sm font-bold text-gray-900">
                  {i + 1}
                </span>
                <span className="text-sm font-medium">{step}</span>
              </li>
            ))}
          </ol>
        </div>
      </section>

      <div className="max-w-6xl mx-auto px-4 sm:px-6 py-10 sm:py-12">
        {/* The guided planner gets the wider column; the result stays beside it on large screens. */}
        <div className="grid grid-cols-1 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)] gap-8 items-start">
          {/* ==================== PREFERENCES ==================== */}
          <div className="bg-white rounded-2xl p-6 sm:p-8 border border-gray-200/70 shadow-(--shadow-card)">
            <div className="flex flex-wrap items-center justify-between gap-2 mb-6">
              <div className="flex items-center gap-2.5">
                <span className="flex h-9 w-9 items-center justify-center rounded-full bg-primary-50 text-primary-600">
                  <FiSliders size={17} aria-hidden="true" />
                </span>
                <h2 className="font-heading font-bold text-lg text-gray-900">Trip Planner</h2>
              </div>
              {quota && (
                <span
                  className={`text-xs font-semibold px-2.5 py-1 rounded-full ${
                    limitReached ? 'bg-red-50 text-red-700' : 'bg-gray-100 text-gray-600'
                  }`}
                >
                  {limitReached
                    ? 'Daily limit reached — resets at midnight (PH time)'
                    : `${quota.remaining} of ${quota.limit} itineraries left today`}
                </span>
              )}
            </div>
            <ItineraryPreferenceForm onSubmit={handleGenerate} loading={loading || claiming} limitReached={limitReached} initialForm={draft?.form} />
          </div>

          {/* ==================== RESULT ==================== */}
          <div className="bg-white rounded-2xl p-6 sm:p-8 border border-gray-200/70 shadow-(--shadow-card) lg:sticky lg:top-24">
            <div className="flex items-center justify-between gap-2 mb-6">
              <div className="flex items-center gap-2.5">
                <span className="flex h-9 w-9 items-center justify-center rounded-full bg-secondary-50 text-secondary-600">
                  <FiMap size={17} aria-hidden="true" />
                </span>
                <h2 className="font-heading font-bold text-lg text-gray-900">Your Itinerary</h2>
              </div>
              {(generatedItinerary || showGuestPreview) && !loading && (
                <button
                  onClick={() => {
                    setGeneratedItinerary(null)
                    setGuestPreview(null)
                  }}
                  className="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-500 hover:text-primary-700 transition-colors"
                >
                  <FiRefreshCw size={12} aria-hidden="true" /> Start over
                </button>
              )}
            </div>

            {loading || claiming ? (
              <GeneratingState claiming={claiming} />
            ) : showGuestPreview ? (
              <LockedPreview preview={guestPreview} onUnlock={() => setAuthPrompt('unlock')} />
            ) : generatedItinerary ? (
              <div>
                <h3 className="text-xl font-heading font-bold text-gray-900 mb-3">
                  {generatedItinerary.title}
                </h3>

                <div className="flex flex-wrap gap-2 mb-6">
                  <span className="inline-flex items-center gap-1 text-xs font-medium text-primary-700 bg-primary-50 px-2.5 py-1 rounded-full">
                    <FiCalendar size={11} /> {generatedItinerary.num_days} day{generatedItinerary.num_days > 1 ? 's' : ''}
                  </span>
                  <span className="inline-flex items-center gap-1 text-xs font-medium text-primary-700 bg-primary-50 px-2.5 py-1 rounded-full">
                    <FiActivity size={11} /> {labelFor(TRAVEL_PACES, generatedItinerary.travel_pace)}
                  </span>
                  <span className="inline-flex items-center gap-1 text-xs font-medium text-primary-700 bg-primary-50 px-2.5 py-1 rounded-full">
                    {formatTripCost(generatedItinerary, labelFor(BUDGETS, generatedItinerary.budget))}
                  </span>
                  <span className="inline-flex items-center gap-1 text-xs font-medium text-primary-700 bg-primary-50 px-2.5 py-1 rounded-full">
                    <FiMap size={11} /> {generatedItinerary.total_distance_km} km
                  </span>
                </div>

                <ol className="relative mb-6 space-y-3 border-l-2 border-primary-100 pl-5">
                  {previewStops.map((stop, index) => {
                    const listing = listingFor(stop)
                    const newDay = index === 0 || previewStops[index - 1].day_number !== stop.day_number
                    return (
                      <li key={stop.id ?? index} className="relative">
                        {newDay && (
                          <p className="mb-2 text-xs font-bold uppercase tracking-wider text-secondary-600">
                            <span className="absolute -left-[27px] top-0.5 h-3 w-3 rounded-full border-2 border-white bg-secondary-500" aria-hidden="true" />
                            Day {stop.day_number}
                          </p>
                        )}
                        <div className="flex items-center gap-3 p-3 bg-gray-50 rounded-xl">
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
                              <FiClock size={10} aria-hidden="true" /> {stop.time_slot}
                            </p>
                            <p className="font-semibold text-gray-900 truncate">
                              {listing?.name || 'Listing no longer available'}
                            </p>
                            {stop.reason && <p className="text-xs text-gray-500 line-clamp-2">{stop.reason}</p>}
                          </div>
                        </div>
                      </li>
                    )
                  })}
                </ol>
                {remaining > 0 && (
                  <p className="text-xs text-gray-500 text-center -mt-2 mb-5">
                    +{remaining} more stop{remaining !== 1 ? 's' : ''} in the full itinerary
                  </p>
                )}

                <button
                  onClick={() => navigate(`/itineraries/${generatedItinerary.id}`)}
                  className="btn-primary w-full justify-center"
                >
                  View Full Itinerary <FiArrowRight size={16} />
                </button>
              </div>
            ) : (
              <div className="py-8 text-center">
                <span className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-primary-50 text-primary-600">
                  <FiMap size={24} aria-hidden="true" />
                </span>
                <p className="font-heading text-lg font-bold text-gray-900">Your itinerary will appear here</p>
                <p className="text-sm text-gray-500 mt-1 max-w-sm mx-auto">
                  Fill out your preferences and press generate. You'll get a day-by-day plan you can adjust and save.
                </p>
              </div>
            )}
          </div>
        </div>
      </div>

      {!isAuthenticated && authPrompt === 'generate' && (
        <AuthPrompt
          title="Log in to generate your itinerary"
          message="Your trip answers are saved. Log in or create a free tourist account and we'll build your full day-by-day plan right away, with times, stays, meals and costs."
          dismissLabel="Keep editing my trip"
          onClose={closePrompt}
        />
      )}
      {showGuestPreview && authPrompt === 'unlock' && (
        <AuthPrompt title="Log in to see your full itinerary" message={lockedDetail(guestPreview)} dismissLabel="Maybe later" onClose={closePrompt} />
      )}
    </div>
  )
}
