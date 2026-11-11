// frontend/src/pages/business/GuestReviews.jsx
import { useCallback, useEffect, useState } from 'react'
import { FiStar, FiMessageSquare, FiClock } from 'react-icons/fi'
import { getOwnerReviews } from '../../api/ratings'
import { formatShortDate, parseReview } from '../../utils/helpers'
import StatCard from '../../components/common/StatCard'
import SampleReviewTag from '../../components/common/SampleReviewTag'
import Pagination from '../../components/common/Pagination'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import { StarDisplay } from '../../components/common/StarRating'
import OwnerReply from '../../components/common/OwnerReply'

const FILTERS = [
  { value: 'all', label: 'All' },
  { value: 'unreplied', label: 'Needs reply' },
  { value: 'replied', label: 'Replied' },
]

const EMPTY_COPY = {
  all: 'No guest reviews yet. Reviews from tourists will appear here.',
  unreplied: "You're all caught up. Every review has a response.",
  replied: "You haven't responded to any reviews yet.",
}

/** Business Owner dashboard page: reviews on the owner's listing(s), with replies. */
export default function GuestReviews({ portalLabel }) {
  const [filter, setFilter] = useState('all')
  const [page, setPage] = useState(1)
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(false)

  const load = useCallback(
    () =>
      getOwnerReviews({ filter, page })
        .then((result) => {
          setData(result)
          setError(false)
        })
        .catch(() => setError(true))
        .finally(() => setLoading(false)),
    [filter, page]
  )

  useEffect(() => {
    load()
  }, [load])

  const changeFilter = (value) => {
    if (value === filter) return
    setLoading(true)
    setFilter(value)
    setPage(1)
  }

  const changePage = (value) => {
    setLoading(true)
    setPage(value)
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }

  const summary = data?.summary
  const reviews = data?.data || []
  // Only label each review with its listing when the owner has more than one.
  const showListing = new Set(reviews.map((r) => `${r.rateable_type}:${r.rateable_id}`)).size > 1

  return (
    <div>
      <div className="mb-8">
        <p className="text-xs font-semibold uppercase tracking-wider text-secondary-500 mb-2">{portalLabel}</p>
        <h1 className="text-2xl sm:text-3xl font-heading font-bold text-gray-900">Guest Reviews</h1>
        <p className="text-gray-600 mt-2">See what tourists say about your listing and respond to their feedback.</p>
      </div>

      {summary && (
        <div className="grid grid-cols-2 lg:grid-cols-3 gap-4 mb-8">
          <StatCard
            icon={FiStar}
            label="Average rating"
            value={summary.total ? summary.average.toFixed(1) : '—'}
            accent="yellow"
          />
          <StatCard icon={FiMessageSquare} label="Total reviews" value={summary.total} accent="primary" />
          <StatCard icon={FiClock} label="Awaiting your reply" value={summary.unreplied} accent="red" />
        </div>
      )}

      <div role="tablist" aria-label="Filter reviews" className="flex gap-2 mb-6 overflow-x-auto">
        {FILTERS.map((option) => {
          const active = filter === option.value
          const count = option.value === 'unreplied' ? summary?.unreplied : option.value === 'replied' ? summary?.replied : summary?.total
          return (
            <button
              key={option.value}
              type="button"
              role="tab"
              aria-selected={active}
              onClick={() => changeFilter(option.value)}
              className={`min-h-11 whitespace-nowrap rounded-full px-4 text-sm font-medium transition-colors ${
                active ? 'bg-primary-500 text-white' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50'
              }`}
            >
              {option.label}
              {count != null && <span className={`ml-1.5 ${active ? 'text-white/80' : 'text-gray-400'}`}>{count}</span>}
            </button>
          )
        })}
      </div>

      {loading ? (
        <LoadingSpinner size="lg" />
      ) : error ? (
        <div className="bg-white rounded-2xl border border-gray-200/70 p-8 text-center shadow-(--shadow-card)">
          <p className="text-gray-600 mb-4">We couldn't load your reviews.</p>
          <button type="button" onClick={() => { setLoading(true); load() }} className="btn-secondary">
            Try again
          </button>
        </div>
      ) : reviews.length === 0 ? (
        <div className="bg-white rounded-2xl border border-gray-200/70 p-8 text-center text-gray-500 shadow-(--shadow-card)">
          {EMPTY_COPY[filter]}
        </div>
      ) : (
        <>
          <ul className="space-y-4">
            {reviews.map((review) => {
              const { isDemo, comment } = parseReview(review)
              return (
                <li key={review.id} className="bg-white rounded-2xl border border-gray-200/70 p-5 sm:p-6 shadow-(--shadow-card)">
                  <div className="flex items-start justify-between gap-3 mb-2">
                    <div className="min-w-0">
                      <p className="font-medium text-gray-900">
                        {review.user?.name || 'Guest'}
                        {isDemo && <SampleReviewTag />}
                      </p>
                      <p className="text-xs text-gray-500 mt-0.5">
                        {formatShortDate(review.created_at)}
                        {showListing && review.rateable?.name && <> · {review.rateable.name}</>}
                      </p>
                    </div>
                    <div className="flex flex-col items-end gap-1.5 shrink-0">
                      <StarDisplay rating={review.score} size={14} />
                      {!review.reply && (
                        <span className="rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700">Needs reply</span>
                      )}
                    </div>
                  </div>
                  <p className="text-sm text-gray-700">{comment || <span className="italic text-gray-400">No written comment.</span>}</p>

                  <OwnerReply review={review} canReply onSaved={load} />
                </li>
              )
            })}
          </ul>

          <Pagination currentPage={data.current_page} lastPage={data.last_page} onPageChange={changePage} />
        </>
      )}
    </div>
  )
}
