import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { FiArrowDownRight, FiArrowRight, FiArrowUpRight, FiEye, FiMap, FiMessageSquare, FiStar } from 'react-icons/fi'
import { getBusinessInsights } from '../../api/business'
import { formatShortDate, parseReview } from '../../utils/helpers'
import { StarDisplay } from '../common/StarRating'
import RatingSummary from './RatingSummary'

const shortDay = (date) => new Date(`${date}T00:00:00`).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })

function Tile({ icon: Icon, label, value, children }) {
  return (
    <div className="bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) p-4 sm:p-5">
      <p className="flex items-center gap-1.5 text-sm font-medium text-gray-500">
        <Icon size={15} aria-hidden="true" /> {label}
      </p>
      <p className="mt-2 text-2xl sm:text-3xl font-heading font-bold text-gray-900 tabular-nums leading-tight">{value}</p>
      {children && <div className="mt-1 text-xs text-gray-500">{children}</div>}
    </div>
  )
}

/** Up or down against the 30 days before, or nothing to compare with. */
function Change({ now, before }) {
  if (!before) return <span>{now ? 'No views in the 30 days before' : 'No views yet'}</span>
  const percent = Math.round(((now - before) / before) * 100)
  if (percent === 0) return <span>Same as the 30 days before</span>
  const up = percent > 0
  return (
    <span className={`inline-flex items-center gap-0.5 font-medium ${up ? 'text-secondary-600' : 'text-red-600'}`}>
      {up ? <FiArrowUpRight size={13} aria-hidden="true" /> : <FiArrowDownRight size={13} aria-hidden="true" />}
      {Math.abs(percent)}% vs the 30 days before
    </span>
  )
}

/** Daily page views for the last 30 days, as bars (the busiest day sets the scale). */
function ViewsChart({ daily }) {
  const max = Math.max(1, ...daily.map((d) => d.views))
  return (
    <figure>
      <div className="flex h-32 items-end gap-[3px]" role="img" aria-label={`Page views per day, ${shortDay(daily[0].date)} to ${shortDay(daily[daily.length - 1].date)}`}>
        {daily.map((day) => (
          <div key={day.date} className="group relative flex h-full flex-1 items-end">
            <div
              className={`w-full rounded-t ${day.views ? 'bg-primary-400 group-hover:bg-primary-600' : 'bg-gray-100'}`}
              style={{ height: day.views ? `${Math.max(6, (day.views / max) * 100)}%` : '4px' }}
              title={`${shortDay(day.date)}: ${day.views} view${day.views === 1 ? '' : 's'}`}
            />
          </div>
        ))}
      </div>
      <figcaption className="mt-2 flex justify-between text-xs text-gray-400">
        <span>{shortDay(daily[0].date)}</span>
        <span>{shortDay(daily[daily.length - 1].date)}</span>
      </figcaption>
    </figure>
  )
}

/**
 * The owner overview's performance section: how many people viewed the listing's page, how
 * guests rate it, how often it's in tourists' itineraries, and the latest reviews. Real counts
 * only (page views are counted from the public listing page).
 */
export default function ListingInsights({ type, reviewsPath }) {
  const [data, setData] = useState(null)
  const [failed, setFailed] = useState(false)

  useEffect(() => {
    getBusinessInsights(type).then(setData).catch(() => setFailed(true))
  }, [type])

  if (failed) return null
  if (!data) {
    return (
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8" aria-hidden="true">
        {[0, 1, 2].map((i) => <div key={i} className="skeleton h-28 rounded-2xl" />)}
      </div>
    )
  }

  const { views, reviews, itineraries } = data

  return (
    <section aria-labelledby="performance-heading" className="mb-8 space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-2">
        <h2 id="performance-heading" className="text-xl font-heading font-semibold text-gray-900">Performance</h2>
        <p className="text-sm text-gray-500">Last 30 days</p>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <Tile icon={FiEye} label="Page views" value={views.last_30_days.toLocaleString()}>
          <Change now={views.last_30_days} before={views.previous_30_days} />
        </Tile>
        <Tile icon={FiStar} label="Guest rating" value={reviews.total ? `${reviews.average.toFixed(1)} / 5` : '—'}>
          {reviews.total ? `${reviews.total.toLocaleString()} reviews · ${reviews.last_30_days.toLocaleString()} new` : 'No reviews yet'}
        </Tile>
        <Tile icon={FiMap} label="In tourists' itineraries" value={itineraries.total.toLocaleString()}>
          {itineraries.last_30_days.toLocaleString()} added in the last 30 days
        </Tile>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-5 gap-4">
        <div className="lg:col-span-3 bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) p-4 sm:p-6">
          <div className="flex flex-wrap items-baseline justify-between gap-2 mb-4">
            <h3 className="font-heading font-semibold text-gray-900">Page views per day</h3>
            <p className="text-xs text-gray-500">{views.all_time.toLocaleString()} views since listing</p>
          </div>
          <ViewsChart daily={views.daily} />
        </div>

        <div className="lg:col-span-2 bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) p-4 sm:p-6">
          <div className="flex flex-wrap items-baseline justify-between gap-2 mb-4">
            <h3 className="font-heading font-semibold text-gray-900">Guest rating</h3>
            {reviews.total > 0 && <p className="text-xs text-gray-500">Response rate {reviews.response_rate}%</p>}
          </div>
          <RatingSummary summary={reviews} />
        </div>
      </div>

      <div className="bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) p-4 sm:p-6">
        <div className="flex flex-wrap items-baseline justify-between gap-2 mb-3">
          <h3 className="font-heading font-semibold text-gray-900">Latest reviews</h3>
          <Link to={reviewsPath} className="inline-flex items-center gap-1 text-sm font-medium text-primary-600 hover:text-primary-700">
            All reviews <FiArrowRight size={14} aria-hidden="true" />
          </Link>
        </div>
        {reviews.recent.length === 0 ? (
          <p className="py-4 text-sm text-gray-500">No reviews yet. Reviews from tourists will appear here.</p>
        ) : (
          <ul className="divide-y divide-gray-100">
            {reviews.recent.map((review) => (
              <li key={review.id} className="py-3 first:pt-0 last:pb-0">
                <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                  <p className="text-sm font-medium text-gray-900">{review.user?.name || 'Guest'}</p>
                  <StarDisplay rating={review.score} size={12} />
                  <span className="text-xs text-gray-400">{formatShortDate(review.created_at)}</span>
                  {review.reply && (
                    <span className="inline-flex items-center gap-1 text-xs text-gray-500"><FiMessageSquare size={11} aria-hidden="true" /> Responded</span>
                  )}
                </div>
                <p className="mt-1 text-sm text-gray-600 line-clamp-2">{parseReview(review).comment || 'No written comment.'}</p>
              </li>
            ))}
          </ul>
        )}
      </div>
    </section>
  )
}
