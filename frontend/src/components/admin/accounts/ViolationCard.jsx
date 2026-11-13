// frontend/src/components/admin/accounts/ViolationCard.jsx
import { FiPaperclip } from 'react-icons/fi'
import StatusPill from '../../account/StatusPill'
import { downloadViolationEvidence } from '../../../api/admin'
import { openPrivateFile } from '../../../utils/privateFile'
import { APPEAL_STATUSES, ENFORCEMENT_ACTIONS, VIOLATION_STATUSES, formatDateTime, formatDay } from '../../../utils/accountStates'

/** One enforcement record as admins see it: case details, evidence, reviewer, outcome and appeal. */
export default function ViolationCard({ violation, showUser = false }) {
  const appeal = violation.latest_appeal

  return (
    <article className="rounded-xl border border-gray-200 bg-white p-4 sm:p-5">
      <div className="flex flex-wrap items-center gap-2">
        <StatusPill value={violation.action} map={ENFORCEMENT_ACTIONS} />
        <StatusPill value={violation.status} map={VIOLATION_STATUSES} />
        {appeal && <StatusPill value={appeal.status} map={APPEAL_STATUSES} />}
        <span className="ml-auto text-xs text-gray-500">#{violation.id}</span>
      </div>

      {showUser && violation.user && (
        <p className="mt-3 text-sm text-gray-700">
          <span className="font-semibold text-gray-900">{violation.user.name}</span> · {violation.user.email}
          {violation.user.role?.name && <span className="text-gray-500"> · {violation.user.role.name.replace('_', ' ')}</span>}
        </p>
      )}

      <h3 className="mt-3 font-semibold text-gray-900">{violation.category_label}</h3>
      <p className="mt-1 text-sm text-gray-800"><span className="font-medium">Reason:</span> {violation.reason}</p>
      <p className="mt-1 text-sm text-gray-600 whitespace-pre-line">{violation.details}</p>

      {(violation.evidence_notes || violation.evidence_files?.length > 0) && (
        <div className="mt-3 rounded-lg bg-gray-50 p-3 text-sm">
          <p className="text-xs font-semibold uppercase tracking-wider text-gray-500">Evidence (internal)</p>
          {violation.evidence_notes && <p className="mt-1 text-gray-700 whitespace-pre-line">{violation.evidence_notes}</p>}
          {violation.evidence_files?.length > 0 && (
            <div className="mt-2 flex flex-wrap gap-2">
              {violation.evidence_files.map((file, index) => (
                <button
                  key={index}
                  type="button"
                  onClick={() => openPrivateFile(() => downloadViolationEvidence(violation.id, index), file.name)}
                  className="inline-flex items-center gap-1.5 rounded-full border border-gray-200 bg-white px-3 py-1 text-xs font-medium text-primary-700 hover:border-primary-300"
                >
                  <FiPaperclip size={12} aria-hidden="true" /> {file.name}
                </button>
              ))}
            </div>
          )}
        </div>
      )}

      <dl className="mt-3 grid grid-cols-1 gap-x-6 gap-y-1.5 text-xs text-gray-600 sm:grid-cols-2">
        <div><dt className="inline font-semibold text-gray-700">Reported: </dt><dd className="inline">{formatDay(violation.reported_at)}</dd></div>
        <div><dt className="inline font-semibold text-gray-700">Reviewed: </dt><dd className="inline">{formatDateTime(violation.reviewed_at)} by {violation.reviewer?.name || 'an administrator'}</dd></div>
        {violation.action === 'suspension' && (
          <div><dt className="inline font-semibold text-gray-700">Duration: </dt><dd className="inline">{violation.suspension_days} day{violation.suspension_days === 1 ? '' : 's'}, until {formatDateTime(violation.ends_at)}</dd></div>
        )}
        {violation.action === 'warning' && violation.ends_at && (
          <div><dt className="inline font-semibold text-gray-700">Warning shown until: </dt><dd className="inline">{formatDay(violation.ends_at)}</dd></div>
        )}
        {violation.review_date && <div><dt className="inline font-semibold text-gray-700">Review date: </dt><dd className="inline">{formatDay(violation.review_date)}</dd></div>}
        {violation.resolved_at && (
          <div className="sm:col-span-2">
            <dt className="inline font-semibold text-gray-700">Resolved: </dt>
            <dd className="inline">{formatDateTime(violation.resolved_at)}{violation.resolver?.name ? ` by ${violation.resolver.name}` : ''}{violation.resolution_note ? ` — ${violation.resolution_note}` : ''}</dd>
          </div>
        )}
      </dl>

      {appeal && appeal.status !== 'pending' && appeal.decision_note && (
        <p className="mt-3 rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-700">
          <span className="font-semibold">Appeal decision{appeal.decider?.name ? ` (${appeal.decider.name})` : ''}:</span> {appeal.decision_note}
        </p>
      )}
    </article>
  )
}
