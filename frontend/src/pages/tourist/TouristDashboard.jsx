import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { FiMap, FiStar, FiCompass, FiClock, FiMapPin } from 'react-icons/fi'
import { getItineraries, getItinerary } from '../../api/itineraries'
import { getMyReviews } from '../../api/ratings'
import { getDestinations } from '../../api/destinations'
import { useAuth } from '../../contexts/AuthContext'
import { StarDisplay } from '../../components/common/StarRating'
import { getImageUrl, formatTripCost } from '../../utils/helpers'
import { placeFor } from '../../utils/itineraryStops'
import LoadingSpinner from '../../components/common/LoadingSpinner'

export default function TouristDashboard() {
  // Account Health and Logout are in the navbar's account menu.
  const { user } = useAuth()

  const [itineraries, setItineraries] = useState([])
  const [reviews, setReviews] = useState([])
  const [latestItinerary, setLatestItinerary] = useState(null)
  const [discover, setDiscover] = useState([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    Promise.all([getItineraries(), getMyReviews(), getDestinations({ per_page: 3 })])
      .then(([itineraryData, reviewData, destData]) => {
        const list = itineraryData.data || []
        setItineraries(list)
        setReviews(reviewData.data || [])
        setDiscover(destData.data || [])
        if (list.length > 0) {
          getItinerary(list[0].id).then(setLatestItinerary)
        }
      })
      .finally(() => setLoading(false))
  }, [])

  if (loading) return <LoadingSpinner size="lg" />

  const today = new Date().toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })

  // Ordered by the planned sequence, not the time text ("1:30 PM" would sort before "8:30 AM").
  const day1Stops = latestItinerary?.items
    ?.filter((item) => item.day_number === 1)
    ?.sort((a, b) => a.sequence - b.sequence) || []

  return (
    <div className="bg-white min-h-screen">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 py-8">
        {/* Top bar */}
        <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 mb-8">
          <div>
            <p className="text-gray-900 font-heading font-bold text-lg">Sorsogon Tourist Dashboard</p>
            <p className="text-gray-500 text-xs mt-0.5">{today}</p>
          </div>
        </div>

        <p className="text-gray-500 mb-6">Welcome, {user?.name}!</p>

        <div className="grid grid-cols-1 lg:grid-cols-3 gap-5 mb-5">
          {/* Latest itinerary hero card */}
          <div className="lg:col-span-2 bg-white border border-gray-100 shadow-sm rounded-2xl p-4 sm:p-6">
            {latestItinerary ? (
              <>
                <div className="flex items-center justify-between mb-4">
                  <p className="text-xs font-medium text-gray-500 uppercase tracking-wide">Latest Itinerary</p>
                  <Link to={`/itineraries/${latestItinerary.id}`} className="text-primary-600 text-sm hover:text-primary-700">
                    View full plan
                  </Link>
                </div>
                <h2 className="text-2xl font-heading font-bold text-gray-900 mb-4">{latestItinerary.title}</h2>
                <div className="grid grid-cols-3 gap-4">
                  <div>
                    <p className="text-xs text-gray-500 mb-1">Duration</p>
                    <p className="text-lg font-heading font-semibold text-gray-900">{latestItinerary.num_days} day(s)</p>
                  </div>
                  <div>
                    <p className="text-xs text-gray-500 mb-1">Pace</p>
                    <p className="text-lg font-heading font-semibold text-gray-900 capitalize">{latestItinerary.travel_pace}</p>
                  </div>
                  <div>
                    <p className="text-xs text-gray-500 mb-1">Est. cost</p>
                    <p className="text-sm font-heading font-semibold text-gray-900">{formatTripCost(latestItinerary, latestItinerary.budget)}</p>
                  </div>
                </div>
              </>
            ) : (
              <div className="h-full flex flex-col items-center justify-center text-center py-8">
                <FiMap className="text-gray-300 mb-3" size={28} />
                <p className="text-gray-700 font-medium mb-1">No itinerary yet</p>
                <p className="text-gray-400 text-sm mb-4">Plan your first Sorsogon trip with the AI planner.</p>
                <Link to="/plan-trip" className="text-primary-600 text-sm font-medium hover:text-primary-700">Plan Your Trip &rarr;</Link>
              </div>
            )}
          </div>

          {/* Stats */}
          <div className="flex flex-col gap-5">
            <div className="flex-1 bg-white border border-gray-100 shadow-sm rounded-2xl p-5 flex items-center gap-4">
              <div className="w-10 h-10 rounded-lg bg-primary-50 text-primary-600 flex items-center justify-center shrink-0">
                <FiMap size={18} />
              </div>
              <div>
                <p className="text-2xl font-heading font-bold text-gray-900 leading-none">{itineraries.length}</p>
                <p className="text-gray-500 text-xs mt-1">Saved Itineraries</p>
              </div>
            </div>
            <div className="flex-1 bg-white border border-gray-100 shadow-sm rounded-2xl p-5 flex items-center gap-4">
              <div className="w-10 h-10 rounded-lg bg-secondary-50 text-secondary-600 flex items-center justify-center shrink-0">
                <FiStar size={18} />
              </div>
              <div>
                <p className="text-2xl font-heading font-bold text-gray-900 leading-none">{reviews.length}</p>
                <p className="text-gray-500 text-xs mt-1">Reviews Submitted</p>
              </div>
            </div>
          </div>
        </div>

        <div className="grid grid-cols-1 lg:grid-cols-3 gap-5 mb-5">
          {/* Day 1 timeline */}
          <div className="bg-white border border-gray-100 shadow-sm rounded-2xl p-4 sm:p-6">
            <p className="text-xs font-medium text-gray-500 uppercase tracking-wide mb-4">
              {latestItinerary ? 'Day 1 Timeline' : 'Timeline'}
            </p>
            {day1Stops.length === 0 ? (
              <p className="text-gray-400 text-sm">No stops to show yet.</p>
            ) : (
              <div className="space-y-4">
                {day1Stops.map((stop, i) => (
                  <div key={i} className="flex gap-3">
                    <div className="flex flex-col items-center">
                      <div className="w-2 h-2 rounded-full bg-primary-500 mt-1.5" />
                      {i < day1Stops.length - 1 && <div className="w-px flex-1 bg-gray-200 mt-1" />}
                    </div>
                    <div className="pb-4">
                      <p className="text-primary-600 text-xs font-medium">{stop.time_slot}</p>
                      <p className="text-gray-900 text-sm font-medium mt-0.5">
                        {placeFor(stop).listing?.name || 'Place no longer available'}
                      </p>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>

          {/* My Itineraries */}
          <div className="bg-white border border-gray-100 shadow-sm rounded-2xl p-4 sm:p-6">
            <div className="flex items-center justify-between mb-4">
              <p className="text-xs font-medium text-gray-500 uppercase tracking-wide">My Itineraries</p>
              <Link to="/my-itineraries" className="text-primary-600 text-xs hover:text-primary-700">View all</Link>
            </div>
            {itineraries.length === 0 ? (
              <p className="text-gray-400 text-sm">No itineraries yet.</p>
            ) : (
              <div className="space-y-2">
                {itineraries.slice(0, 4).map((item) => (
                  <Link key={item.id} to={`/itineraries/${item.id}`} className="flex items-center gap-2 p-2.5 rounded-lg hover:bg-gray-50 transition-colors duration-200">
                    <FiClock className="text-gray-400 shrink-0" size={14} />
                    <span className="text-sm text-gray-700 truncate">{item.title}</span>
                  </Link>
                ))}
              </div>
            )}
          </div>

          {/* My Reviews */}
          <div className="bg-white border border-gray-100 shadow-sm rounded-2xl p-4 sm:p-6">
            <div className="flex items-center justify-between mb-4">
              <p className="text-xs font-medium text-gray-500 uppercase tracking-wide">My Reviews</p>
              <Link to="/my-reviews" className="text-primary-600 text-xs hover:text-primary-700">View all</Link>
            </div>
            {reviews.length === 0 ? (
              <p className="text-gray-400 text-sm">No reviews yet.</p>
            ) : (
              <div className="space-y-2">
                {reviews.slice(0, 4).map((review) => (
                  <div key={review.id} className="flex items-center justify-between p-2.5 rounded-lg">
                    <span className="text-sm text-gray-700 truncate">{review.rateable?.name || 'Item'}</span>
                    <StarDisplay rating={review.score} size={11} />
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>

        {/* Discover */}
        <div className="bg-white border border-gray-100 shadow-sm rounded-2xl p-4 sm:p-6">
          <div className="flex items-center justify-between mb-4">
            <p className="text-xs font-medium text-gray-500 uppercase tracking-wide">Discover Sorsogon</p>
            <Link to="/destinations" className="text-primary-600 text-xs hover:text-primary-700">See all</Link>
          </div>
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
            {discover.map((dest) => (
              <Link key={dest.id} to={`/destinations/${dest.id}`} className="group">
                <div className="aspect-4/3 rounded-xl overflow-hidden mb-2">
                  <img
                    src={getImageUrl(dest.images?.[0])}
                    alt={dest.name}
                    className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
                  />
                </div>
                <p className="text-sm font-medium text-gray-800 truncate">{dest.name}</p>
                <div className="flex items-center gap-1 text-gray-400 text-xs mt-0.5">
                  <FiMapPin size={11} />
                  {dest.municipality?.name}
                </div>
              </Link>
            ))}
          </div>
        </div>

        <div className="text-center mt-6">
          <Link to="/plan-trip" className="inline-flex items-center gap-2 text-primary-600 hover:text-primary-700 text-sm font-medium">
            <FiCompass size={14} /> Plan a new trip
          </Link>
        </div>
      </div>
    </div>
  )
}