import { FiCheckCircle, FiClock, FiInfo } from 'react-icons/fi'

const dateOf = (iso) => new Date(iso).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })

// How each part's state reads to visitors: plain and calm, never like an error.
function line(aspect) {
  switch (aspect.state) {
    case 'verified':
      return { icon: FiCheckCircle, tone: 'text-primary-600', text: `Last verified ${dateOf(aspect.verified_at)}` }
    case 'overdue':
      return { icon: FiClock, tone: 'text-gray-400', text: `Last verified ${dateOf(aspect.verified_at)}`, hint: 'may have changed since' }
    case 'changed':
      return { icon: FiInfo, tone: 'text-gray-400', text: 'Updated since last verified' }
    default:
      return { icon: FiInfo, tone: 'text-gray-300', text: 'Not yet verified' }
  }
}

/**
 * Under a place's facts card: when the Tourism Office last checked each part of its information
 * (price, location, hours, contact), from the detail API's `verification`. A part never checked
 * reads "Not yet verified"; when nothing has been checked yet, one quiet line says so. Dates come
 * only from real checks by the office, never from edits.
 */
export default function VerificationInfo({ verification }) {
  const aspects = verification?.aspects || []
  if (aspects.length === 0) return null

  if (aspects.every((aspect) => aspect.state === 'not_verified')) {
    return (
      <p className="mt-3 flex items-start gap-2 px-1 text-sm text-gray-500">
        <FiInfo size={14} className="mt-0.5 shrink-0 text-gray-400" aria-hidden="true" />
        <span>Information not yet verified by the {verification.verified_by || 'Tourism Office'}. Please confirm details before you go.</span>
      </p>
    )
  }

  return (
    <section aria-labelledby="verification-heading" className="mt-4 rounded-2xl border border-gray-200/70 bg-white px-5 py-4">
      <h2 id="verification-heading" className="text-xs font-semibold uppercase tracking-wider text-gray-500">Information check</h2>
      <dl className="mt-2 divide-y divide-gray-100">
        {aspects.map((aspect) => {
          const { icon: Icon, tone, text, hint } = line(aspect)
          return (
            <div key={aspect.aspect} className="flex items-start gap-2.5 py-2">
              <Icon size={14} className={`mt-1 shrink-0 ${tone}`} aria-hidden="true" />
              <div className="min-w-0">
                <dt className="text-sm font-medium text-gray-800">{aspect.label}</dt>
                <dd className="text-sm text-gray-500">
                  {text}
                  {hint && <span className="text-gray-400"> · {hint}</span>}
                </dd>
              </div>
            </div>
          )
        })}
      </dl>
      <p className="mt-2 text-xs text-gray-400">Checked by the {verification.verified_by || 'Tourism Office'}. Details can change, so confirm before you go.</p>
    </section>
  )
}
