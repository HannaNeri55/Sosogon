// frontend/src/pages/admin/ManageUsers.jsx
import { useCallback, useEffect, useState } from 'react'
import {
  FiSearch, FiUser, FiActivity, FiFileText, FiAlertTriangle, FiPauseCircle, FiSlash, FiRotateCcw, FiArchive, FiUserCheck,
} from 'react-icons/fi'
import toast from 'react-hot-toast'
import { getAdminUsers, getEnforcementMeta, updateAdminUser } from '../../api/admin'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import Pagination from '../../components/common/Pagination'
import StatusPill from '../../components/account/StatusPill'
import ActionMenu from '../../components/admin/accounts/ActionMenu'
import EnforcementModal from '../../components/admin/accounts/EnforcementModal'
import ReasonActionModal from '../../components/admin/accounts/ReasonActionModal'
import UserAccountPanel from '../../components/admin/accounts/UserAccountPanel'
import { formatDateTime } from '../../utils/accountStates'
import { showApiError } from '../../utils/apiErrors'
import { confirmDialog } from '../../utils/dialogs'
import useAdminScope from '../../hooks/useAdminScope'

const ROLE_STYLES = {
  admin: 'bg-primary-50 text-primary-700',
  business_owner: 'bg-secondary-50 text-secondary-700',
  tourist: 'bg-gray-100 text-gray-600',
}

const STATE_FILTERS = [
  { value: '', label: 'All accounts' },
  { value: 'restricted', label: 'Suspended / deactivated' },
  { value: 'pending', label: 'Pending review' },
  { value: 'closed', label: 'Closed' },
]

const ROLE_FILTERS = [
  { value: '', label: 'All roles' },
  { value: 'tourist', label: 'Tourists' },
  { value: 'business_owner', label: 'Business owners' },
  { value: 'admin', label: 'Admins' },
]

export default function ManageUsers() {
  // A municipal admin sees their municipality's owners and the tourists who reviewed its listings.
  const scope = useAdminScope()
  const [items, setItems] = useState([])
  const [pagination, setPagination] = useState({ current_page: 1, last_page: 1, total: 0 })
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [roleFilter, setRoleFilter] = useState('')
  const [stateFilter, setStateFilter] = useState('')
  const [categories, setCategories] = useState([])
  // { type: 'panel' | 'enforce' | 'reactivate' | 'close', user, tab?, action? }
  const [dialog, setDialog] = useState(null)
  const [panelKey, setPanelKey] = useState(0)

  const loadData = useCallback(async () => {
    setLoading(true)
    try {
      // Searched and paged on the server, so every account can be reached (there are hundreds).
      const data = await getAdminUsers({ per_page: 50, page, q: query || undefined, role: roleFilter || undefined, state: stateFilter || undefined })
      setItems(data.data || [])
      setPagination({ current_page: data.current_page, last_page: data.last_page, total: data.total })
    } finally {
      setLoading(false)
    }
  }, [page, query, roleFilter, stateFilter])

  // Search applies after a short pause in typing and starts again from page 1.
  useEffect(() => {
    const timer = setTimeout(() => { setQuery(search.trim()); setPage(1) }, 350)
    return () => clearTimeout(timer)
  }, [search])

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    loadData()
  }, [loadData])

  useEffect(() => {
    getEnforcementMeta().then((meta) => setCategories(meta.categories || [])).catch(() => {})
  }, [])

  const closeDialog = useCallback(() => setDialog(null), [])

  // After an action: refresh the list and, if the panel was open, reopen it with fresh data.
  const afterAction = (user) => {
    loadData()
    setPanelKey((key) => key + 1)
    setDialog(dialog?.from === 'panel' ? { type: 'panel', user, tab: 'violations' } : null)
  }

  const activateLegacyOwner = async (user) => {
    if (!(await confirmDialog({ title: `Activate ${user.name}'s account?`, message: 'This business owner registered before registration review existed.', confirmLabel: 'Activate' }))) return
    try {
      await updateAdminUser(user.id, { status: 'active' })
      toast.success('Owner account activated.')
      loadData()
    } catch (e) {
      showApiError(e, 'Could not activate this account.')
    }
  }

  const menuItems = (user, from = 'table') => {
    const state = user.account_state
    const role = user.role?.name || user.role
    const protectedAccount = role === 'admin'
    const items = [
      { label: 'View user details', icon: FiUser, onClick: () => setDialog({ type: 'panel', user, tab: 'details' }) },
      { label: 'View account activity', icon: FiActivity, onClick: () => setDialog({ type: 'panel', user, tab: 'activity' }) },
      { label: 'View violation history', icon: FiFileText, onClick: () => setDialog({ type: 'panel', user, tab: 'violations' }) },
    ]
    if (protectedAccount || state === 'closed') return items

    items.push({ divider: true })
    if (state === 'pending' && !user.owner_application) {
      items.push({ label: 'Activate owner account', icon: FiUserCheck, tone: 'good', onClick: () => activateLegacyOwner(user) })
    }
    items.push({ label: 'Send warning', icon: FiAlertTriangle, tone: 'warn', onClick: () => setDialog({ type: 'enforce', user, action: 'warning', from }) })
    if (state !== 'suspended' && state !== 'deactivated') {
      items.push({ label: 'Temporarily suspend', icon: FiPauseCircle, tone: 'warn', onClick: () => setDialog({ type: 'enforce', user, action: 'suspension', from }) })
    }
    if (state !== 'deactivated') {
      items.push({ label: 'Deactivate account', icon: FiSlash, tone: 'danger', onClick: () => setDialog({ type: 'enforce', user, action: 'deactivation', from }) })
    }
    if (state === 'suspended' || state === 'deactivated') {
      items.push({ label: 'Reactivate account', icon: FiRotateCcw, tone: 'good', onClick: () => setDialog({ type: 'reactivate', user, from }) })
    }
    // Closing a tourist account isn't tied to one municipality: provincial admins only.
    if (scope.isProvincial || role !== 'tourist') {
      items.push({ divider: true })
      items.push({ label: 'Close account…', icon: FiArchive, tone: 'danger', onClick: () => setDialog({ type: 'close', user, from }) })
    }
    return items
  }

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
          <p className="text-xs font-semibold uppercase tracking-wider text-secondary-500 mb-2">Admin</p>
          <h1 className="text-2xl sm:text-3xl font-heading font-bold text-gray-900">Manage Users</h1>
          <p className="mt-1 text-sm text-gray-600">Account actions are recorded, logged and sent to the user. Nothing is deleted with one click.</p>
          {scope.isMunicipal && (
            <p className="mt-1 text-sm text-gray-600">
              Showing {scope.municipality?.name}'s business owners, and tourists who reviewed its listings. You can act on a tourist over
              one of those reviews; other cases go to the provincial administrator.
            </p>
          )}
        </div>
      </div>

      <div className="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center">
        <div className="relative w-full sm:max-w-sm">
          <FiSearch className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={16} />
          <input className="input-field pl-9" placeholder="Search by name or email..." value={search} onChange={(e) => setSearch(e.target.value)} />
        </div>
        <select className="input-field sm:w-48" value={roleFilter} onChange={(e) => { setRoleFilter(e.target.value); setPage(1) }} aria-label="Filter by role">
          {ROLE_FILTERS.map((f) => <option key={f.value} value={f.value}>{f.label}</option>)}
        </select>
        <select className="input-field sm:w-56" value={stateFilter} onChange={(e) => { setStateFilter(e.target.value); setPage(1) }} aria-label="Filter by account status">
          {STATE_FILTERS.map((f) => <option key={f.value} value={f.value}>{f.label}</option>)}
        </select>
      </div>

      {!loading && <p className="mb-3 text-sm text-gray-600"><span className="font-semibold text-gray-900">{pagination.total}</span> account{pagination.total === 1 ? '' : 's'}</p>}
      {loading && items.length === 0 ? (
        <LoadingSpinner size="lg" />
      ) : (
        <>
        <div className={`bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) overflow-hidden transition-opacity ${loading ? 'opacity-60' : ''}`}>
          <div className="overflow-x-auto">
            {/* Phones: keep columns readable and let the table scroll sideways. */}
            <table className="table-cards w-full min-w-[48rem]">
              <thead className="bg-gray-50/80 border-b border-gray-200">
                <tr>
                  <th className="text-left px-6 py-3 text-xs font-semibold uppercase tracking-wider text-gray-500">User</th>
                  <th className="text-left px-6 py-3 text-xs font-semibold uppercase tracking-wider text-gray-500">Role</th>
                  <th className="text-left px-6 py-3 text-xs font-semibold uppercase tracking-wider text-gray-500">Account status</th>
                  <th className="text-left px-6 py-3 text-xs font-semibold uppercase tracking-wider text-gray-500">Violations</th>
                  <th className="text-right px-6 py-3 text-xs font-semibold uppercase tracking-wider text-gray-500">Actions</th>
                </tr>
              </thead>
              <tbody>
                {items.length === 0 && (
                  <tr><td colSpan={5} className="px-6 py-10 text-center text-sm text-gray-500">No users match these filters.</td></tr>
                )}
                {items.map((user) => (
                  <tr key={user.id} className="border-b border-gray-100 last:border-b-0 hover:bg-primary-50/40 transition-colors duration-150">
                    <td className="px-6 py-3">
                      <button type="button" onClick={() => setDialog({ type: 'panel', user, tab: 'details' })} className="flex items-center gap-3 text-left">
                        <div className="w-9 h-9 rounded-full bg-primary-50 text-primary-600 flex items-center justify-center font-heading font-semibold text-sm shrink-0">
                          {user.name.charAt(0).toUpperCase()}
                        </div>
                        <div>
                          <p className="text-sm font-medium text-gray-900 hover:text-primary-700">{user.name}</p>
                          <p className="text-xs text-gray-500">{user.email}</p>
                        </div>
                      </button>
                    </td>
                    <td data-label="Role" className="px-6 py-4">
                      <span className={`whitespace-nowrap text-xs font-medium px-2.5 py-1 rounded-full capitalize ${ROLE_STYLES[user.role?.name] || 'bg-gray-100 text-gray-600'}`}>
                        {user.role?.name?.replace('_', ' ')}
                      </span>
                    </td>
                    <td data-label="Account status" className="px-6 py-4">
                      <StatusPill value={user.account_state} withDot />
                      {user.account_state === 'suspended' && user.suspended_until && (
                        <p className="mt-1 text-xs text-gray-500">until {formatDateTime(user.suspended_until)}</p>
                      )}
                    </td>
                    <td data-label="Violations" className="px-6 py-4 text-sm text-gray-600">
                      {user.violations_count > 0 ? (
                        <button type="button" onClick={() => setDialog({ type: 'panel', user, tab: 'violations' })} className="font-medium text-primary-700 hover:underline">
                          {user.violations_count} on record
                        </button>
                      ) : '—'}
                    </td>
                    <td data-actions className="px-6 py-4 text-right">
                      <ActionMenu items={menuItems(user)} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
        <Pagination currentPage={pagination.current_page} lastPage={pagination.last_page} onPageChange={setPage} />
        </>
      )}

      {dialog?.type === 'panel' && (
        <UserAccountPanel
          key={`${dialog.user.id}-${dialog.tab}`}
          userId={dialog.user.id}
          initialTab={dialog.tab}
          refreshKey={panelKey}
          onClose={closeDialog}
          actions={(fresh) => (
            <ActionMenu label="Account actions" items={menuItems({ ...dialog.user, ...fresh, role: { name: fresh.role } }, 'panel').filter((item) => item.divider || !item.label.startsWith('View'))} />
          )}
        />
      )}
      {dialog?.type === 'enforce' && (
        <EnforcementModal user={dialog.user} initialAction={dialog.action} categories={categories} onClose={closeDialog} onDone={() => afterAction(dialog.user)} />
      )}
      {(dialog?.type === 'reactivate' || dialog?.type === 'close') && (
        <ReasonActionModal user={dialog.user} mode={dialog.type} onClose={closeDialog} onDone={() => afterAction(dialog.user)} />
      )}
    </div>
  )
}
