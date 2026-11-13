import { FiStar } from 'react-icons/fi'
import { StarDisplay } from '../common/StarRating'

/**
 * A listing's rating at a glance, the way review sites show it: the average with its stars and
 * review count, and a bar per star rating with how many reviews gave it. With `onSelect`, each
 * bar filters the reviews to that rating (`selected` marks the active one).
 */
export default function RatingSummary({ summary, onSelect = null, selected = '' }) {
  const total = summary?.total || 0
  const counts = summary?.by_rating || {}

  return (
    <div className="grid grid-cols-1 sm:grid-cols-[auto_1fr] gap-5 sm:gap-8 items-center">
      <div className="text-center sm:text-left sm:pr-8 sm:border-r sm:border-gray-100">
        <p className="text-5xl font-heading font-bold text-gray-900 tabular-nums leading-none">{total ? summary.average.toFixed(1) : '—'}</p>
        <div className="mt-2 flex justify-center sm:justify-start">
          <StarDisplay rating={total ? summary.average : 0} size={16} />
        </div>
        <p className="mt-1.5 text-sm text-gray-500">
          {total.toLocaleString()} review{total === 1 ? '' : 's'}
        </p>
      </div>

      <ul className="space-y-1.5" aria-label="Reviews by star rating">
        {[5, 4, 3, 2, 1].map((star) => {
          const count = counts[star] || 0
          const width = total ? Math.round((count / total) * 100) : 0
          const active = String(selected) === String(star)
          const row = (
            <>
              <span className="w-8 shrink-0 inline-flex items-center gap-0.5 text-sm font-medium text-gray-700 tabular-nums">
                {star} <FiStar size={12} className="fill-accent-500 text-accent-500" aria-hidden="true" />
              </span>
              <span className="relative h-2.5 flex-1 overflow-hidden rounded-full bg-gray-100">
                <span className={`absolute inset-y-0 left-0 rounded-full ${active ? 'bg-primary-500' : 'bg-accent-400'}`} style={{ width: `${width}%` }} />
              </span>
              <span className="w-14 shrink-0 text-right text-sm text-gray-500 tabular-nums">
                {count.toLocaleString()}
                <span className="sr-only"> reviews with {star} stars</span>
              </span>
            </>
          )
          return (
            <li key={star}>
              {onSelect ? (
                <button
                  type="button"
                  onClick={() => onSelect(active ? '' : String(star))}
                  aria-pressed={active}
                  className={`flex w-full items-center gap-3 rounded-lg px-2 py-1 transition-colors ${active ? 'bg-primary-50' : 'hover:bg-gray-50'}`}
                >
                  {row}
                </button>
              ) : (
                <div className="flex items-center gap-3 px-2 py-1">{row}</div>
              )}
            </li>
          )
        })}
      </ul>
    </div>
  )
}
