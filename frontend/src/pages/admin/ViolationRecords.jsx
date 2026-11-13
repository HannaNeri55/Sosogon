// frontend/src/pages/admin/ViolationRecords.jsx
import { useCallback, useEffect, useState } from 'react'
import { FiSearch, FiShield } from 'react-icons/fi'
import { getEnforcementMeta, getViolationRecords } from '../../api/admin'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import EmptyState from '../../components/common/EmptyState'
import Pagination from '../../components/common/Pagination'
import ViolationCard from '../../components/admin/accounts/ViolationCard'
import { ENFORCEMENT_ACTIONS, VIOLATION_STATUSES, APPEAL_STATUSES } from '../../utils/accountStates'

const EMPTY = { q: '', category: '', action: '', status: '', role: '', appeal: '', page: 1 }

/** Every enforcement record, kept permanently (also after reactivation), with search and filters. */
export default function ViolationRecords() {
  const [filters, setFilters] = useState(EMPTY)
  const [search, setSearch] = useState('')
  const [categories, setCategories] = useState([])
  const [result, setResult] = useState(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    getEnforcementMeta().then((meta) => setCategories(meta.categories || [])).catch(() => {})
  }, [])

  // Search text applies after a short pause in typing.
  useEffect(() => {
    const timer = setTimeout(() => setFilters((prev) => (prev.q === search ? prev : { ...prev, q: search, page: 1 })), 350)
    return () => clearTimeout(timer)
  }, [search])

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const params = Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== ''))
      setResult(await getViolationRecords({ ...params, per_page: 20 }))
    } finally {
      setLoading(false)
    }
  }, [filters])

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load()
  }, [load])

  const set = (field) => (event) => setFilters((prev) => ({ ...prev, [field]: event.target.value, page: 1 }))
  const active = Object.entries(filters).some(([key, value]) => key !== 'page' && value !== '')

  return (
    <div>
      <div className="mb-6">
        <p className="text-xs font-semibold uppercase tracking-wider text-secondary-500 mb-2">Account enforcement</p>
        <h1 className="text-2xl sm:text-3xl font-heading font-bold text-gray-900">Violation Records</h1>
        <p className="mt-1 text-sm text-gray-600">Every warning, suspension and deactivation, with its evidence, reviewer, outcome and appeal. Records are never deleted.</p>
      </div>

      <div className="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-6">
        <div className="relative sm:col-span-2">
          <FiSearch className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={16} />
          <input className="input-field pl-9" placeholder="Search user name or email…" value={search} onChange={(e) => setSearch(e.target.value)} />
        </div>
        <select className="input-field" value={filters.category} onChange={set('category')} aria-label="Category">
          <option value="">All categories</option>
          {categories.map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
        </select>
        <select className="input-field" value={filters.action} onChange={set('action')} aria-label="Action">
          <option value="">All actions</option>
          {Object.entries(ENFORCEMENT_ACTIONS).map(([value, meta]) => <option key={value} value={value}>{meta.label}</option>)}
        </select>
        <select className="input-field" value={filters.status} onChange={set('status')} aria-label="Record status">
          <option value="">Any status</option>
          {Object.entries(VIOLATION_STATUSES).map(([value, meta]) => <option key={value} value={value}>{meta.label}</option>)}
        </select>
        <select className="input-field" value={filters.role} onChange={set('role')} aria-label="Role">
          <option value="">All roles</option>
          <option value="tourist">Tourists</option>
          <option value="business_owner">Business owners</option>
        </select>
        <select className="input-field" value={filters.appeal} onChange={set('appeal')} aria-label="Appeal">
          <option value="">Any appeal status</option>
          <option value="none">No appeal</option>
          {Object.entries(APPEAL_STATUSES).map(([value, meta]) => <option key={value} value={value}>{meta.label}</option>)}
        </select>
        {active && (
          <button type="button" onClick={() => { setSearch(''); setFilters(EMPTY) }} className="text-sm font-semibold text-primary-700 hover:text-primary-800 sm:justify-self-start">
            Clear filters
          </button>
        )}
      </div>

      {loading && !result ? (
        <LoadingSpinner size="lg" />
      ) : result?.data?.length === 0 ? (
        <EmptyState icon={FiShield} title={active ? 'No records match these filters' : 'No violation records yet'}>
          Enforcement actions taken from Manage Users appear here.
        </EmptyState>
      ) : (
        <>
          <p className="mb-3 text-sm text-gray-600"><span className="font-semibold text-gray-900">{result.total}</span> record{result.total === 1 ? '' : 's'}</p>
          <div className={`space-y-3 transition-opacity ${loading ? 'opacity-60' : ''}`}>
            {result.data.map((violation) => <ViolationCard key={violation.id} violation={violation} showUser />)}
          </div>
          <Pagination currentPage={result.current_page} lastPage={result.last_page} onPageChange={(page) => setFilters((prev) => ({ ...prev, page }))} />
        </>
      )}
    </div>
  )
}
