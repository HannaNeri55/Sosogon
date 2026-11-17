import { Link } from 'react-router-dom'
import { FiFlag } from 'react-icons/fi'

/**
 * Under a place's facts card: a quiet link to Feedback & Concerns with this place already filled
 * in, for prices, hours or locations that are wrong or out of date. `type` is the listing type the
 * feedback form knows: destination, accommodation, food_beverage or retail_shop.
 */
export default function ReportInfoLink({ type, id, name }) {
  const params = new URLSearchParams({ type, id: String(id), name: name || '' })

  return (
    <p className="mt-3 flex items-start gap-2 px-1 text-sm text-gray-500">
      <FiFlag size={14} className="mt-0.5 shrink-0" aria-hidden="true" />
      <span>
        Something wrong or out of date?{' '}
        <Link to={`/feedback?${params}`} className="font-medium text-primary-700 hover:text-primary-800 underline-offset-2 hover:underline">
          Report incorrect information
        </Link>
      </span>
    </p>
  )
}
