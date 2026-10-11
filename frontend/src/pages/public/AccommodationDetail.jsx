import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { FiCheck, FiMapPin, FiPhone, FiClock, FiDollarSign } from 'react-icons/fi'
import { getAccommodation } from '../../api/accommodations'
import LocationMap from '../../components/maps/LocationMap'
import ListingCard from '../../components/cards/ListingCard'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import ReviewSection from '../../components/common/ReviewSection'
import DetailHero from '../../components/common/DetailHero'
import DetailInfoRow from '../../components/common/DetailInfoRow'
import { getImageUrl } from '../../utils/helpers'

export default function AccommodationDetail() {
  const { id } = useParams()
  const [item, setItem] = useState(null)
  const [loading, setLoading] = useState(true)

  const load = () => getAccommodation(id).then(setItem).finally(() => setLoading(false))

  useEffect(() => {
    load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id])

  if (loading) return <LoadingSpinner size="lg" />
  if (!item) return <div className="p-6 sm:p-8 text-center">Accommodation not found</div>

  const imageUrl = getImageUrl(item.images?.[0])

  return (
    <div className="bg-gray-50 min-h-screen">
      <DetailHero
        backTo="/accommodations"
        backLabel="Back to Accommodations"
        badge={item.type}
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

        {item.rooms?.length > 0 && (
          <div className="card p-6 sm:p-8">
            <h2 className="text-2xl font-heading font-semibold text-gray-900 mb-1">Rooms</h2>
            <p className="text-sm text-gray-500 mb-5">
              Status is updated manually by the property owner and is for information only — contact the property directly to arrange a stay.
            </p>
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
              {item.rooms.map((room) => {
                const roomImageUrl = getImageUrl(room.images?.[0])
                const isOccupied = room.occupancy_status === 'occupied'
                return (
                  <div
                    key={room.id}
                    className="rounded-xl border border-gray-100 bg-white shadow-sm hover:shadow-md transition-shadow duration-200 overflow-hidden flex flex-col"
                  >
                    <div className="relative aspect-4/3 overflow-hidden bg-gray-100">
                      <img src={roomImageUrl} alt={room.room_number} className="w-full h-full object-cover" />
                      <span
                        className={`absolute top-2 right-2 text-xs font-medium px-2 py-0.5 rounded-full capitalize ${
                          isOccupied ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800'
                        }`}
                      >
                        {room.occupancy_status}
                      </span>
                    </div>
                    <div className="p-4 flex-1 flex flex-col">
                      <p className="font-medium text-gray-900 mb-1 truncate">{room.room_number}</p>
                      <div className="flex items-center gap-2 mb-1 min-h-5.5 text-sm text-gray-500">
                        {room.category && (
                          <span className="px-2 py-0.5 rounded-full bg-gray-100 text-gray-600 text-xs font-medium capitalize">
                            {room.category}
                          </span>
                        )}
                        {room.price && <span>₱{Number(room.price).toLocaleString()}</span>}
                      </div>
                      <p className="text-sm text-gray-500 line-clamp-2 min-h-[2.5em]">{room.description}</p>
                    </div>
                  </div>
                )
              })}
            </div>
          </div>
        )}

        {item.amenities?.length > 0 && (
          <div className="card p-6 sm:p-8">
            <h2 className="text-2xl font-heading font-semibold text-gray-900 mb-4">Amenities</h2>
            <div className="grid grid-cols-2 sm:grid-cols-3 gap-3">
              {item.amenities.map((a) => (
                <div key={a} className="flex items-center gap-2 text-sm text-gray-700">
                  <FiCheck className="text-primary-500 shrink-0" size={16} />
                  {a}
                </div>
              ))}
            </div>
          </div>
        )}

        <div className="card p-6 sm:p-8">
          <h2 className="text-2xl font-heading font-semibold text-gray-900 mb-4">Location</h2>
          <LocationMap latitude={item.latitude} longitude={item.longitude} title={item.name} />
        </div>

        {item.nearby_food_beverages?.length > 0 && (
          <section>
            <h2 className="text-2xl font-heading font-bold text-gray-900">Food &amp; Beverage</h2>
            <p className="text-sm text-gray-500 mt-1 mb-6">The closest verified places to eat near {item.name}.</p>
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
              {item.nearby_food_beverages.map((place) => (
                <ListingCard
                  key={place.id}
                  item={place}
                  basePath="/food-beverage"
                  typeLabel={place.cuisine_type}
                  distanceKm={place.distance_km}
                />
              ))}
            </div>
          </section>
        )}

        {item.nearby_retail_shops?.length > 0 && (
          <section>
            <h2 className="text-2xl font-heading font-bold text-gray-900">Souvenir Shops</h2>
            <p className="text-sm text-gray-500 mt-1 mb-6">The closest verified pasalubong shops to {item.name}.</p>
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
              {item.nearby_retail_shops.map((shop) => (
                <ListingCard key={shop.id} item={shop} basePath="/shops" typeLabel="Pasalubong Shop" distanceKm={shop.distance_km} />
              ))}
            </div>
          </section>
        )}

        <ReviewSection item={item} rateableType="accommodation" onReviewed={load} />
      </div>
    </div>
  )
}
