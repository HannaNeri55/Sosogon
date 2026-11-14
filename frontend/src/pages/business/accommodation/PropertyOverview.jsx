import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { FiHome, FiGrid, FiCheckCircle, FiExternalLink } from 'react-icons/fi'
import { getMyAccommodation } from '../../../api/accommodationOwner'
import { optionImage, optionName } from '../../../utils/accommodationOptions'
import StatCard from '../../../components/common/StatCard'
import LoadingSpinner from '../../../components/common/LoadingSpinner'
import ListingInsights from '../../../components/business/ListingInsights'

const STATUS_COPY = {
  approved: { label: 'Approved', style: 'bg-green-100 text-green-700', note: 'Your property is live on the Sorsogon platform.' },
  pending: { label: 'Pending Review', style: 'bg-yellow-100 text-yellow-700', note: "Your municipality's tourism office is reviewing your latest submission." },
  rejected: { label: 'Rejected', style: 'bg-red-100 text-red-700', note: 'Your last update was rejected. Edit your property details and resubmit.' },
  archived: { label: 'Archived', style: 'bg-gray-100 text-gray-700', note: 'This property is archived and not visible to tourists.' },
}

export default function PropertyOverview() {
  const [accommodation, setAccommodation] = useState(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    getMyAccommodation().then(setAccommodation).finally(() => setLoading(false))
  }, [])

  if (loading) return <LoadingSpinner size="lg" />
  if (!accommodation) {
    return (
      <div className="bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) p-8 text-center">
        <h1 className="text-xl font-heading font-semibold text-gray-900">No property on SOSOGON right now</h1>
        <p className="mt-2 text-gray-600">Your listing may still be under review, or it was removed by the Tourism Office.</p>
        <Link to="/business" className="btn-primary mt-5">Check your listing status</Link>
      </div>
    )
  }

  const rooms = accommodation.rooms || []
  const pendingRooms = rooms.filter((r) => r.status === 'pending').length
  const status = STATUS_COPY[accommodation.status] || STATUS_COPY.pending
  const approvedRooms = rooms.filter((r) => r.status === 'approved')

  return (
    <div>
      <div className="mb-8">
        <p className="text-xs font-semibold uppercase tracking-wider text-secondary-500 mb-2">Accommodation Owner Portal</p>
        <h1 className="text-2xl sm:text-3xl font-heading font-bold text-gray-900">{accommodation.name}</h1>
      </div>

      <div className="bg-white rounded-2xl border border-gray-200/70 p-4 sm:p-6 mb-8 flex items-center justify-between flex-wrap gap-4 shadow-(--shadow-card)">
        <div className="flex items-center gap-4">
          <div className="w-12 h-12 rounded-lg bg-primary-50 text-primary-600 flex items-center justify-center shrink-0">
            <FiHome size={22} />
          </div>
          <div>
            <p className="text-sm text-gray-500 mb-1">Property Approval Status</p>
            <p className="text-gray-700">{status.note}</p>
          </div>
        </div>
        <span className={`text-sm font-medium px-3 py-1.5 rounded-full shrink-0 ${status.style}`}>{status.label}</span>
      </div>

      <ListingInsights type="accommodation" reviewsPath="/business/accommodation/reviews" />

      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
        <StatCard icon={FiGrid} label="Accommodation Options" value={rooms.length} accent="primary" />
        <StatCard icon={FiCheckCircle} label="Visible to Tourists" value={approvedRooms.length} accent="green" />
        <StatCard icon={FiGrid} label="Awaiting Approval" value={pendingRooms} accent="yellow" />
      </div>

      <div className="bg-white rounded-2xl p-4 sm:p-6 border border-gray-200/70 shadow-(--shadow-card)">
        <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2 mb-4">
          <div>
            <h2 className="text-xl font-heading font-semibold text-gray-900">What Tourists Currently See</h2>
            <p className="text-sm text-gray-500 mt-1">Only approved options are visible on the public Sorsogon platform.</p>
          </div>
          {accommodation.status === 'approved' && (
            <Link
              to={`/accommodations/${accommodation.id}`}
              target="_blank"
              rel="noreferrer"
              className="flex items-center gap-1.5 text-primary-600 hover:text-primary-700 text-sm font-medium shrink-0"
            >
              View live page <FiExternalLink size={13} />
            </Link>
          )}
        </div>

        {approvedRooms.length === 0 ? (
          <p className="text-gray-500 text-sm py-6 text-center">No approved options are visible to tourists yet.</p>
        ) : (
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            {approvedRooms.map((room) => (
              <div key={room.id} className="border border-gray-100 rounded-lg overflow-hidden">
                <img src={optionImage(room.images?.[0])} alt={optionName(room)} className="w-full h-28 object-cover bg-gray-100" />
                <div className="p-3">
                  <p className="text-sm font-medium text-gray-900 truncate">{optionName(room)}</p>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  )
}
