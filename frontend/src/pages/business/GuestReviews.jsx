// frontend/src/pages/business/GuestReviews.jsx
import { useCallback, useEffect, useRef, useState } from 'react'
import { FiCheck, FiMessageSquare, FiSearch, FiStar, FiTrendingUp, FiX } from 'react-icons/fi'
import { getOwnerReviews } from '../../api/ratings'
import { formatShortDate, parseReview } from '../../utils/helpers'
import Pagination from '../../components/common/Pagination'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import { StarDisplay } from '../../components/common/StarRating'
import OwnerReply from '../../components/common/OwnerReply'
import ReportReviewButton from '../../components/business/ReportReviewButton'
import RatingSummary from '../../components/business/RatingSummary'

const RATINGS = ['', '5', '4', '3', '2', '1']

const SORTS = [
  { value: 'newest', label: 'Newest first' },
  { value: 'oldest', label: 'Oldest first' },
  { value: 'highest', label: 'Highest rated' },
  { value: 'lowest', label: 'Lowest rated' },
]

function Figure({ icon: Icon, label, value, note }) {
  return (
    <div className="flex items-start gap-3">
      <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gray-50 text-gray-500">
        <Icon size={16} aria-hidden="true" />
      </span>
      <div className="min-w-0">
        <p className="text-xl font-heading font-bold text-gray-900 leading-tight tabular-nums">{value}</p>
        <p className="text-sm text-gray-500">{label}</p>
        {note && <p className="text-xs text-gray-400 mt-0.5">{note}</p>}
      </div>
    </div>
  )
}

/**
 * Business Owner portal: what guests say about the owner's listing(s), the way review sites
 * show it to a business: the rating and its star breakdown, a few figures, and every review
 * with filters (stars, responded or not, search) and sorting. Responding is optional.
 */
export default function GuestReviews({ portalLabel }) {
  const [rating, setRating] = useState('')
  const [filter, setFilter] = useState('all')
  const [sort, setSort] = useState('newest')
  const [searchInput, setSearchInput] = useState('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(false)
  // Only the newest request may show its results (filters can change while one is on its way).
  const latestRequest = useRef(0)

  const load = useCallback(() => {
    const request = ++latestRequest.current
    getOwnerReviews({ rating: rating || undefined, filter, sort, search: search || undefined, page })
      .then((result) => {
        if (request !== latestRequest.current) return
        setData(result)
        setError(false)
      })
      .catch(() => request === latestRequest.current && setError(true))
      .finally(() => request === latestRequest.current && setLoading(false))
  }, [rating, filter, sort, search, page])

  useEffect(() => {
    load()
  }, [load])

  // Search as the owner types, once they pause.
  useEffect(() => {
    const timer = setTimeout(() => {
      if (searchInput.trim() !== search) {
        setLoading(true)
        setSearch(searchInput.trim())
        setPage(1)
      }
    }, 400)
    return () => clearTimeout(timer)
  }, [searchInput, search])

  const update = (setter) => (value) => {
    setLoading(true)
    setter(value)
    setPage(1)
  }

  const filtered = rating || filter !== 'all' || search
  const clearFilters = () => {
    setLoading(true)
    setRating('')
    setFilter('all')
    setSearchInput('')
    setSearch('')
    setPage(1)
  }

  const summary = data?.summary
  const reviews = data?.data || []
  // Only label each review with its listing when the owner has more than one.
  const showListing = new Set(reviews.map((r) => `${r.rateable_type}:${r.rateable_id}`)).size > 1

  return (
    <div>
      <div className="mb-6">
        <p className="text-xs font-semibold uppercase tracking-wider text-secondary-500 mb-2">{portalLabel}</p>
        <h1 className="text-2xl sm:text-3xl font-heading font-bold text-gray-900">Guest Reviews</h1>
        <p className="text-gray-600 mt-2">What tourists say about your listing. You can respond publicly to any review if you wish.</p>
      </div>

      {summary && (
        <div className="bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) p-5 sm:p-6 mb-6">
          <RatingSummary summary={summary} onSelect={update(setRating)} selected={rating} />
          {summary.total > 0 && (
            <div className="mt-6 pt-5 border-t border-gray-100 grid grid-cols-1 sm:grid-cols-3 gap-4">
              <Figure icon={FiTrendingUp} label="New in the last 30 days" value={summary.last_30_days.toLocaleString()} />
              <Figure
                icon={FiStar}
                label="Rated 4 stars or more"
                value={`${Math.round((((summary.by_rating?.[4] || 0) + (summary.by_rating?.[5] || 0)) / summary.total) * 100)}%`}
              />
              <Figure
                icon={FiMessageSquare}
                label="Response rate"
                value={`${summary.response_rate}%`}
                note={`${summary.replied.toLocaleString()} of ${summary.total.toLocaleString()} reviews have your response`}
              />
            </div>
          )}
        </div>
      )}

      <div className="bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) p-4 mb-4 space-y-4">
        <div className="flex flex-wrap gap-2" role="group" aria-label="Star rating">
          {RATINGS.map((value) => {
            const active = rating === value
            const count = value ? summary?.by_rating?.[value] : summary?.total
            return (
              <button
                key={value || 'all'}
                type="button"
                aria-pressed={active}
                onClick={() => update(setRating)(value)}
                className={`inline-flex items-center gap-1 rounded-full border px-3.5 py-1.5 text-sm font-medium transition-colors ${active ? 'border-primary-600 bg-primary-600 text-white' : 'border-gray-200 bg-white text-gray-700 hover:border-primary-300'}`}
              >
                {value ? <>{value} <FiStar size={13} className={active ? 'fill-white' : 'fill-accent-500 text-accent-500'} aria-hidden="true" /></> : 'All ratings'}
                {count !== undefined && <span className={active ? 'text-primary-100' : 'text-gray-400'}>({count})</span>}
              </button>
            )
          })}
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-12 gap-3">
          <label className="text-sm sm:col-span-2 lg:col-span-6">
            <span className="block font-medium text-gray-700 mb-1">Search</span>
            <span className="relative block">
              <FiSearch className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" aria-hidden="true" />
              <input
                type="search"
                value={searchInput}
                onChange={(e) => setSearchInput(e.target.value)}
                placeholder="Guest name or review text"
                className="input-field pl-9"
              />
            </span>
          </label>
          <label className="text-sm lg:col-span-3">
            <span className="block font-medium text-gray-700 mb-1">Your response</span>
            <select value={filter} onChange={(e) => update(setFilter)(e.target.value)} className="input-field">
              <option value="all">Any</option>
              <option value="replied">Responded</option>
              <option value="unreplied">Not responded</option>
            </select>
          </label>
          <label className="text-sm lg:col-span-3">
            <span className="block font-medium text-gray-700 mb-1">Sort by</span>
            <select value={sort} onChange={(e) => update(setSort)(e.target.value)} className="input-field">
              {SORTS.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
            </select>
          </label>
        </div>

        {filtered && (
          <button type="button" onClick={clearFilters} className="inline-flex items-center gap-1 text-sm font-semibold text-primary-700 hover:text-primary-800">
            <FiX size={14} aria-hidden="true" /> Clear filters
          </button>
        )}
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
          {summary?.total ? 'No reviews match these filters.' : 'No guest reviews yet. Reviews from tourists will appear here.'}
        </div>
      ) : (
        <>
          <p className="text-sm text-gray-500 mb-3">
            {filtered ? `${data.total.toLocaleString()} of ${summary.total.toLocaleString()} reviews` : `${data.total.toLocaleString()} review${data.total === 1 ? '' : 's'}`}
          </p>
          <ul className="space-y-4">
            {reviews.map((review) => {
              const { comment } = parseReview(review)
              const name = review.user?.name || 'Guest'
              return (
                <li key={review.id} className="bg-white rounded-2xl border border-gray-200/70 p-5 sm:p-6 shadow-(--shadow-card)">
                  <div className="flex items-start justify-between gap-3">
                    <div className="flex min-w-0 items-start gap-3">
                      <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary-50 font-heading font-semibold text-primary-700" aria-hidden="true">
                        {name.charAt(0).toUpperCase()}
                      </span>
                      <div className="min-w-0">
                        <p className="font-medium text-gray-900">{name}</p>
                        <div className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1">
                          <StarDisplay rating={review.score} size={13} />
                          <span className="text-xs text-gray-500">
                            {formatShortDate(review.created_at)}
                            {showListing && review.rateable?.name && <> · {review.rateable.name}</>}
                          </span>
                        </div>
                      </div>
                    </div>
                    <div className="flex flex-col items-end gap-1.5 shrink-0">
                      {review.reply && (
                        <span className="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">
                          <FiCheck size={11} aria-hidden="true" /> Responded
                        </span>
                      )}
                      {review.hidden_at && (
                        <span className="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">Hidden by the Tourism Office</span>
                      )}
                      <ReportReviewButton review={review} onReported={load} />
                    </div>
                  </div>
                  <p className="mt-3 text-sm text-gray-700 leading-relaxed">{comment || <span className="italic text-gray-400">No written comment.</span>}</p>

                  <OwnerReply review={review} canReply onSaved={load} />
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
