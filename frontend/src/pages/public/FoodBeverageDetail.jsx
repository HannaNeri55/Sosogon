// frontend/src/pages/public/FoodBeverageDetail.jsx
import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { FiMapPin, FiPhone, FiClock, FiDollarSign } from 'react-icons/fi'
import { getFoodBeverage } from '../../api/foodBeverages'
import LocationMap from '../../components/maps/LocationMap'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import ReviewSection from '../../components/common/ReviewSection'
import DetailHero from '../../components/common/DetailHero'
import DetailInfoRow from '../../components/common/DetailInfoRow'
import { getImageUrl } from '../../utils/helpers'

export default function FoodBeverageDetail() {
  const { id } = useParams()
  const [item, setItem] = useState(null)
  const [loading, setLoading] = useState(true)

  const load = () => getFoodBeverage(id).then(setItem).finally(() => setLoading(false))

  useEffect(() => {
    load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id])

  if (loading) return <LoadingSpinner size="lg" />
  if (!item) return <div className="p-6 sm:p-8 text-center">Item not found</div>

  const imageUrl = getImageUrl(item.images?.[0])

  return (
    <div className="bg-gray-50 min-h-screen">
      <DetailHero
        backTo="/food-beverage"
        backLabel="Back to Food & Beverage"
        badge={item.cuisine_type || 'Food Place'}
        badgeClassName="bg-secondary-500"
        title={item.name}
        subtitle={item.address}
        rating={item.average_rating}
        reviewCount={item.ratings?.length}
        imageUrl={imageUrl}
        imageAlt={item.name}
        latitude={item.latitude}
        longitude={item.longitude}
        website={item.website}
      />

      <div className="max-w-6xl mx-auto px-4 sm:px-6 pb-16 space-y-8">
        <div>
          <h2 className="text-2xl font-heading font-semibold text-gray-900 mb-4">About this place</h2>
          <p className="text-gray-600 leading-relaxed mb-6">{item.description}</p>
          <div className="border-t border-gray-100 pt-4">
            <DetailInfoRow icon={FiMapPin} label="Address">{item.address}</DetailInfoRow>
            <DetailInfoRow icon={FiPhone} label="Contact">{item.contact_number || 'Not available'}</DetailInfoRow>
            <DetailInfoRow icon={FiClock} label="Operating Hours">{item.operating_hours || 'Not specified'}</DetailInfoRow>
            <DetailInfoRow icon={FiDollarSign} label="Price Range">
              <span className="capitalize">{item.price_range}</span>
            </DetailInfoRow>
          </div>
        </div>

        {item.menu_items?.length > 0 && (
          <div className="card p-6 sm:p-8">
            <h2 className="text-2xl font-heading font-semibold text-gray-900 mb-1">Menu</h2>
            <p className="text-sm text-gray-500 mb-5">
              Informational only — this menu does not support online ordering or reservations. Contact the establishment directly.
            </p>
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
              {item.menu_items.map((dish) => {
                const dishImageUrl = getImageUrl(dish)
                return (
                  <div key={dish.id} className="border border-gray-100 rounded-xl overflow-hidden flex flex-col bg-white shadow-sm">
                    <img src={dishImageUrl} alt={dish.name} className="w-full h-40 object-cover bg-gray-100" />
                    <div className="p-4 flex flex-col flex-1">
                      <div className="flex items-start justify-between gap-3 mb-2">
                        <p className="font-medium text-gray-900 break-words min-w-0">{dish.name}</p>
                        <span className="text-sm font-semibold text-primary-700 shrink-0">₱{Number(dish.price).toFixed(2)}</span>
                      </div>
                      {dish.description && <p className="text-sm text-gray-500 line-clamp-3">{dish.description}</p>}
                    </div>
                  </div>
                )
              })}
            </div>
          </div>
        )}

        <div className="card p-6 sm:p-8">
          <h2 className="text-2xl font-heading font-semibold text-gray-900 mb-4">Location</h2>
          <LocationMap latitude={item.latitude} longitude={item.longitude} title={item.name} />
        </div>

        <ReviewSection item={item} rateableType="food_beverage" onReviewed={load} />
      </div>
    </div>
  )
}
