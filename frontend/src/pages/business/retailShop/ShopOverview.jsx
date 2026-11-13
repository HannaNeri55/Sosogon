import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { FiShoppingBag, FiPackage, FiCheckCircle, FiClock, FiExternalLink } from 'react-icons/fi'
import { getMyRetailShop } from '../../../api/retailShopOwner'
import { formatProductPrice, getImageUrl } from '../../../utils/helpers'
import StatCard from '../../../components/common/StatCard'
import LoadingSpinner from '../../../components/common/LoadingSpinner'
import ListingInsights from '../../../components/business/ListingInsights'

const STATUS_COPY = {
  approved: { label: 'Approved', style: 'bg-green-100 text-green-700', note: 'Your shop is live on the Sorsogon platform.' },
  pending: { label: 'Pending Review', style: 'bg-yellow-100 text-yellow-700', note: 'The Bulan Tourism Office is reviewing your latest submission.' },
  rejected: { label: 'Rejected', style: 'bg-red-100 text-red-700', note: 'Your last update was rejected. Edit your shop details and resubmit.' },
  archived: { label: 'Archived', style: 'bg-gray-100 text-gray-700', note: 'This shop is archived and not visible to tourists.' },
}

export default function ShopOverview() {
  const [shop, setShop] = useState(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    getMyRetailShop().then(setShop).finally(() => setLoading(false))
  }, [])

  if (loading) return <LoadingSpinner size="lg" />
  if (!shop) {
    return (
      <div className="bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) p-8 text-center">
        <h1 className="text-xl font-heading font-semibold text-gray-900">No shop on SOSOGON right now</h1>
        <p className="mt-2 text-gray-600">Your listing may still be under review, or it was removed by the Tourism Office.</p>
        <Link to="/business" className="btn-primary mt-5">Check your listing status</Link>
      </div>
    )
  }

  const products = shop.products || []
  const approvedProducts = products.filter((p) => p.status === 'approved')
  const pendingProducts = products.filter((p) => p.status === 'pending')
  const status = STATUS_COPY[shop.status] || STATUS_COPY.pending

  return (
    <div>
      <div className="mb-8">
        <p className="text-xs font-semibold uppercase tracking-wider text-secondary-500 mb-2">Local Products Owner Portal</p>
        <h1 className="text-2xl sm:text-3xl font-heading font-bold text-gray-900">{shop.name}</h1>
      </div>

      <div className="bg-white rounded-2xl border border-gray-200/70 p-4 sm:p-6 mb-8 flex items-center justify-between flex-wrap gap-4 shadow-(--shadow-card)">
        <div className="flex items-center gap-4">
          <div className="w-12 h-12 rounded-lg bg-primary-50 text-primary-600 flex items-center justify-center shrink-0">
            <FiShoppingBag size={22} />
          </div>
          <div>
            <p className="text-sm text-gray-500 mb-1">Shop Approval Status</p>
            <p className="text-gray-700">{status.note}</p>
          </div>
        </div>
        <span className={`text-sm font-medium px-3 py-1.5 rounded-full shrink-0 ${status.style}`}>{status.label}</span>
      </div>

      <ListingInsights type="retail_shop" reviewsPath="/business/retail-shop/reviews" />

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <StatCard icon={FiPackage} label="Total Products" value={products.length} accent="primary" />
        <StatCard icon={FiCheckCircle} label="Approved" value={approvedProducts.length} accent="green" />
        <StatCard icon={FiClock} label="Pending Approval" value={pendingProducts.length} accent="yellow" />
        <StatCard icon={FiPackage} label="Product Capacity" value={`${products.length} / 20`} accent="secondary" />
      </div>

      <div className="bg-white rounded-2xl p-4 sm:p-6 border border-gray-200/70 shadow-(--shadow-card)">
        <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2 mb-4">
          <div>
            <h2 className="text-xl font-heading font-semibold text-gray-900">What Tourists Currently See</h2>
            <p className="text-sm text-gray-500 mt-1">Only approved products are visible on the public Sorsogon platform. This is a browsing catalog only — tourists cannot order or reserve items online.</p>
          </div>
          {shop.status === 'approved' && (
            <Link
              to={`/shops/${shop.id}`}
              target="_blank"
              rel="noreferrer"
              className="flex items-center gap-1.5 text-primary-600 hover:text-primary-700 text-sm font-medium shrink-0"
            >
              View live page <FiExternalLink size={13} />
            </Link>
          )}
        </div>

        {approvedProducts.length === 0 ? (
          <p className="text-gray-500 text-sm py-6 text-center">No approved products are visible to tourists yet.</p>
        ) : (
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
            {approvedProducts.map((item) => (
              <div key={item.id} className="border border-gray-200/70 rounded-2xl overflow-hidden flex flex-col bg-white shadow-sm">
                <img src={getImageUrl(item.images?.[0])} alt={item.name} className="w-full h-40 object-cover bg-gray-100" />
                <div className="p-4 flex flex-col flex-1">
                  <div className="flex items-start justify-between gap-3 mb-2">
                    <p className="font-medium text-gray-900 break-words min-w-0">{item.name}</p>
                    {item.price != null && <span className="text-sm font-semibold text-primary-700 shrink-0">{formatProductPrice(item.price)}</span>}
                  </div>
                  {item.product_type && (
                    <span className="self-start text-xs text-primary-600 bg-primary-50 px-2 py-0.5 rounded mb-2">{item.product_type}</span>
                  )}
                  {item.description && <p className="text-sm text-gray-500 line-clamp-3">{item.description}</p>}
                </div>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  )
}
