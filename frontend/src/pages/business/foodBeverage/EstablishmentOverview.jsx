import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { FiCoffee, FiBook, FiCheckCircle, FiClock, FiExternalLink } from 'react-icons/fi'
import { getMyFoodBeverage } from '../../../api/foodBeverageOwner'
import { getImageUrl } from '../../../utils/helpers'
import StatCard from '../../../components/common/StatCard'
import LoadingSpinner from '../../../components/common/LoadingSpinner'

const STATUS_COPY = {
  approved: { label: 'Approved', style: 'bg-green-100 text-green-700', note: 'Your establishment is live on the Sorsogon platform.' },
  pending: { label: 'Pending Review', style: 'bg-yellow-100 text-yellow-700', note: 'The Bulan Tourism Office is reviewing your latest submission.' },
  rejected: { label: 'Rejected', style: 'bg-red-100 text-red-700', note: 'Your last update was rejected. Edit your establishment details and resubmit.' },
  archived: { label: 'Archived', style: 'bg-gray-100 text-gray-700', note: 'This establishment is archived and not visible to tourists.' },
}

export default function EstablishmentOverview() {
  const [foodBeverage, setFoodBeverage] = useState(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    getMyFoodBeverage().then(setFoodBeverage).finally(() => setLoading(false))
  }, [])

  if (loading) return <LoadingSpinner size="lg" />
  if (!foodBeverage) return null

  const menuItems = foodBeverage.menu_items || []
  const approvedItems = menuItems.filter((m) => m.status === 'approved')
  const pendingItems = menuItems.filter((m) => m.status === 'pending')
  const status = STATUS_COPY[foodBeverage.status] || STATUS_COPY.pending

  return (
    <div>
      <div className="mb-8">
        <p className="text-primary-600 font-medium mb-2">Food &amp; Beverage Owner Portal</p>
        <h1 className="text-3xl font-heading font-bold text-gray-900">{foodBeverage.name}</h1>
      </div>

      <div className="bg-white rounded-xl border border-gray-100 p-4 sm:p-6 mb-8 flex items-center justify-between flex-wrap gap-4">
        <div className="flex items-center gap-4">
          <div className="w-12 h-12 rounded-lg bg-primary-50 text-primary-600 flex items-center justify-center shrink-0">
            <FiCoffee size={22} />
          </div>
          <div>
            <p className="text-sm text-gray-500 mb-1">Establishment Approval Status</p>
            <p className="text-gray-700">{status.note}</p>
          </div>
        </div>
        <span className={`text-sm font-medium px-3 py-1.5 rounded-full shrink-0 ${status.style}`}>{status.label}</span>
      </div>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <StatCard icon={FiBook} label="Total Menu Items" value={menuItems.length} accent="primary" />
        <StatCard icon={FiCheckCircle} label="Approved" value={approvedItems.length} accent="green" />
        <StatCard icon={FiClock} label="Pending Approval" value={pendingItems.length} accent="yellow" />
        <StatCard icon={FiBook} label="Menu Capacity" value={`${menuItems.length} / 20`} accent="secondary" />
      </div>

      <div className="bg-white rounded-xl p-4 sm:p-6 border border-gray-100">
        <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2 mb-4">
          <div>
            <h2 className="text-xl font-heading font-semibold text-gray-900">What Tourists Currently See</h2>
            <p className="text-sm text-gray-500 mt-1">Only approved dishes are visible on the public Sorsogon platform.</p>
          </div>
          {foodBeverage.status === 'approved' && (
            <Link
              to={`/food-beverage/${foodBeverage.id}`}
              target="_blank"
              rel="noreferrer"
              className="flex items-center gap-1.5 text-primary-600 hover:text-primary-700 text-sm font-medium shrink-0"
            >
              View live page <FiExternalLink size={13} />
            </Link>
          )}
        </div>

        {approvedItems.length === 0 ? (
          <p className="text-gray-500 text-sm py-6 text-center">No approved dishes are visible to tourists yet.</p>
        ) : (
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            {approvedItems.map((item) => (
              <div key={item.id} className="border border-gray-100 rounded-lg overflow-hidden">
                <img src={getImageUrl(item)} alt={item.name} className="w-full h-28 object-cover bg-gray-100" />
                <div className="p-3">
                  <div className="flex items-center justify-between gap-2">
                    <p className="text-sm font-medium text-gray-900 truncate">{item.name}</p>
                    <span className="text-xs font-medium text-primary-700 shrink-0">₱{Number(item.price).toFixed(2)}</span>
                  </div>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  )
}
