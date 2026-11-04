// frontend/src/components/admin/accounts/UserAccountPanel.jsx
import { useEffect, useState } from 'react'
import { FiX } from 'react-icons/fi'
import LoadingSpinner from '../../common/LoadingSpinner'
import StatusPill from '../../account/StatusPill'
import ViolationCard from './ViolationCard'
import { getUserAccount } from '../../../api/admin'
import { formatDateTime, roleLabel } from '../../../utils/accountStates'

const TABS = [
  { key: 'details', label: 'Details' },
  { key: 'activity', label: 'Account activity' },
  { key: 'violations', label: 'Violation history' },
]

const LOG_LABELS = {
  issued_warning: 'Warning issued',
  issued_suspension: 'Account suspended',
  issued_deactivation: 'Account deactivated',
  reactivated: 'Account reactivated',
  suspension_expired: 'Suspension ended automatically',
  closed_account: 'Account closed',
  appeal_submitted: 'Appeal submitted',
  appeal_approved: 'Appeal approved',
  appeal_rejected: 'Appeal rejected',
  activated_legacy_owner: 'Owner account activated',
}

/** Side panel: profile and state, account activity, and full violation history of one user. */
export default function UserAccountPanel({ userId, initialTab = 'details', refreshKey, onClose, actions }) {
  const [tab, setTab] = useState(initialTab)
  const [data, setData] = useState(null)
  const [error, setError] = useState(false)

  useEffect(() => {
    let ignore = false
    getUserAccount(userId)
      .then((result) => !ignore && (setData(result), setError(false)))
      .catch(() => !ignore && setError(true))
    return () => { ignore = true }
  }, [userId, refreshKey])

  useEffect(() => {
    const onKey = (event) => event.key === 'Escape' && onClose()
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])

  const user = data?.user

  return (
    <div className="fixed inset-0 z-90 flex justify-end bg-black/40" onClick={onClose} role="dialog" aria-modal="true" aria-label="User account">
      <aside className="flex h-full w-full max-w-xl flex-col bg-gray-50 shadow-xl" onClick={(e) => e.stopPropagation()}>
        <header className="border-b border-gray-200 bg-white px-5 py-4">
          <div className="flex items-start justify-between gap-3">
            <div className="min-w-0">
              <h2 className="font-heading text-lg font-bold text-gray-900 truncate">{user?.name || 'Loading…'}</h2>
              {user && <p className="text-sm text-gray-500 truncate">{user.email} · {roleLabel(user.role)}</p>}
            </div>
            <button type="button" onClick={onClose} className="-mr-2 p-2 rounded-full text-gray-500 hover:bg-gray-100" aria-label="Close panel">
              <FiX size={20} />
            </button>
          </div>
          {user && (
            <div className="mt-3 flex flex-wrap items-center gap-2">
              <StatusPill value={user.account_state} withDot />
              {user.account_state === 'suspended' && <span className="text-xs text-gray-600">until {formatDateTime(user.suspended_until)}</span>}
              {user.is_protected && <span className="text-xs font-medium text-gray-500">Administrator accounts are protected</span>}
            </div>
          )}
          {user && actions && <div className="mt-3">{actions(user)}</div>}
          <nav className="mt-4 -mb-4 flex gap-1 overflow-x-auto" aria-label="User account sections">
            {TABS.map((t) => (
              <button
                key={t.key}
                type="button"
                onClick={() => setTab(t.key)}
                className={`whitespace-nowrap border-b-2 px-3 py-2 text-sm font-semibold ${tab === t.key ? 'border-primary-500 text-primary-700' : 'border-transparent text-gray-500 hover:text-gray-800'}`}
              >
                {t.label}
                {t.key === 'violations' && data?.violations?.length > 0 && ` (${data.violations.length})`}
              </button>
            ))}
          </nav>
        </header>

        <div className="flex-1 overflow-y-auto p-5">
          {error ? (
            <p className="text-sm text-red-700">Could not load this account. Please try again.</p>
          ) : !data ? (
            <LoadingSpinner />
          ) : tab === 'details' ? (
            <dl className="grid gap-4 rounded-xl border border-gray-200 bg-white p-5 text-sm sm:grid-cols-2">
              {[
                ['Name', user.name],
                ['Email', user.email],
                ['Phone', user.phone || '—'],
                ['Role', roleLabel(user.role)],
                ['Business type', user.business_type ? user.business_type.replace('_', ' ') : '—'],
                ['Registration', user.application ? `${user.application.business_name || 'Application'} (${user.application.status})` : '—'],
                ['Member since', formatDateTime(user.created_at)],
                ['Last active', formatDateTime(user.last_seen_at)],
                ['Reviews written', user.counts.reviews],
                ['Saved itineraries', user.counts.itineraries],
                ['Business submissions', user.counts.business_submissions],
                ['Closed on', user.closed_at ? formatDateTime(user.closed_at) : '—'],
              ].map(([label, value]) => (
                <div key={label}>
                  <dt className="text-xs font-semibold uppercase tracking-wider text-gray-500">{label}</dt>
                  <dd className="mt-0.5 text-gray-900">{value}</dd>
                </div>
              ))}
            </dl>
          ) : tab === 'activity' ? (
            <div className="space-y-6">
              <section>
                <h3 className="text-xs font-semibold uppercase tracking-wider text-gray-500 mb-2">Account management</h3>
                {data.account_log.length === 0 ? (
                  <p className="text-sm text-gray-500">No account actions yet.</p>
                ) : (
                  <ol className="space-y-2">
                    {data.account_log.map((entry) => (
                      <li key={entry.id} className="rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm">
                        <p className="font-semibold text-gray-900">{LOG_LABELS[entry.action] || entry.action}</p>
                        <p className="text-xs text-gray-500">{formatDateTime(entry.created_at)} · {entry.admin_name || 'System'}{entry.previous_status && entry.new_status && entry.previous_status !== entry.new_status ? ` · ${entry.previous_status} → ${entry.new_status}` : ''}</p>
                        {entry.reason && <p className="mt-1 text-gray-700">{entry.reason}</p>}
                      </li>
                    ))}
                  </ol>
                )}
              </section>
              <section>
                <h3 className="text-xs font-semibold uppercase tracking-wider text-gray-500 mb-2">Other recorded activity</h3>
                {data.activity.length === 0 ? (
                  <p className="text-sm text-gray-500">No other activity recorded for this user.</p>
                ) : (
                  <ol className="space-y-2">
                    {data.activity.map((entry) => (
                      <li key={entry.id} className="rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm">
                        <p className="font-medium text-gray-900">{entry.description || entry.action}</p>
                        <p className="text-xs text-gray-500">{formatDateTime(entry.created_at)}</p>
                      </li>
                    ))}
                  </ol>
                )}
              </section>
            </div>
          ) : data.violations.length === 0 ? (
            <p className="text-sm text-gray-500">No violations on record.</p>
          ) : (
            <div className="space-y-3">
              {data.violations.map((violation) => <ViolationCard key={violation.id} violation={violation} />)}
            </div>
          )}
        </div>
      </aside>
    </div>
  )
}
