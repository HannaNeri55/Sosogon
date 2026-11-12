import usePageTitle from '../../hooks/usePageTitle'
import { useEffect, useMemo, useState } from 'react'
import { useParams } from 'react-router-dom'
import { FiMapPin, FiPhone, FiClock } from 'react-icons/fi'
import { getFoodBeverage } from '../../api/foodBeverages'
import LocationMap from '../../components/maps/LocationMap'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import ReviewSection from '../../components/common/ReviewSection'
import GuestReviewCarousel from '../../components/common/GuestReviewCarousel'
import NearbyPlaces from '../../components/common/NearbyPlaces'
import DetailHero from '../../components/common/DetailHero'
import DetailLayout from '../../components/detail/DetailLayout'
import DetailSection from '../../components/detail/DetailSection'
import FactsCard from '../../components/detail/FactsCard'
import DiningInfo from '../../components/detail/DiningInfo'
import { getImageUrl } from '../../utils/helpers'
import { approxHours, approxRange, estimateOf } from '../../utils/estimates'
import LoadError from '../../components/common/LoadError'

// Cover photo first, then the owner's order.
const sortGallery = (images = []) =>
  [...images].sort(
    (a, b) =>
      Number(Boolean(b.is_primary)) - Number(Boolean(a.is_primary)) ||
      (a.sort_order ?? 0) - (b.sort_order ?? 0) ||
      a.id - b.id
  )

export default function FoodBeverageDetail() {
  const { id } = useParams()

  const [item, setItem] = useState(null)
  usePageTitle(item?.name)
  const [loading, setLoading] = useState(true)
  // 'not_found', or 'failed' when the request failed for another reason (offline, server error).
  const [error, setError] = useState(null)

  const load = async () => {
    try {
      const data = await getFoodBeverage(id)
      setItem(data)
      setError(null)
    } catch (e) {
      setItem(null)
      setError(e.response?.status === 404 ? 'not_found' : 'failed')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setLoading(true)
    load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id])

  const retry = () => {
    setLoading(true)
    load()
  }

  const gallery = useMemo(() => sortGallery(item?.images), [item])

  if (loading) {
    return <LoadingSpinner size="lg" />
  }

  if (error === 'failed') {
    return <div className="px-4 py-12 sm:py-16"><LoadError what="this place" onRetry={retry} className="max-w-xl mx-auto" /></div>
  }

  if (error || !item) {
    return (
      <div className="p-6 sm:p-8 text-center text-gray-600">
        Food &amp; Beverage establishment not found.
      </div>
    )
  }

  return (
    <div className="bg-gray-50 min-h-screen">
      <DetailHero
        backTo="/food-beverage"
        backLabel="Back to Food & Beverage"
        badge={item.cuisine_type || estimateOf(item, 'cuisine_type') || 'Food Place'}
        title={item.name}
        subtitle={item.address}
        rating={item.average_rating}
        reviewCount={item.ratings?.length}
        images={gallery}
        imageAlt={item.name}
        sample={item.is_sample}
      />

      <DetailLayout
        aside={
          <FactsCard
            facts={[
              { icon: FiMapPin, label: 'Address', value: item.address },
              { icon: FiPhone, label: 'Contact', value: item.contact_number || 'Not available' },
              { icon: FiClock, label: 'Operating Hours', value: item.operating_hours || approxHours(estimateOf(item, 'operating_hours')) || 'Not specified' },
              {
                label: 'Price Range',
                value: item.price_range && (
                  <span>
                    <span className="capitalize">{item.price_range}</span>
                    {estimateOf(item, 'meal_price') && ` · ${approxRange(estimateOf(item, 'meal_price'), ' per person')}`}
                  </span>
                ),
              },
            ]}
            name={item.name}
            latitude={item.latitude}
            longitude={item.longitude}
            website={item.website}
          />
        }
      >
        <DetailSection title="About this place">
          <p className="text-gray-700 leading-relaxed whitespace-pre-line">{item.description}</p>
        </DetailSection>

        {(item.specialties || item.meals_served?.length > 0) && (
          <DetailSection title="Specialties & Meals">
            <DiningInfo details={item.specialties} meals={item.meals_served} />
          </DetailSection>
        )}

        {item.menu_items?.length > 0 && (
          <DetailSection
            title="Menu"
            subtitle="Informational only — this menu does not support online ordering or reservations. Contact the establishment directly."
          >
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              {item.menu_items.map((dish) => (
                <div key={dish.id} className="flex gap-4 rounded-xl border border-gray-100 bg-gray-50/60 p-3">
                  <img
                    src={getImageUrl(dish)}
                    alt={dish.name}
                    loading="lazy"
                    className="h-20 w-20 shrink-0 rounded-lg object-cover bg-gray-100"
                  />
                  <div className="min-w-0 flex-1">
                    <div className="flex items-start justify-between gap-3">
                      <p className="font-semibold text-gray-900 wrap-break-word min-w-0">{dish.name}</p>
                      <span className="text-sm font-semibold text-primary-700 shrink-0">
                        {/* A price not confirmed with the business is shown as an estimate. */}
                        {estimateOf(dish, 'price') ? 'Est. ' : ''}₱{Number(dish.price).toFixed(2)}
                      </span>
                    </div>
                    {dish.description && (
                      <p className="mt-1 text-sm text-gray-500 line-clamp-2">{dish.description}</p>
                    )}
                  </div>
                </div>
              ))}
            </div>
          </DetailSection>
        )}

        <DetailSection title="Location">
          <LocationMap latitude={item.latitude} longitude={item.longitude} title={item.name} address={item.address} />
        </DetailSection>

        {item.ratings?.length > 0 && (
          <GuestReviewCarousel item={item} title="What Diners Are Saying" />
        )}

        <NearbyPlaces data={item} name={item.name} kind="food" />

        <div id="reviews" className="scroll-mt-24">
          <ReviewSection item={item} rateableType="food_beverage" onReviewed={load} />
        </div>
      </DetailLayout>
    </div>
  )
}
