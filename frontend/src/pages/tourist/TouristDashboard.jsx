import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  FiArrowRight, FiCalendar, FiChevronRight, FiCompass, FiDownload, FiMap, FiMapPin, FiStar, FiUsers,
} from 'react-icons/fi'
import { getItineraries, getItinerary } from '../../api/itineraries'
import { getMyReviews } from '../../api/ratings'
import { getDestinations } from '../../api/destinations'
import { useAuth } from '../../contexts/AuthContext'
import { StarDisplay } from '../../components/common/StarRating'
import { getImageUrl, formatTripCost, formatShortDate } from '../../utils/helpers'
import { placeFor } from '../../utils/itineraryStops'
import { listPdfCopies } from '../../utils/downloadedItineraries'
import LoadingSpinner from '../../components/common/LoadingSpinner'

const HERO_IMAGE = '/images/destinations-hero.jpg'
const CARD = 'bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card)'

// Timeline dot and badge colours by what the stop is: a visit, a meal, the stay or shopping.
const STOP_COLORS = {
  destination: { dot: 'bg-primary-500', badge: 'bg-primary-50 text-primary-700' },
  food_beverage: { dot: 'bg-orange-500', badge: 'bg-orange-50 text-orange-700' },
  accommodation: { dot: 'bg-violet-500', badge: 'bg-violet-50 text-violet-700' },
  retail_shop: { dot: 'bg-pink-500', badge: 'bg-pink-50 text-pink-700' },
}

function greeting() {
  const hour = new Date().getHours()
  return hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening'
}

/** "Nov 16, 2026 – Nov 18, 2026", or the one day, from the itinerary's dates. */
function tripDates(itinerary) {
  if (!itinerary?.start_date) return null
  const start = formatShortDate(itinerary.start_date)
  const end = itinerary.end_date && itinerary.end_date !== itinerary.start_date ? formatShortDate(itinerary.end_date) : null
  return end ? `${start} – ${end}` : start
}

/** "Starts in 3 days", "Happening now" or "Completed", from the trip's dates; null without dates. */
function tripStatus(itinerary) {
  if (!itinerary?.start_date) return null
  const day = (value) => {
    const date = new Date(value)
    return new Date(date.getFullYear(), date.getMonth(), date.getDate())
  }
  const today = day(new Date())
  const start = day(itinerary.start_date)
  const end = day(itinerary.end_date || itinerary.start_date)
  const days = Math.round((start - today) / 86400000)
  if (days > 1) return { label: `Starts in ${days} days`, tone: 'bg-accent-400 text-gray-900' }
  if (days === 1) return { label: 'Starts tomorrow', tone: 'bg-accent-400 text-gray-900' }
  if (today <= end) return { label: 'Happening now', tone: 'bg-secondary-500 text-white' }
  return { label: 'Completed', tone: 'bg-white/90 text-gray-700' }
}

/** The trip's first destination photo, for its cover. */
function coverOf(itinerary) {
  const visit = itinerary?.items?.find((item) => item.item_type === 'destination' && item.destination?.images?.length)
  return visit ? getImageUrl(visit.destination.images[0]) : HERO_IMAGE
}

function SectionTitle({ children, to, linkLabel = 'View all' }) {
  return (
    <div className="mb-4 flex items-center justify-between gap-3">
      <h2 className="font-heading text-lg font-bold text-gray-900">{children}</h2>
      {to && (
        <Link to={to} className="inline-flex items-center gap-0.5 text-sm font-medium text-primary-600 hover:text-primary-700">
          {linkLabel} <FiChevronRight size={15} aria-hidden="true" />
        </Link>
      )}
    </div>
  )
}

export default function TouristDashboard() {
  // Account Health and Logout are in the navbar's account menu.
  const { user } = useAuth()

  const [itineraries, setItineraries] = useState([])
  const [itineraryTotal, setItineraryTotal] = useState(0)
  const [reviews, setReviews] = useState([])
  const [reviewTotal, setReviewTotal] = useState(0)
  const [downloads, setDownloads] = useState(0)
  const [latestItinerary, setLatestItinerary] = useState(null)
  const [discover, setDiscover] = useState([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    // Each part loads on its own, so one failing leaves the rest of the dashboard working.
    Promise.all([
      getItineraries().catch(() => null),
      getMyReviews().catch(() => null),
      getDestinations({ per_page: 4, sort: 'featured' }).catch(() => null),
      listPdfCopies(user.id).catch(() => []),
    ])
      .then(([itineraryData, reviewData, destData, pdfs]) => {
        const list = itineraryData?.data || []
        setItineraries(list)
        setItineraryTotal(itineraryData?.total ?? list.length)
        setReviews(reviewData?.data || [])
        setReviewTotal(reviewData?.total ?? reviewData?.data?.length ?? 0)
        setDiscover(destData?.data || [])
        setDownloads(pdfs.length)
        if (list.length > 0) {
          getItinerary(list[0].id).then(setLatestItinerary).catch(() => {})
        }
      })
      .finally(() => setLoading(false))
  }, [user.id])

  if (loading) return <LoadingSpinner size="lg" />

  const today = new Date().toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })
  const firstName = user?.name?.split(' ')[0] || 'traveler'

  // Ordered by the planned sequence, not the time text ("1:30 PM" would sort before "8:30 AM").
  const day1Stops = latestItinerary?.items
    ?.filter((item) => item.day_number === 1)
    ?.sort((a, b) => a.sequence - b.sequence) || []
  const status = tripStatus(latestItinerary)
  const towns = (latestItinerary?.municipalities || []).map((town) => town.name).filter(Boolean)

  const stats = [
    { icon: FiMap, value: itineraryTotal, label: itineraryTotal === 1 ? 'Trip planned' : 'Trips planned', to: '/my-itineraries' },
    { icon: FiStar, value: reviewTotal, label: reviewTotal === 1 ? 'Review written' : 'Reviews written', to: '/my-reviews' },
    { icon: FiDownload, value: downloads, label: 'Offline PDFs', to: '/my-downloads' },
  ]

  return (
    <div className="min-h-screen bg-gray-50">
      <div className="mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6 sm:py-8">
        {/* Welcome */}
        <section className="relative overflow-hidden rounded-3xl bg-primary-900 text-white shadow-(--shadow-card)">
          <img src={HERO_IMAGE} alt="" className="absolute inset-0 h-full w-full object-cover" />
          <div className="absolute inset-0 bg-linear-to-r from-primary-900/95 via-primary-900/75 to-primary-900/30" aria-hidden="true" />
          <div className="relative px-5 py-7 sm:px-8 sm:py-10">
            <p className="text-sm text-primary-100">{today}</p>
            <h1 className="mt-1 font-heading text-2xl font-bold leading-tight sm:text-4xl">
              {greeting()}, {firstName}!
            </h1>
            <p className="mt-2 max-w-xl text-sm text-primary-50 sm:text-base">
              Ready for your next Sorsogon adventure? Plan a trip, revisit your itineraries or discover new places.
            </p>
            <div className="mt-5 flex flex-wrap gap-2.5">
              <Link to="/plan-trip" className="inline-flex min-h-11 items-center gap-2 rounded-xl bg-accent-400 px-5 text-sm font-semibold text-gray-900 hover:bg-accent-500">
                <FiCompass size={16} aria-hidden="true" /> Plan a new trip
              </Link>
              <Link to="/destinations" className="inline-flex min-h-11 items-center gap-2 rounded-xl border border-white/40 bg-white/10 px-5 text-sm font-semibold text-white backdrop-blur-sm hover:bg-white/20">
                <FiMapPin size={16} aria-hidden="true" /> Explore destinations
              </Link>
            </div>

            <ul className="mt-7 grid grid-cols-3 gap-2 sm:max-w-xl sm:gap-3">
              {stats.map(({ icon: Icon, value, label, to }) => (
                <li key={label}>
                  <Link to={to} className="block rounded-2xl border border-white/20 bg-white/10 px-3 py-3 backdrop-blur-sm transition-colors hover:bg-white/20 sm:px-4">
                    <Icon size={16} className="text-accent-400" aria-hidden="true" />
                    <p className="mt-1.5 font-heading text-2xl font-bold leading-none tabular-nums">{value}</p>
                    <p className="mt-1 text-xs leading-snug text-primary-50">{label}</p>
                  </Link>
                </li>
              ))}
            </ul>
          </div>
        </section>

        {/* Latest trip and its first day */}
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
          <section className={`${CARD} flex flex-col overflow-hidden lg:col-span-2`}>
            {latestItinerary ? (
              <>
                <div className="relative h-44 sm:h-56 lg:h-auto lg:min-h-56 lg:flex-1">
                  <img src={coverOf(latestItinerary)} alt="" className="absolute inset-0 h-full w-full object-cover" />
                  <div className="absolute inset-0 bg-linear-to-t from-black/75 via-black/20 to-transparent" aria-hidden="true" />
                  {status && (
                    <span className={`absolute left-4 top-4 rounded-full px-3 py-1 text-xs font-semibold ${status.tone}`}>{status.label}</span>
                  )}
                  <div className="absolute inset-x-0 bottom-0 p-4 sm:p-6">
                    <p className="text-xs font-semibold uppercase tracking-wider text-accent-400">Your latest trip</p>
                    <h2 className="mt-1 font-heading text-xl font-bold leading-tight text-white sm:text-2xl">{latestItinerary.title}</h2>
                  </div>
                </div>
                <div className="p-4 sm:p-6">
                  <dl className="grid grid-cols-2 gap-x-4 gap-y-4 sm:grid-cols-4">
                    <div>
                      <dt className="flex items-center gap-1.5 text-xs text-gray-500"><FiCalendar size={13} aria-hidden="true" /> Dates</dt>
                      <dd className="mt-1 text-sm font-semibold text-gray-900">{tripDates(latestItinerary) || 'Not set'}</dd>
                    </div>
                    <div>
                      <dt className="flex items-center gap-1.5 text-xs text-gray-500"><FiMap size={13} aria-hidden="true" /> Length</dt>
                      <dd className="mt-1 text-sm font-semibold text-gray-900">
                        {latestItinerary.num_days} day{latestItinerary.num_days === 1 ? '' : 's'} · <span className="capitalize">{latestItinerary.travel_pace}</span>
                      </dd>
                    </div>
                    <div>
                      <dt className="flex items-center gap-1.5 text-xs text-gray-500"><FiUsers size={13} aria-hidden="true" /> Travelers</dt>
                      <dd className="mt-1 text-sm font-semibold text-gray-900">{latestItinerary.travelers || 1}</dd>
                    </div>
                    <div>
                      <dt className="text-xs text-gray-500">Est. cost</dt>
                      <dd className="mt-1 text-sm font-semibold text-gray-900">{formatTripCost(latestItinerary, latestItinerary.budget)}</dd>
                    </div>
                  </dl>
                  {towns.length > 0 && (
                    <div className="mt-4 flex flex-wrap gap-1.5">
                      {towns.map((town) => (
                        <span key={town} className="inline-flex items-center gap-1 rounded-full bg-primary-50 px-2.5 py-1 text-xs font-medium text-primary-700">
                          <FiMapPin size={11} aria-hidden="true" /> {town}
                        </span>
                      ))}
                    </div>
                  )}
                  <div className="mt-5 flex flex-wrap gap-2.5 border-t border-gray-100 pt-4">
                    <Link to={`/itineraries/${latestItinerary.id}`} className="btn-primary min-h-11">
                      View full plan <FiArrowRight size={15} aria-hidden="true" />
                    </Link>
                    <Link to="/my-itineraries" className="inline-flex min-h-11 items-center rounded-lg border border-primary-200 bg-white px-4 text-sm font-medium text-primary-700 hover:bg-primary-50">
                      All my trips
                    </Link>
                  </div>
                </div>
              </>
            ) : (
              <div className="flex h-full flex-col items-center justify-center px-6 py-14 text-center">
                <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-primary-50 text-primary-600">
                  <FiMap size={24} aria-hidden="true" />
                </span>
                <h2 className="mt-4 font-heading text-xl font-bold text-gray-900">No trips planned yet</h2>
                <p className="mt-1 max-w-sm text-sm text-gray-500">Tell us your dates and interests, and we'll plan your Sorsogon trip day by day.</p>
                <Link to="/plan-trip" className="btn-primary mt-5 min-h-11">
                  <FiCompass size={15} aria-hidden="true" /> Plan your first trip
                </Link>
              </div>
            )}
          </section>

          <section className={`${CARD} p-4 sm:p-6`}>
            <SectionTitle to={latestItinerary ? `/itineraries/${latestItinerary.id}` : undefined} linkLabel="Full plan">
              Day 1 at a glance
            </SectionTitle>
            {day1Stops.length === 0 ? (
              <p className="text-sm text-gray-500">Your first day's stops will show here once you plan a trip.</p>
            ) : (
              <ol className="relative">
                {day1Stops.map((stop, i) => {
                  const { listing, badge } = placeFor(stop)
                  const colors = STOP_COLORS[stop.item_type] || STOP_COLORS.destination
                  return (
                    <li key={stop.id ?? i} className="relative flex gap-3 pb-4 last:pb-0">
                      {i < day1Stops.length - 1 && <span className="absolute left-[5px] top-4 bottom-0 w-px bg-gray-200" aria-hidden="true" />}
                      <span className={`relative mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full ring-4 ring-white ${colors.dot}`} aria-hidden="true" />
                      <div className="min-w-0">
                        <p className="text-xs font-semibold text-primary-700">{stop.time_slot}</p>
                        <p className="text-sm font-medium leading-snug text-gray-900">{listing?.name || 'Place no longer available'}</p>
                        <span className={`mt-1 inline-block rounded-full px-2 py-0.5 text-[0.7rem] font-medium ${colors.badge}`}>{badge}</span>
                      </div>
                    </li>
                  )
                })}
              </ol>
            )}
          </section>
        </div>

        {/* Trips, reviews and shortcuts */}
        <div className="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
          <section className={`${CARD} p-4 sm:p-6`}>
            <SectionTitle to={itineraries.length ? '/my-itineraries' : undefined}>My itineraries</SectionTitle>
            {itineraries.length === 0 ? (
              <p className="text-sm text-gray-500">Trips you plan are kept here.</p>
            ) : (
              <ul className="-mx-2 divide-y divide-gray-100">
                {itineraries.slice(0, 4).map((item) => (
                  <li key={item.id}>
                    <Link to={`/itineraries/${item.id}`} className="flex items-center gap-3 rounded-xl px-2 py-2.5 transition-colors hover:bg-gray-50">
                      <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-600">
                        <FiMap size={17} aria-hidden="true" />
                      </span>
                      <span className="min-w-0 flex-1">
                        <span className="block truncate text-sm font-medium text-gray-900">{item.title}</span>
                        <span className="block text-xs text-gray-500">
                          {item.num_days} day{item.num_days === 1 ? '' : 's'}
                          {item.start_date ? ` · ${formatShortDate(item.start_date)}` : ''}
                        </span>
                      </span>
                      <FiChevronRight size={16} className="shrink-0 text-gray-300" aria-hidden="true" />
                    </Link>
                  </li>
                ))}
              </ul>
            )}
          </section>

          <section className={`${CARD} p-4 sm:p-6`}>
            <SectionTitle to={reviews.length ? '/my-reviews' : undefined}>My reviews</SectionTitle>
            {reviews.length === 0 ? (
              <div className="rounded-xl bg-gray-50 px-4 py-6 text-center">
                <FiStar size={22} className="mx-auto text-accent-500" aria-hidden="true" />
                <p className="mt-2 text-sm font-medium text-gray-800">No reviews yet</p>
                <p className="mt-0.5 text-xs text-gray-500">Visited a place? Your review helps other travelers.</p>
                <Link to="/destinations" className="mt-3 inline-flex items-center gap-1 text-sm font-medium text-primary-600 hover:text-primary-700">
                  Find a place to review <FiChevronRight size={15} aria-hidden="true" />
                </Link>
              </div>
            ) : (
              <ul className="-mx-2 divide-y divide-gray-100">
                {reviews.slice(0, 4).map((review) => (
                  <li key={review.id} className="flex items-center justify-between gap-3 px-2 py-2.5">
                    <span className="min-w-0">
                      <span className="block truncate text-sm font-medium text-gray-900">{review.rateable?.name || 'Listing no longer available'}</span>
                      <span className="block text-xs text-gray-500">{formatShortDate(review.created_at)}</span>
                    </span>
                    <StarDisplay rating={review.score} size={12} />
                  </li>
                ))}
              </ul>
            )}
          </section>

          <section className={`${CARD} p-4 sm:p-6 md:col-span-2 lg:col-span-1`}>
            <SectionTitle>Quick links</SectionTitle>
            <ul className="grid grid-cols-2 gap-2.5">
              {[
                { to: '/plan-trip', icon: FiCompass, label: 'Plan a trip', tone: 'bg-primary-50 text-primary-600' },
                { to: '/my-downloads', icon: FiDownload, label: 'Offline PDFs', tone: 'bg-secondary-50 text-secondary-600' },
                { to: '/destinations', icon: FiMapPin, label: 'Destinations', tone: 'bg-orange-50 text-orange-600' },
                { to: '/events', icon: FiCalendar, label: 'Events', tone: 'bg-violet-50 text-violet-600' },
              ].map(({ to, icon: Icon, label, tone }) => (
                <li key={to}>
                  <Link to={to} className="flex h-full flex-col items-start gap-2 rounded-xl border border-gray-100 p-3 transition-colors hover:border-primary-200 hover:bg-primary-50/40">
                    <span className={`flex h-9 w-9 items-center justify-center rounded-lg ${tone}`}>
                      <Icon size={17} aria-hidden="true" />
                    </span>
                    <span className="text-sm font-medium text-gray-800">{label}</span>
                  </Link>
                </li>
              ))}
            </ul>
          </section>
        </div>

        {/* Discover */}
        {discover.length > 0 && (
          <section>
            <SectionTitle to="/destinations" linkLabel="See all">Discover Sorsogon</SectionTitle>
            <ul className="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
              {discover.map((dest) => (
                <li key={dest.id}>
                  <Link to={`/destinations/${dest.id}`} className="group relative block aspect-4/5 overflow-hidden rounded-2xl bg-primary-900 shadow-(--shadow-card) sm:aspect-4/3">
                    <img
                      src={getImageUrl(dest.images?.[0])}
                      alt={dest.name}
                      loading="lazy"
                      className="absolute inset-0 h-full w-full object-cover transition-transform duration-500 group-hover:scale-105"
                    />
                    <span className="absolute inset-0 bg-linear-to-t from-black/75 via-black/10 to-transparent" aria-hidden="true" />
                    <span className="absolute inset-x-0 bottom-0 p-3 sm:p-4">
                      {dest.category?.name && (
                        <span className="mb-1 inline-block rounded-full bg-white/90 px-2 py-0.5 text-[0.7rem] font-semibold text-primary-800">{dest.category.name}</span>
                      )}
                      <span className="block font-heading text-sm font-semibold leading-snug text-white sm:text-base">{dest.name}</span>
                      {dest.municipality?.name && (
                        <span className="mt-0.5 flex items-center gap-1 text-xs text-white/80">
                          <FiMapPin size={11} aria-hidden="true" /> {dest.municipality.name}
                        </span>
                      )}
                    </span>
                  </Link>
                </li>
              ))}
            </ul>
          </section>
        )}
      </div>
    </div>
  )
}
