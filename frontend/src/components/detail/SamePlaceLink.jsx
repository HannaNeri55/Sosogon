import { Link } from 'react-router-dom'
import { FiArrowRight } from 'react-icons/fi'

/**
 * Under a place's facts card, for a resort listed both ways: the destination page points to its
 * rooms ("You can stay here too") and the accommodation page to its day-use details ("Day visitors
 * welcome"). Renders nothing when the place has no linked listing.
 */
export default function SamePlaceLink({ to, title, text }) {
  if (!to) return null

  return (
    <Link
      to={to}
      className="mt-4 flex items-center gap-3 rounded-xl border border-primary-100 bg-primary-50/60 px-4 py-3 text-sm transition-colors hover:border-primary-300 hover:bg-primary-50"
    >
      <span className="min-w-0 flex-1">
        <span className="block font-semibold text-primary-800">{title}</span>
        <span className="block text-gray-600">{text}</span>
      </span>
      <FiArrowRight size={18} className="shrink-0 text-primary-700" aria-hidden="true" />
    </Link>
  )
}
