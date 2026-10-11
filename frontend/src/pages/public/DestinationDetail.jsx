// frontend/src/pages/public/DestinationDetail.jsx
import { useEffect, useState } from 'react'
import { useParams, Link } from 'react-router-dom'
import { FiMapPin, FiClock, FiDollarSign } from 'react-icons/fi'
import { getDestination } from '../../api/destinations'
import { useAuth } from '../../contexts/AuthContext'
import { submitRating } from '../../api/ratings'
import { StarDisplay, StarInput } from '../../components/common/StarRating'
import LocationMap from '../../components/maps/LocationMap'
import ListingCard from '../../components/cards/ListingCard'
import DetailHero from '../../components/common/DetailHero'
import DetailInfoRow from '../../components/common/DetailInfoRow'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import { getImageUrl } from '../../utils/helpers'
import toast from 'react-hot-toast'

export default function DestinationDetail() {
  const { id } = useParams()
  const { isAuthenticated, isTourist } = useAuth()
  const [destination, setDestination] = useState(null)
  const [nearbyAccommodations, setNearbyAccommodations] = useState([])
  const [nearbyFoodBeverages, setNearbyFoodBeverages] = useState([])
  const [nearbyRetailShops, setNearbyRetailShops] = useState([])
  const [loading, setLoading] = useState(true)
  const [rating, setRating] = useState(0)
  const [comment, setComment] = useState('')
  const [submitting, setSubmitting] = useState(false)

  const loadDestination = async () => {
    try {
      const data = await getDestination(id)
      setDestination(data.destination)
      setNearbyAccommodations(data.nearby_accommodations || [])
      setNearbyFoodBeverages(data.nearby_food_beverages || [])
      setNearbyRetailShops(data.nearby_retail_shops || [])
    } catch (e) {
      console.error(e)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    loadDestination()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id])

  const handleSubmitReview = async (e) => {
    e.preventDefault()
    if (!rating) return toast.error('Please select a rating')

    setSubmitting(true)
    try {
      await submitRating({
        rateable_type: 'destination',
        rateable_id: destination.id,
        score: rating,
        comment,
      })
      toast.success('Review submitted successfully')
      setRating(0)
      setComment('')
      loadDestination()
    } catch (e) {
      toast.error(e.response?.data?.message || 'Failed to submit review')
    } finally {
      setSubmitting(false)
    }
  }

  if (loading) return <LoadingSpinner size="lg" />
  if (!destination) return <div className="p-6 sm:p-8 text-center">Destination not found</div>

  const imageUrl = getImageUrl(destination.images?.[0])

  return (
    <div className="bg-gray-50 min-h-screen">
      <DetailHero
        backTo="/destinations"
        backLabel="Back to Destinations"
        badge={destination.category?.name}
        title={destination.name}
        subtitle={destination.address}
        rating={destination.average_rating}
        reviewCount={destination.total_reviews}
        imageUrl={imageUrl}
        imageAlt={destination.name}
        latitude={destination.latitude}
        longitude={destination.longitude}
        website={destination.website}
      />

      <div className="max-w-6xl mx-auto px-4 sm:px-6 pb-16 space-y-8">
        <div>
          <h2 className="text-2xl font-heading font-semibold text-gray-900 mb-4">About this destination</h2>
          <p className="text-gray-600 leading-relaxed mb-6">{destination.description}</p>
          <div className="border-t border-gray-100 pt-4">
            <DetailInfoRow icon={FiMapPin} label="Municipality">{destination.municipality?.name}</DetailInfoRow>
            <DetailInfoRow icon={FiClock} label="Operating Hours">{destination.operating_hours || 'Not specified'}</DetailInfoRow>
            <DetailInfoRow icon={FiDollarSign} label="Entrance Fee">
              {destination.entrance_fee ? `PHP ${destination.entrance_fee}` : 'Free'}
            </DetailInfoRow>
          </div>
          {isAuthenticated && isTourist && (
            <Link to="/plan-trip" className="btn-primary mt-6">
              Add to Itinerary
            </Link>
          )}
        </div>

        <div className="card p-6 sm:p-8">
          <h2 className="text-2xl font-heading font-semibold text-gray-900 mb-4">Location</h2>
          <LocationMap
            latitude={destination.latitude}
            longitude={destination.longitude}
            title={destination.name}
          />
        </div>

        {/* Reviews */}
        <div className="card p-6 sm:p-8">
          <h2 className="text-2xl font-heading font-semibold text-gray-900 mb-6">Ratings and Reviews</h2>

          {isAuthenticated && isTourist && (
            <form onSubmit={handleSubmitReview} className="mb-8 pb-8 border-b border-gray-100">
              <div className="mb-4">
                <label className="block text-sm font-medium text-gray-700 mb-2">Your Rating</label>
                <StarInput value={rating} onChange={setRating} />
              </div>
              <textarea
                value={comment}
                onChange={(e) => setComment(e.target.value)}
                placeholder="Share your experience..."
                rows={4}
                className="input-field mb-4"
              />
              <button type="submit" className="btn-primary" disabled={submitting}>
                {submitting ? 'Submitting...' : 'Submit Review'}
              </button>
            </form>
          )}

          <div className="space-y-5">
            {destination.ratings?.length === 0 ? (
              <p className="text-gray-500">No reviews yet.</p>
            ) : (
              destination.ratings?.map((review) => (
                <div key={review.id} className="flex gap-3 border-b border-gray-100 pb-5 last:border-b-0 last:pb-0">
                  <div className="w-9 h-9 rounded-full bg-primary-100 text-primary-700 flex items-center justify-center text-xs font-semibold shrink-0">
                    {initials(review.user?.name)}
                  </div>
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center justify-between gap-2 mb-1">
                      <p className="font-medium text-gray-900">{review.user?.name}</p>
                      <StarDisplay rating={review.score} size={14} />
                    </div>
                    {review.comment && <p className="text-sm text-gray-600">{review.comment}</p>}
                  </div>
                </div>
              ))
            )}
          </div>
        </div>

        {/* Activities */}
        {destination.activities?.length > 0 && (
          <section>
            <h2 className="text-2xl font-heading font-bold text-gray-900 mb-6">Activities</h2>
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
              {destination.activities.map((a) => (
                <Link key={a.id} to={`/activities/${a.id}`} className="card group block">
                  <div className="aspect-4/3 overflow-hidden">
                    <img
                      src={getImageUrl(a.image_path)}
                      alt={a.name}
                      className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
                      loading="lazy"
                    />
                  </div>
                  <div className="p-4">
                    <div className="flex items-center justify-between gap-2 mb-2">
                      {a.type && (
                        <span className="text-xs font-medium text-primary-600 bg-primary-50 px-2 py-0.5 rounded capitalize">
                          {a.type}
                        </span>
                      )}
                      <StarDisplay rating={a.average_rating} size={14} />
                    </div>
                    <h3 className="font-heading font-semibold text-gray-900 mb-1 group-hover:text-primary-600 transition-colors">
                      {a.name}
                    </h3>
                    {a.description && (
                      <p className="text-sm text-gray-500 line-clamp-2">
                        {a.description.substring(0, 100)}...
                      </p>
                    )}
                  </div>
                </Link>
              ))}
            </div>
          </section>
        )}

        {/* Accommodations */}
        {nearbyAccommodations.length > 0 && (
          <section>
            <h2 className="text-2xl font-heading font-bold text-gray-900 mb-6">Accommodations</h2>
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
              {nearbyAccommodations.map((item) => (
                <ListingCard key={item.id} item={item} basePath="/accommodations" typeLabel={item.type} />
              ))}
            </div>
          </section>
        )}

        {/* Food and Beverage */}
        {nearbyFoodBeverages.length > 0 && (
          <section>
            <h2 className="text-2xl font-heading font-bold text-gray-900 mb-6">Food and Beverage</h2>
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
              {nearbyFoodBeverages.map((item) => (
                <ListingCard key={item.id} item={item} basePath="/food-beverage" typeLabel={item.cuisine_type} />
              ))}
            </div>
          </section>
        )}

        {/* Souvenirs */}
        {nearbyRetailShops.length > 0 && (
          <section>
            <h2 className="text-2xl font-heading font-bold text-gray-900 mb-6">Souvenir Shops</h2>
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
              {nearbyRetailShops.map((item) => (
                <ListingCard key={item.id} item={item} basePath="/shops" typeLabel="Pasalubong Shop" />
              ))}
            </div>
          </section>
        )}
      </div>
    </div>
  )
}

function initials(name) {
  if (!name) return '?'
  return name.trim().split(/\s+/).slice(0, 2).map((w) => w[0].toUpperCase()).join('')
}
