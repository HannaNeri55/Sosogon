import { FiLock } from 'react-icons/fi'

/**
 * Shown in place of a record's actions when this admin can't change it: the office that does
 * (the record's managed_by, e.g. "Bulan Tourism Office"), else the provincial administrator
 * (province-wide records, for a municipal admin).
 */
export default function ManagedBy({ office, className = '' }) {
  return (
    <p className={`flex w-fit max-w-xs items-start gap-1.5 text-xs text-gray-500 ${className}`}>
      <FiLock size={12} className="mt-0.5 shrink-0" aria-hidden="true" />
      <span>
        Managed by {office || 'the provincial administrator'} <span className="whitespace-nowrap">· view only</span>
      </span>
    </p>
  )
}
