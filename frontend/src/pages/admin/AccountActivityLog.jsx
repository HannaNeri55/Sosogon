// frontend/src/pages/admin/AccountActivityLog.jsx
import { useCallback, useEffect, useState } from 'react'
import { FiClipboard, FiLock, FiSearch } from 'react-icons/fi'
import { getAccountLog } from '../../api/admin'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import EmptyState from '../../components/common/EmptyState'
import Pagination from '../../components/common/Pagination'
import { formatDateTime } from '../../utils/accountStates'

const ACTIONS = {
  issued_warning: 'Warning issued',
  issued_suspension: 'Account suspended',
  issued_deactivation: 'Account deactivated',
  reactivated: 'Account reactivated',
  suspension_expired: 'Suspension ended (automatic)',
  closed_account: 'Account closed',
  appeal_submitted: 'Appeal submitted',
  appeal_approved: 'Appeal approved',
  appeal_rejected: 'Appeal rejected',
  activated_legacy_owner: 'Owner account activated',
  admin_created: 'Admin account created',
  admin_scope_changed: 'Admin scope changed',
}

/** Read-only audit trail of account management actions. Entries cannot be edited or deleted. */
export default function AccountActivityLog() {
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [action, setAction] = useState('')
  const [page, setPage] = useState(1)
  const [result, setResult] = useState(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    const timer = setTimeout(() => { setQuery(search); setPage(1) }, 350)
    return () => clearTimeout(timer)
  }, [search])

  const load = useCallback(async () => {
    setLoading(true)
    try {
      setResult(await getAccountLog({ q: query || undefined, action: action || undefined, page, per_page: 50 }))
    } finally {
      setLoading(false)
    }
  }, [query, action, page])

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load()
  }, [load])

  return (
    <div>
      <div className="mb-6">
        <p className="text-xs font-semibold uppercase tracking-wider text-secondary-500 mb-2">Account enforcement</p>
        <h1 className="text-2xl sm:text-3xl font-heading font-bold text-gray-900">Account Activity Log</h1>
        <p className="mt-1 flex items-center gap-1.5 text-sm text-gray-600">
          <FiLock size={14} aria-hidden="true" /> Every account management action, who did it and why. This log is read-only and cannot be edited or deleted.
        </p>
      </div>

      <div className="mb-5 flex flex-col gap-3 sm:flex-row">
        <div className="relative w-full sm:max-w-sm">
          <FiSearch className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={16} />
          <input className="input-field pl-9" placeholder="Search user or admin…" value={search} onChange={(e) => setSearch(e.target.value)} />
        </div>
        <select className="input-field sm:w-60" value={action} onChange={(e) => { setAction(e.target.value); setPage(1) }} aria-label="Action">
          <option value="">All actions</option>
          {Object.entries(ACTIONS).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
        </select>
      </div>

      {loading && !result ? (
        <LoadingSpinner size="lg" />
      ) : result?.data?.length === 0 ? (
        <EmptyState icon={FiClipboard} title="No entries">Account actions will be recorded here.</EmptyState>
      ) : (
        <>
          <div className={`bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) overflow-hidden transition-opacity ${loading ? 'opacity-60' : ''}`}>
            <div className="overflow-x-auto">
              <table className="table-cards w-full min-w-[56rem] text-sm">
                <thead className="bg-gray-50/80 border-b border-gray-200">
                  <tr>
                    {['Date and time', 'Action', 'Affected user', 'Status change', 'Reason', 'By'].map((heading) => (
                      <th key={heading} className="text-left px-5 py-3 text-xs font-semibold uppercase tracking-wider text-gray-500">{heading}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {result.data.map((entry) => (
                    <tr key={entry.id} className="border-b border-gray-100 last:border-b-0 align-top">
                      <td className="px-5 py-3 whitespace-nowrap text-gray-600">{formatDateTime(entry.created_at)}</td>
                      <td data-label="Action" className="px-5 py-3 font-medium text-gray-900">{ACTIONS[entry.action] || entry.action}</td>
                      <td data-label="Affected user" className="px-5 py-3">
                        <p className="text-gray-900">{entry.user_name}</p>
                        <p className="text-xs text-gray-500">{entry.user_email}</p>
                      </td>
                      <td data-label="Status change" className="px-5 py-3 whitespace-nowrap text-gray-600">
                        {entry.previous_status && entry.new_status ? `${entry.previous_status} → ${entry.new_status}` : '—'}
                      </td>
                      <td data-label="Reason" className="px-5 py-3 text-gray-700 max-w-xs">{entry.reason || '—'}</td>
                      <td data-label="By" className="px-5 py-3 whitespace-nowrap text-gray-600">{entry.admin_name || 'System'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
          <Pagination currentPage={result.current_page} lastPage={result.last_page} onPageChange={setPage} />
        </>
      )}
    </div>
  )
}
