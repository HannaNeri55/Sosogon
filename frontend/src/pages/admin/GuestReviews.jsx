// frontend/src/pages/admin/GuestReviews.jsx
import { useCallback, useEffect, useRef, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { FiAlertTriangle, FiEyeOff, FiFlag, FiMessageSquare, FiSearch, FiStar, FiX } from 'react-icons/fi'
import { getAdminGuestReviews, getReviewReports } from '../../api/admin'
import Pagination from '../../components/common/Pagination'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import ReviewContent, { Badge } from '../../components/admin/reviews/ReviewContent'
import { LISTING_TYPES, REPORT_STATUS } from '../../components/admin/reviews/reviewLabels'
import ReviewReportsPanel from '../../components/admin/reviews/ReviewReportsPanel'

const RATINGS = ['', '5', '4', '3', '2', '1']

function StatCard({ icon: Icon, label, value, note, tone = 'primary' }) {
  const tones = {
    primary: 'bg-primary-50 text-primary-700',
    amber: 'bg-amber-50 text-amber-600',
    red: 'bg-red-50 text-red-600',
    sky: 'bg-sky-50 text-sky-700',
  }
  return (
    <div className="bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) p-4 sm:p-5">
      <div className="flex items-center gap-3">
        <span className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ${tones[tone]}`}>
          <Icon size={18} aria-hidden="true" />
        </span>
        <div className="min-w-0">
          <p className="text-2xl font-heading font-bold text-gray-900 leading-tight">{value}</p>
          <p className="text-xs sm:text-sm text-gray-500">{label}</p>
        </div>
      </div>
      {note && <p className="mt-2 text-xs text-gray-400">{note}</p>}
    </div>
  )
}

/**
 * Every guest review and owner response across all listings, with a summary of the selected
 * reviews, filters (listing type, rating, owner response, search, dates), and the queue of
 * reviews that owners reported. Reviews are never edited or deleted here: a reported review can
 * only be hidden from the public, and restored again.
 */
export default function GuestReviews() {
  const [params, setParams] = useSearchParams()
  const tab = params.get('tab') === 'reports' ? 'reports' : 'reviews'

  const [type, setType] = useState('')
  const [rating, setRating] = useState('')
  const [filter, setFilter] = useState('all')
  const [searchInput, setSearchInput] = useState('')
  const [search, setSearch] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [page, setPage] = useState(1)
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(false)
  const [pendingReports, setPendingReports] = useState(0)
  // Only the newest request may show its results: an older one that answers late (filters
  // changed in the meantime) is ignored instead of replacing the list with stale reviews.
  const latestRequest = useRef(0)
  const dateError = from && to && from > to ? 'The end date must be on or after the start date.' : null

  const load = useCallback(() => {
    const request = ++latestRequest.current
    // An impossible range isn't sent; the page explains it instead (and any older request is ignored).
    if (dateError) return
    getAdminGuestReviews({
      type: type || undefined, rating: rating || undefined, filter, search: search || undefined,
      from: from || undefined, to: to || undefined, page,
    })
      .then((result) => {
        if (request !== latestRequest.current) return
        setData(result)
        setError(false)
      })
      .catch(() => request === latestRequest.current && setError(true))
      .finally(() => request === latestRequest.current && setLoading(false))
  }, [type, rating, filter, search, from, to, page, dateError])

  const loadPending = useCallback(() => {
    getReviewReports({ status: 'pending' }).then((result) => setPendingReports(result.counts?.pending ?? 0)).catch(() => {})
  }, [])

  useEffect(() => {
    if (tab === 'reviews') load()
  }, [load, tab])

  useEffect(() => {
    loadPending()
  }, [loadPending])

  // Search as the admin types, once they pause.
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

  const filtered = type || rating || filter !== 'all' || search || from || to
  const clearFilters = () => {
    setLoading(true)
    setType('')
    setRating('')
    setFilter('all')
    setSearchInput('')
    setSearch('')
    setFrom('')
    setTo('')
    setPage(1)
  }

  const showTab = (next) => setParams(next === 'reports' ? { tab: 'reports' } : {}, { replace: true })

  const summary = data?.summary
  const counts = data?.counts || {}
  const totalAll = Object.values(counts).reduce((sum, n) => sum + n, 0)
  const reviews = data?.data || []
  const percent = (n) => (summary?.total ? `${Math.round((n / summary.total) * 100)}% of reviews` : null)

  return (
    <div>
      <div className="mb-6">
        <p className="text-primary-600 font-medium mb-2">Admin</p>
        <h1 className="text-2xl sm:text-3xl font-heading font-bold text-gray-900">Guest Reviews</h1>
        <p className="text-gray-600 mt-2">
          Ratings and reviews from tourists across every listing, the business owners&apos; responses, and reviews owners have reported.
        </p>
      </div>

      <div className="mb-6 flex gap-1 border-b border-gray-200" role="tablist" aria-label="Guest reviews">
        {[
          { key: 'reviews', label: 'All reviews' },
          { key: 'reports', label: 'Reported reviews', count: pendingReports },
        ].map(({ key, label, count }) => (
          <button
            key={key}
            type="button"
            role="tab"
            aria-selected={tab === key}
            onClick={() => showTab(key)}
            className={`-mb-px border-b-2 px-4 py-2.5 text-sm font-semibold transition-colors ${tab === key ? 'border-primary-600 text-primary-700' : 'border-transparent text-gray-500 hover:text-gray-800'}`}
          >
            {label}
            {count > 0 && <span className="ml-2 rounded-full bg-red-500 px-2 py-0.5 text-xs text-white">{count}</span>}
          </button>
        ))}
      </div>

      {tab === 'reports' ? (
        <ReviewReportsPanel onChange={loadPending} />
      ) : (
        <>
          {summary && (
            <>
              <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
                <StatCard icon={FiMessageSquare} label="Total reviews" value={summary.total.toLocaleString()} />
                <StatCard icon={FiStar} label="Average rating" value={summary.total ? `${summary.average.toFixed(1)} / 5` : '—'} tone="amber" />
                <StatCard icon={FiAlertTriangle} label="Negative reviews (1–2 stars)" value={summary.negative.toLocaleString()} note={percent(summary.negative)} tone="red" />
                <StatCard icon={FiMessageSquare} label="Without an owner response" value={summary.unreplied.toLocaleString()} note={percent(summary.unreplied)} tone="sky" />
              </div>
              <p className="mt-2 mb-5 text-xs text-gray-400">
                {filtered ? 'Figures match all the filters below.' : 'Figures cover all reviews.'}
              </p>
            </>
          )}

          <div className="bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) p-4 mb-4 space-y-4">
            <div className="flex flex-wrap gap-2" role="group" aria-label="Rating">
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
                    {value ? <>{value} <FiStar size={13} className={active ? 'fill-white' : 'fill-amber-400 text-amber-400'} aria-hidden="true" /></> : 'All ratings'}
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
                    placeholder="Listing, reviewer or review text"
                    className="input-field pl-9"
                  />
                </span>
              </label>
              <label className="text-sm lg:col-span-3">
                <span className="block font-medium text-gray-700 mb-1">Listing type</span>
                <select value={type} onChange={(e) => update(setType)(e.target.value)} className="input-field">
                  {LISTING_TYPES.map((option) => (
                    <option key={option.value} value={option.value}>
                      {option.label} ({option.value ? counts[option.value] ?? 0 : totalAll})
                    </option>
                  ))}
                </select>
              </label>
              <label className="text-sm lg:col-span-3">
                <span className="block font-medium text-gray-700 mb-1">Owner response</span>
                <select value={filter} onChange={(e) => update(setFilter)(e.target.value)} className="input-field">
                  <option value="all">Any</option>
                  <option value="replied">Replied</option>
                  <option value="unreplied">Not replied</option>
                </select>
              </label>
              {/* Its own wide row, so both full dates (mm/dd/yyyy) always fit. */}
              <div className="text-sm sm:col-span-2 lg:col-span-6">
                <span className="block font-medium text-gray-700 mb-1">Submitted</span>
                <span className="grid grid-cols-[1fr_auto_1fr] items-center gap-2">
                  <input type="date" value={from} max={to || undefined} onChange={(e) => update(setFrom)(e.target.value)} className="input-field w-full min-w-0" aria-label="From date" />
                  <span className="text-gray-400">to</span>
                  <input type="date" value={to} min={from || undefined} onChange={(e) => update(setTo)(e.target.value)} className="input-field w-full min-w-0" aria-label="To date" aria-invalid={!!dateError} />
                </span>
                {dateError && <span className="mt-1 block text-xs text-red-600" role="alert">{dateError}</span>}
              </div>
            </div>

            {filtered && (
              <button type="button" onClick={clearFilters} className="inline-flex items-center gap-1 text-sm font-semibold text-primary-700 hover:text-primary-800">
                <FiX size={14} aria-hidden="true" /> Clear filters
              </button>
            )}
          </div>

          {dateError ? (
            <div className="bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) p-8 text-center text-gray-500">
              Choose an end date on or after the start date to see reviews.
            </div>
          ) : loading ? (
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
              <p className="text-sm text-gray-500 mb-3">{data.total.toLocaleString()} review{data.total === 1 ? '' : 's'}</p>
              <ul className="space-y-4">
                {reviews.map((review) => {
                  const report = review.latest_report
                  return (
                    <li key={review.id} className="bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) p-5">
                      <ReviewContent
                        review={review}
                        aside={
                          <>
                            {review.reply && <Badge className="border-emerald-200 bg-emerald-50 text-emerald-700">Replied</Badge>}
                            {report && (
                              <button type="button" onClick={() => showTab('reports')} className="inline-flex" title="Open reported reviews">
                                <Badge className={REPORT_STATUS[report.status]?.className}>
                                  <FiFlag size={11} aria-hidden="true" /> Reported · {REPORT_STATUS[report.status]?.label}
                                </Badge>
                              </button>
                            )}
                            {review.hidden_at && (
                              <Badge className="border-red-200 bg-red-50 text-red-700"><FiEyeOff size={11} aria-hidden="true" /> Hidden from public</Badge>
                            )}
                          </>
                        }
                      />
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
        </>
      )}
    </div>
  )
}
