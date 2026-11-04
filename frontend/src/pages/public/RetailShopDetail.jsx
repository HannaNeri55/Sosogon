import { useEffect, useMemo, useState } from 'react'
import { useParams } from 'react-router-dom'
import { FiMapPin, FiPhone, FiClock, FiShoppingBag } from 'react-icons/fi'
import { approxRange, estimateOf } from '../../utils/estimates'
import { getRetailShop } from '../../api/retailShops'
import LocationMap from '../../components/maps/LocationMap'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import ReviewSection from '../../components/common/ReviewSection'
import GuestReviewCarousel from '../../components/common/GuestReviewCarousel'
import NearbyPlaces from '../../components/common/NearbyPlaces'
import DetailHero from '../../components/common/DetailHero'
import DetailLayout from '../../components/detail/DetailLayout'
import DetailSection from '../../components/detail/DetailSection'
import FactsCard from '../../components/detail/FactsCard'
import { getImageUrl } from '../../utils/helpers'

// Cover photo first, then the owner's order.
const sortImages = (images = []) =>
  [...images].sort(
    (a, b) =>
      Number(Boolean(b.is_primary)) - Number(Boolean(a.is_primary)) ||
      (a.sort_order ?? 0) - (b.sort_order ?? 0) ||
      a.id - b.id
  )

export default function RetailShopDetail() {
  const { id } = useParams()

  const [shop, setShop] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(false)

  const load = async () => {
    try {
      const data = await getRetailShop(id)
      setShop(data)
      setError(false)
    } catch {
      setShop(null)
      setError(true)
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

  const gallery = useMemo(() => sortImages(shop?.images), [shop])

  if (loading) {
    return <LoadingSpinner size="lg" />
  }

  if (error || !shop) {
    return (
      <div className="p-6 sm:p-8 text-center text-gray-600">
        Shop not found or could not be loaded.
      </div>
    )
  }

  return (
    <div className="bg-gray-50 min-h-screen">
      <DetailHero
        backTo="/shops"
        backLabel="Back to Pasalubong Shops"
        badge="Pasalubong Shop"
        title={shop.name}
        subtitle={shop.address}
        rating={shop.average_rating}
        reviewCount={shop.ratings?.length}
        images={gallery}
        imageAlt={shop.name}
        sample={shop.is_sample}
      />

      <DetailLayout
        aside={
          <FactsCard
            facts={[
              { icon: FiMapPin, label: 'Address', value: shop.address },
              { icon: FiPhone, label: 'Contact', value: shop.contact_number || 'Not available' },
              { icon: FiClock, label: 'Operating Hours', value: shop.operating_hours || 'Not specified' },
              ...(estimateOf(shop, 'item_price')
                ? [{ icon: FiShoppingBag, label: 'Typical Souvenir Spend', value: approxRange(estimateOf(shop, 'item_price'), ' per person') }]
                : []),
            ]}
            latitude={shop.latitude}
            longitude={shop.longitude}
            website={shop.website}
          />
        }
      >
        {shop.products?.length > 0 ? (
          <>
            <DetailSection title="About this shop">
              <p className="text-gray-700 leading-relaxed whitespace-pre-line">{shop.description}</p>
            </DetailSection>

            <DetailSection
              title="Product Catalog"
              subtitle="Informational only — this catalog does not support online ordering, payment, or reservations. Visit the shop in person to purchase."
            >
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                {shop.products.map((product) => (
                  <div key={product.id} className="flex gap-4 rounded-xl border border-gray-100 bg-gray-50/60 p-3">
                    <img
                      src={getImageUrl(sortImages(product.images)[0])}
                      alt={product.name}
                      loading="lazy"
                      className="h-20 w-20 shrink-0 rounded-lg object-cover bg-gray-100"
                    />
                    <div className="min-w-0 flex-1">
                      <div className="flex items-start justify-between gap-3">
                        <p className="font-semibold text-gray-900 wrap-break-word min-w-0">{product.name}</p>
                        <span className="text-sm font-semibold text-primary-700 shrink-0">
                          ₱{Number(product.price).toFixed(2)}
                        </span>
                      </div>
                      {product.product_type && (
                        <span className="mt-1 inline-block text-xs font-medium text-secondary-700 bg-secondary-50 px-2 py-0.5 rounded-full">
                          {product.product_type}
                        </span>
                      )}
                      {product.description && (
                        <p className="mt-1 text-sm text-gray-500 line-clamp-2">{product.description}</p>
                      )}
                    </div>
                  </div>
                ))}
              </div>
            </DetailSection>
          </>
        ) : (
          // No individual products listed: what the shop offers, from its own description.
          <DetailSection title="What You'll Find Here" subtitle="Visit the shop to see what's available and current prices.">
            {shop.product_categories?.length > 0 && (
              <ul className="mb-4 flex flex-wrap gap-2">
                {shop.product_categories.map((category) => (
                  <li key={category} className="rounded-full bg-secondary-50 px-3 py-1 text-sm font-medium text-secondary-700">
                    {category}
                  </li>
                ))}
              </ul>
            )}
            <p className="text-gray-700 leading-relaxed whitespace-pre-line">{shop.description}</p>
          </DetailSection>
        )}

        <DetailSection title="Location">
          <LocationMap latitude={shop.latitude} longitude={shop.longitude} title={shop.name} address={shop.address} />
        </DetailSection>

        {shop.ratings?.length > 0 && (
          <GuestReviewCarousel item={shop} title="What Shoppers Are Saying" />
        )}

        <NearbyPlaces data={shop} name={shop.name} kind="shop" />

        <div id="reviews" className="scroll-mt-24">
          <ReviewSection item={shop} rateableType="retail_shop" onReviewed={load} />
        </div>
      </DetailLayout>
    </div>
  )
}
