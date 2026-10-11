import { useSearchParams } from 'react-router-dom'
import { FiClock } from 'react-icons/fi'

// Shown on the login pages when the previous session ended on its own (?expired=1).
export default function SessionExpiredNotice() {
  const [searchParams] = useSearchParams()
  if (searchParams.get('expired') !== '1') return null

  return (
    <div className="flex items-start gap-2 mb-5 p-3 rounded-lg bg-amber-50 border border-amber-200 text-sm text-amber-800">
      <FiClock size={16} className="shrink-0 mt-0.5" />
      <p>Your session expired. Please log in again.</p>
    </div>
  )
}
