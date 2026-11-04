import { FiAlertTriangle } from 'react-icons/fi'

// Marks demonstration listings (is_sample) so they can't pass as verified businesses.
export default function SampleBadge({ className = '' }) {
  return (
    <span
      className={`inline-flex items-center gap-1 text-xs font-semibold text-amber-800 bg-amber-100 border border-amber-300 px-2 py-0.5 rounded ${className}`}
      title="Demonstration data — not a verified business"
    >
      <FiAlertTriangle size={11} aria-hidden="true" />
      Sample listing
      <span className="sr-only"> — demonstration data, not a verified business</span>
    </span>
  )
}

// Detail-page notice under the title of a sample listing.
export function SampleNotice() {
  return (
    <div className="mt-3 flex flex-wrap items-center gap-2 text-sm text-amber-900 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
      <SampleBadge />
      <span>This is demonstration data. The business details have not been verified.</span>
    </div>
  )
}
