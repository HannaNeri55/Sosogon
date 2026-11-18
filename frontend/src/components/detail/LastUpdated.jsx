import { FiClock } from 'react-icons/fi'

const NOTICES = {
  establishment: 'Information provided by the establishment. Prices and availability may change. Please contact the establishment directly for confirmation.',
  tourismOffice: 'Information provided by the Tourism Office. Fees and schedules may change. Please confirm before you go.',
}

/**
 * Under a place's facts card: when its public information was last published or changed
 * (the API's info_updated_at), and who provides it. A listing with no recorded date yet (one
 * published before dates were kept) shows only the notice: no date is ever guessed.
 */
export default function LastUpdated({ date, source = 'establishment' }) {
  const shown = date ? new Date(date) : null

  return (
    <div className="mt-3 space-y-1 px-1 text-sm text-gray-500">
      {shown && !Number.isNaN(shown.getTime()) && (
        <p className="flex items-center gap-2 font-medium text-gray-600">
          <FiClock size={14} className="shrink-0" aria-hidden="true" />
          Last Updated: {shown.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })}
        </p>
      )}
      <p className="text-xs leading-relaxed text-gray-400">{NOTICES[source]}</p>
    </div>
  )
}
