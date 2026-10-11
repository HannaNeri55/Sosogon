import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { getItineraries } from '../../api/itineraries'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import { formatShortDate, formatTripCost } from '../../utils/helpers'
import { INTERESTS, TRAVEL_PACES, BUDGETS } from '../../utils/constants'
import { FiPlus, FiCalendar, FiActivity, FiDollarSign, FiChevronRight, FiCompass } from 'react-icons/fi'

function labelFor(list, value) {
  const match = list.find((item) => item.value === value)
  if (match) return match.label
  return value ? value.charAt(0).toUpperCase() + value.slice(1) : ''
}

export default function SavedItineraries() {
  const [items, setItems] = useState([])
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    getItineraries()
      .then((data) => {
        setItems(data.data || [])
        setTotal(data.total ?? (data.data || []).length)
      })
      .finally(() => setLoading(false))
  }, [])

  if (loading) return <LoadingSpinner size="lg" />

  return (
    <div className="bg-gray-50 min-h-screen">
      <div className="max-w-5xl mx-auto px-4 sm:px-6 py-12">
        <div className="flex flex-wrap items-end justify-between mb-8 gap-4">
          <div>
            <p className="text-primary-600 font-medium mb-2">Tourist</p>
            <h1 className="text-4xl font-heading font-bold text-gray-900">Saved Itineraries</h1>
            {total > 0 && (
              <p className="text-sm text-gray-500 mt-2">
                {total} itinerar{total !== 1 ? 'ies' : 'y'} saved
              </p>
            )}
          </div>
          <Link to="/plan-trip" className="btn-primary shrink-0">
            <FiPlus size={16} /> Plan New Trip
          </Link>
        </div>

        {items.length === 0 ? (
          <div className="bg-white rounded-xl p-12 text-center border border-gray-100">
            <FiCompass size={40} className="mx-auto text-gray-300 mb-4" />
            <p className="text-gray-500 mb-4">No itineraries yet.</p>
            <Link to="/plan-trip" className="btn-primary">Generate Your First Itinerary</Link>
          </div>
        ) : (
          <>
            <div className="space-y-4">
              {items.map((item) => (
                <Link
                  key={item.id}
                  to={`/itineraries/${item.id}`}
                  className="flex items-center gap-4 bg-white rounded-xl p-6 border border-gray-100 hover:border-gray-200 hover:shadow-sm transition-all"
                >
                  <div className="w-12 h-12 rounded-full bg-primary-50 text-primary-600 flex items-center justify-center shrink-0">
                    <FiCompass size={20} />
                  </div>

                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                      <h3 className="font-heading font-semibold text-lg text-gray-900 truncate">{item.title}</h3>
                      {item.created_at && (
                        <span className="text-xs text-gray-400 shrink-0">{formatShortDate(item.created_at)}</span>
                      )}
                    </div>

                    <div className="flex flex-wrap items-center gap-2 mt-2">
                      <span className="inline-flex items-center gap-1 text-xs font-medium text-gray-600 bg-gray-100 px-2 py-0.5 rounded">
                        <FiCalendar size={11} /> {item.num_days} day{item.num_days > 1 ? 's' : ''}
                      </span>
                      <span className="inline-flex items-center gap-1 text-xs font-medium text-gray-600 bg-gray-100 px-2 py-0.5 rounded">
                        <FiActivity size={11} /> {labelFor(TRAVEL_PACES, item.travel_pace)}
                      </span>
                      <span className="inline-flex items-center gap-1 text-xs font-medium text-gray-600 bg-gray-100 px-2 py-0.5 rounded">
                        <FiDollarSign size={11} /> {formatTripCost(item, labelFor(BUDGETS, item.budget))}
                      </span>
                      {item.interests?.slice(0, 3).map((interest) => (
                        <span
                          key={interest}
                          className="text-xs font-medium text-accent-600 bg-accent-50 px-2 py-0.5 rounded"
                        >
                          {labelFor(INTERESTS, interest)}
                        </span>
                      ))}
                    </div>
                  </div>

                  <FiChevronRight size={18} className="text-gray-300 shrink-0" />
                </Link>
              ))}
            </div>

            {total > items.length && (
              <p className="text-xs text-gray-400 text-center mt-6">
                Showing {items.length} of {total} — newest first
              </p>
            )}
          </>
        )}
      </div>
    </div>
  )
}