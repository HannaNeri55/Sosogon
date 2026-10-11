import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { FiMapPin, FiPhone, FiClock } from 'react-icons/fi'
import { getRetailShop } from '../../api/retailShops'
import LocationMap from '../../components/maps/LocationMap'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import ReviewSection from '../../components/common/ReviewSection'
import DetailHero from '../../components/common/DetailHero'
import DetailInfoRow from '../../components/common/DetailInfoRow'
import { getImageUrl } from '../../utils/helpers'

export default function RetailShopDetail() {
  const { id } = useParams()
  const [shop, setShop] = useState(null)
  const [loading, setLoading] = useState(true)

  const load = () => getRetailShop(id).then(setShop).finally(() => setLoading(false))

  useEffect(() => {
    load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id])

  if (loading) return <LoadingSpinner size="lg" />
  if (!shop) return <div className="p-6 sm:p-8 text-center">Shop not found</div>

  const imageUrl = getImageUrl(shop.images?.[0])

  return (
    <div className="bg-gray-50 min-h-screen">
      <DetailHero
        backTo="/shops"
        backLabel="Back to Pasalubong Shops"
        badge="Pasalubong Shop"
        badgeClassName="bg-secondary-500"
        title={shop.name}
        subtitle={shop.address}
        imageUrl={imageUrl}
        imageAlt={shop.name}
        rating={shop.average_rating}
        reviewCount={shop.ratings?.length}
        latitude={shop.latitude}
        longitude={shop.longitude}
        website={shop.website}
      />

      <div className="max-w-6xl mx-auto px-4 sm:px-6 pb-16 space-y-8">
        <div>
          <h2 className="text-2xl font-heading font-semibold text-gray-900 mb-4">About this shop</h2>
          <p className="text-gray-600 leading-relaxed mb-6">{shop.description}</p>
          <div className="border-t border-gray-100 pt-4">
            <DetailInfoRow icon={FiMapPin} label="Address">{shop.address}</DetailInfoRow>
            <DetailInfoRow icon={FiPhone} label="Contact">{shop.contact_number || 'Not available'}</DetailInfoRow>
            <DetailInfoRow icon={FiClock} label="Operating Hours">{shop.operating_hours || 'Not specified'}</DetailInfoRow>
          </div>
        </div>

        <div className="card p-6 sm:p-8">
          <h2 className="text-2xl font-heading font-semibold text-gray-900 mb-1">Product Catalog</h2>
          <p className="text-sm text-gray-500 mb-5">
            Informational only — this catalog does not support online ordering, payment, or reservations. Visit the shop in person to purchase.
          </p>
          {shop.products?.length > 0 ? (
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
              {shop.products.map((product) => (
                <div
                  key={product.id}
                  className="border border-gray-100 rounded-xl overflow-hidden flex flex-col bg-white shadow-sm"
                >
                  <img
                    src={getImageUrl(product.images?.[0])}
                    alt={product.name}
                    className="w-full h-40 object-cover bg-gray-100"
                  />
                  <div className="p-4 flex flex-col flex-1">
                    <div className="flex items-start justify-between gap-3 mb-2">
                      <p className="font-medium text-gray-900 break-words min-w-0">{product.name}</p>
                      <span className="text-sm font-semibold text-primary-700 shrink-0">₱{Number(product.price).toFixed(2)}</span>
                    </div>
                    <span className="self-start text-xs text-primary-600 bg-primary-50 px-2 py-0.5 rounded mb-2">{product.product_type}</span>
                    {product.description && <p className="text-sm text-gray-500 line-clamp-3">{product.description}</p>}
                  </div>
                </div>
              ))}
            </div>
          ) : (
            <p className="text-gray-500 text-sm">This shop hasn't listed any products yet.</p>
          )}
        </div>

        <div className="card p-6 sm:p-8">
          <h2 className="text-2xl font-heading font-semibold text-gray-900 mb-4">Location</h2>
          <LocationMap latitude={shop.latitude} longitude={shop.longitude} title={shop.name} />
        </div>

        <ReviewSection item={shop} rateableType="retail_shop" onReviewed={load} />
      </div>
    </div>
  )
}
