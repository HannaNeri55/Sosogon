import { Link } from 'react-router-dom'
import { FiCornerDownRight, FiExternalLink } from 'react-icons/fi'
import { StarDisplay } from '../../common/StarRating'
import { formatShortDate, parseReview } from '../../../utils/helpers'
import { TYPE_BY_VALUE } from './reviewLabels'

export function Badge({ className, children }) {
  return <span className={`inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs font-semibold ${className}`}>{children}</span>
}

/**
 * One guest review as the Tourism Office sees it: the listing (type and name), the stars, who
 * wrote it and when, what they wrote, and the business owner's response set apart below it.
 * `aside` adds badges next to the reviewer line (response status, report status).
 */
export default function ReviewContent({ review, aside = null }) {
  const { comment } = parseReview(review)
  const type = TYPE_BY_VALUE[review.listing_type]

  return (
    <div>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="text-xs font-semibold uppercase tracking-wider text-gray-500">{type?.label || 'Listing'}</p>
          {review.rateable && type?.path ? (
            <Link to={`${type.path}/${review.rateable_id}`} className="inline-flex items-center gap-1 font-heading font-semibold text-gray-900 hover:text-primary-600">
              {review.rateable.name}
              <FiExternalLink size={13} aria-hidden="true" />
            </Link>
          ) : (
            <p className={`font-heading font-semibold ${review.rateable ? 'text-gray-900' : 'text-gray-400'}`}>{review.rateable?.name || 'Listing removed'}</p>
          )}
        </div>
        <div className="flex items-center gap-2">
          <StarDisplay rating={review.score} size={15} />
          <span className="text-sm font-semibold text-gray-700">{review.score}.0</span>
        </div>
      </div>

      <div className="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1.5 text-sm">
        <span className="font-medium text-gray-900">{review.user?.name || 'Unknown user'}</span>
        <span className="text-gray-400">· {formatShortDate(review.created_at)}</span>
        {aside}
      </div>

      {comment ? (
        <p className="mt-2 text-sm leading-relaxed text-gray-700 whitespace-pre-line">{comment}</p>
      ) : (
        <p className="mt-2 text-sm italic text-gray-400">Rating only, no written review.</p>
      )}

      {review.reply && (
        <div className="mt-4 rounded-xl border border-primary-100 bg-primary-50/60 px-4 py-3">
          <p className="flex flex-wrap items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-primary-700">
            <FiCornerDownRight size={14} aria-hidden="true" />
            Business owner response
            <span className="font-normal normal-case tracking-normal text-gray-500">
              {review.reply.user?.name ? `by ${review.reply.user.name} · ` : ''}{formatShortDate(review.reply.created_at)}
            </span>
          </p>
          <p className="mt-1.5 text-sm text-gray-700 whitespace-pre-line">{review.reply.body}</p>
        </div>
      )}
    </div>
  )
}
