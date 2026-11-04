// frontend/src/pages/admin/GuestReviews.jsx
import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { FiCornerDownRight, FiExternalLink } from 'react-icons/fi'
import { getAdminGuestReviews } from '../../api/admin'
import { formatShortDate, parseReview } from '../../utils/helpers'
import { StarDisplay } from '../../components/common/StarRating'
import Pagination from '../../components/common/Pagination'
import LoadingSpinner from '../../components/common/LoadingSpinner'

const TYPES = [
  { value: '', label: 'All listings' },
  { value: 'destination', label: 'Destinations', path: '/destinations' },
  { value: 'accommodation', label: 'Accommodations', path: '/accommodations' },
  { value: 'food_beverage', label: 'Food & Beverage', path: '/food-beverage' },
  { value: 'retail_shop', label: 'Shops', path: '/shops' },
  // Products have no public detail page, so they are listed without a link.
  { value: 'retail_product', label: 'Products' },
]

const TYPE_BY_VALUE = Object.fromEntries(TYPES.map((type) => [type.value, type]))

/**
 * Read-only overview of every guest review and owner response. Seeded sample
 * reviews are tagged here (but not on public pages) so admins can tell them apart.
 */
export default function GuestReviews() {
  const [type, setType] = useState('')
  const [filter, setFilter] = useState('all')
  const [source, setSource] = useState('all')
  const [page, setPage] = useState(1)
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(false)

  const load = useCallback(
    () =>
      getAdminGuestReviews({ type: type || undefined, filter, source, page })
        .then((result) => {
          setData(result)
          setError(false)
        })
        .catch(() => setError(true))
        .finally(() => setLoading(false)),
    [type, filter, source, page]
  )

  useEffect(() => {
    load()
  }, [load])

  const update = (setter) => (e) => {
    setLoading(true)
    setter(e.target.value)
    setPage(1)
  }

  const reviews = data?.data || []
  const counts = data?.counts || {}
  const totalAll = Object.values(counts).reduce((sum, n) => sum + n, 0)

  return (
    <div>
      <div className="mb-6">
        <p className="text-primary-600 font-medium mb-2">Admin</p>
        <h1 className="text-2xl sm:text-3xl font-heading font-bold text-gray-900">Guest Reviews</h1>
        <p className="text-gray-600 mt-2">
          Reviews from tourists and responses from business owners across all listings. Reviews tagged
          “Sample” are seeded demo data and are not genuine feedback.
        </p>
      </div>

      <div className="bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) p-4 mb-6 grid grid-cols-1 sm:grid-cols-3 gap-3">
        <label className="text-sm">
          <span className="block font-medium text-gray-700 mb-1">Listing type</span>
          <select value={type} onChange={update(setType)} className="input-field">
            {TYPES.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label} ({option.value ? counts[option.value] ?? 0 : totalAll})
              </option>
            ))}
          </select>
        </label>
        <label className="text-sm">
          <span className="block font-medium text-gray-700 mb-1">Owner response</span>
          <select value={filter} onChange={update(setFilter)} className="input-field">
            <option value="all">Any</option>
            <option value="replied">Replied</option>
            <option value="unreplied">Not replied</option>
          </select>
        </label>
        <label className="text-sm">
          <span className="block font-medium text-gray-700 mb-1">Source</span>
          <select value={source} onChange={update(setSource)} className="input-field">
            <option value="all">All reviews</option>
            <option value="genuine">Genuine only</option>
            <option value="demo">Sample (demo) only</option>
          </select>
        </label>
      </div>

      {loading ? (
        <LoadingSpinner size="lg" />
      ) : error ? (
        <div className="bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) p-8 text-center">
          <p className="text-gray-600 mb-4">We couldn't load reviews.</p>
          <button type="button" onClick={() => { setLoading(true); load() }} className="btn-secondary">
            Try again
          </button>
        </div>
      ) : reviews.length === 0 ? (
        <div className="bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) p-8 text-center text-gray-500">
          No reviews match these filters.
        </div>
      ) : (
        <>
          <p className="text-sm text-gray-500 mb-3">{data.total} review{data.total === 1 ? '' : 's'}</p>
          <ul className="space-y-4">
            {reviews.map((review) => {
              const { isDemo, comment } = parseReview(review)
              const listingType = TYPE_BY_VALUE[review.listing_type]
              return (
                <li key={review.id} className="bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) p-5">
                  <div className="flex flex-wrap items-start justify-between gap-3 mb-2">
                    <div className="min-w-0">
                      <p className="text-xs font-medium uppercase tracking-wide text-gray-500">
                        {listingType?.label || 'Listing'}
                      </p>
                      {review.rateable && !listingType?.path ? (
                        <p className="font-heading font-semibold text-gray-900">{review.rateable.name}</p>
                      ) : review.rateable ? (
                        <Link
                          to={`${listingType?.path}/${review.rateable_id}`}
                          className="inline-flex items-center gap-1 font-heading font-semibold text-gray-900 hover:text-primary-600"
                        >
                          {review.rateable.name}
                          <FiExternalLink size={13} aria-hidden="true" />
                        </Link>
                      ) : (
                        <p className="font-heading font-semibold text-gray-400">Listing removed</p>
                      )}
                    </div>
                    <StarDisplay rating={review.score} size={14} />
                  </div>

                  <p className="text-sm text-gray-700">
                    <span className="font-medium text-gray-900">{review.user?.name || 'Unknown user'}</span>
                    <span className="text-gray-400"> · {formatShortDate(review.created_at)}</span>
                    {isDemo && (
                      <span className="ml-2 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-800">
                        Sample
                      </span>
                    )}
                  </p>
                  {comment && <p className="mt-1.5 text-sm text-gray-600">{comment}</p>}

                  {review.reply && (
                    <div className="mt-3 ml-4 rounded-lg border-l-4 border-primary-500 bg-primary-50/60 px-4 py-3">
                      <p className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-primary-700">
                        <FiCornerDownRight size={14} aria-hidden="true" />
                        Owner response
                        {review.reply.user?.name && (
                          <span className="font-normal normal-case tracking-normal text-gray-500">
                            by {review.reply.user.name}
                          </span>
                        )}
                      </p>
                      <p className="mt-1.5 text-sm text-gray-700 whitespace-pre-line">{review.reply.body}</p>
                    </div>
                  )}
                </li>
              )
            })}
          </ul>

          <Pagination
            currentPage={data.current_page}
            lastPage={data.last_page}
            onPageChange={(value) => {
              setLoading(true)
              setPage(value)
              window.scrollTo({ top: 0, behavior: 'smooth' })
            }}
          />
        </>
      )}
    </div>
  )
}
