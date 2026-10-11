import { useEffect, useState } from 'react'
import { FiAlertTriangle } from 'react-icons/fi'
import { getBusinessDashboard } from '../../api/business'
import { daysUntil, formatDate } from '../../utils/helpers'

/** Shows the admin's negative-review warning(s) at the top of the owner portal. */
export default function ListingWarningBanner() {
  const [warnings, setWarnings] = useState([])

  useEffect(() => {
    getBusinessDashboard()
      .then((data) => setWarnings(data.warnings || []))
      .catch(() => {})
  }, [])

  if (warnings.length === 0) return null

  return (
    <div className="space-y-3 mb-6">
      {warnings.map((w) => {
        const left = daysUntil(w.warning_deadline)
        return (
          <div key={w.id} role="alert" className="flex gap-3 p-4 bg-amber-50 border border-amber-200 rounded-xl text-amber-900">
            <FiAlertTriangle size={20} className="text-amber-600 shrink-0 mt-0.5" />
            <div className="text-sm">
              <p className="font-semibold">
                Notice for {w.business_name}:{' '}
                {left > 0 ? `${left} day${left === 1 ? '' : 's'} remaining` : 'notice period ended'}
              </p>
              <p className="mt-1">
                Your business will be removed from the system if negative reviews continue. Please review recent
                visitor feedback and address the concerns before {formatDate(w.warning_deadline)}.
              </p>
              {w.warning_notes && (
                <p className="mt-2">
                  <span className="font-medium">Note from the administrator:</span> {w.warning_notes}
                </p>
              )}
            </div>
          </div>
        )
      })}
    </div>
  )
}
