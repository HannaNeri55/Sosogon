// frontend/src/components/account/StatusPill.jsx
import { ACCOUNT_STATES } from '../../utils/accountStates'

/** Coloured pill for a value from one of the maps in utils/accountStates.js. */
export default function StatusPill({ value, map = ACCOUNT_STATES, withDot = false, className = '' }) {
  const meta = map[value] || { label: value, className: 'bg-gray-100 text-gray-600' }

  return (
    <span
      className={`inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border border-transparent px-2.5 py-1 text-xs font-semibold ${meta.className} ${className}`}
    >
      {withDot && meta.dot && <span className={`h-1.5 w-1.5 rounded-full ${meta.dot}`} aria-hidden="true" />}
      {meta.label}
    </span>
  )
}
