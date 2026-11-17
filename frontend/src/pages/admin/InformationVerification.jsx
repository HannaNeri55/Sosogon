import { useCallback, useEffect, useRef, useState } from 'react'
import toast from 'react-hot-toast'
import { FiAlertCircle, FiCheckCircle, FiClock, FiExternalLink, FiInfo, FiSearch, FiSettings } from 'react-icons/fi'
import {
  getMunicipalities,
  getVerification,
  getVerificationSettings,
  getVerifications,
  updateVerificationSettings,
  verifyListing,
} from '../../api/admin'
import Pagination from '../../components/common/Pagination'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import Modal from '../../components/common/Modal'
import ManagedBy from '../../components/admin/ManagedBy'
import { Badge } from '../../components/admin/reviews/ReviewContent'
import useAdminScope from '../../hooks/useAdminScope'
import { showApiError } from '../../utils/apiErrors'

const STATUS = {
  not_verified: { label: 'Not yet verified', className: 'border-gray-200 bg-gray-50 text-gray-600', icon: FiInfo },
  changed: { label: 'Updated since verified', className: 'border-sky-200 bg-sky-50 text-sky-800', icon: FiAlertCircle },
  overdue: { label: 'Verification overdue', className: 'border-amber-200 bg-amber-50 text-amber-800', icon: FiClock },
  verified: { label: 'Recently verified', className: 'border-green-200 bg-green-50 text-green-800', icon: FiCheckCircle },
}
const FILTERS = [
  { key: 'needs_attention', label: 'Needs attention' },
  { key: 'not_verified', label: 'Not yet verified' },
  { key: 'changed', label: 'Updated since verified' },
  { key: 'overdue', label: 'Overdue' },
  { key: 'verified', label: 'Fully verified' },
  { key: 'all', label: 'All' },
]
const ASPECT_LABELS = { price: 'Price', location: 'Location', hours: 'Operating Hours', contact: 'Contact Information' }

const shortDate = (iso) => new Date(iso).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
const dateTime = (iso) => new Date(iso).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' })

// In the list: the date of the last check with verified and overdue parts; the status name otherwise.
function listLabel(aspect) {
  if (aspect.status === 'verified') return `Verified ${shortDate(aspect.verified_at)}`
  if (aspect.status === 'overdue') return `Overdue · ${shortDate(aspect.verified_at)}`
  return undefined
}

function StatusBadge({ status, children }) {
  const style = STATUS[status] || STATUS.not_verified
  const Icon = style.icon
  return <Badge className={style.className}><Icon size={12} aria-hidden="true" />{children ?? style.label}</Badge>
}

/**
 * Information Verification: published destinations, accommodations, restaurants and shops with
 * when each part of their information (price, location, hours, contact) was last checked by the
 * Tourism Office. Opening one shows the information as it is published now; the admin ticks the
 * parts they actually checked and confirms. Nothing here is verified by editing a listing.
 */
export default function InformationVerification() {
  const scope = useAdminScope()
  const [filter, setFilter] = useState('needs_attention')
  const [type, setType] = useState('')
  const [town, setTown] = useState('')
  const [searchInput, setSearchInput] = useState('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(false)
  const [towns, setTowns] = useState([])
  // ?open=destination-72 opens that listing's check right away.
  const [open, setOpen] = useState(() => {
    const match = /^(destination|accommodation|food_beverage|retail_shop)-(\d+)$/.exec(new URLSearchParams(window.location.search).get('open') || '')
    return match ? { type: match[1], id: Number(match[2]) } : null
  }) // { type, id }
  const [editingSettings, setEditingSettings] = useState(false)
  const latestRequest = useRef(0)

  const load = useCallback(() => {
    const request = ++latestRequest.current
    return getVerifications({ status: filter, type: type || undefined, municipality_id: town || undefined, search: search || undefined, page })
      .then((result) => {
        if (request !== latestRequest.current) return
        setData(result)
        setError(false)
      })
      .catch(() => request === latestRequest.current && setError(true))
      .finally(() => request === latestRequest.current && setLoading(false))
  }, [filter, type, town, search, page])

  useEffect(() => {
    load()
  }, [load])

  useEffect(() => {
    if (!scope.isProvincial) return
    getMunicipalities().then((result) => setTowns(Array.isArray(result) ? result : result.data || [])).catch(() => {})
  }, [scope.isProvincial])

  const refilter = (setter) => (value) => { setLoading(true); setter(value); setPage(1) }
  const counts = data?.counts || {}
  const rows = data?.data || []
  const days = data?.overdue_days || {}

  return (
    <div>
      <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <p className="text-primary-600 font-medium mb-2">Admin</p>
          <h1 className="text-2xl sm:text-3xl font-heading font-bold text-gray-900">Information Verification</h1>
          <p className="text-gray-600 mt-2 max-w-3xl">
            Check that each published listing&apos;s prices, location, hours and contact details are still correct, then record the check.
            Visitors see when each part was last verified.
          </p>
        </div>
        <button type="button" onClick={() => setEditingSettings(true)} className="btn-secondary shrink-0 justify-center">
          <FiSettings size={16} aria-hidden="true" /> Overdue periods
        </button>
      </div>

      {data && (
        <p className="mb-4 text-sm text-gray-500">
          Overdue after: {Object.entries(ASPECT_LABELS).map(([key, label]) => `${label} ${days[key] ?? '–'} days`).join(' · ')}
        </p>
      )}

      <div className="mb-4 flex flex-wrap gap-2" role="tablist" aria-label="Verification status">
        {FILTERS.map(({ key, label }) => {
          const active = filter === key
          return (
            <button key={key} type="button" role="tab" aria-selected={active} onClick={() => refilter(setFilter)(key)}
              className={`rounded-full border px-4 py-1.5 text-sm font-medium transition-colors ${active ? 'border-primary-600 bg-primary-600 text-white' : 'border-gray-200 bg-white text-gray-700 hover:border-primary-300'}`}>
              {label} <span className={active ? 'text-primary-100' : 'text-gray-400'}>({counts[key] ?? 0})</span>
            </button>
          )
        })}
      </div>

      <form className={`mb-6 grid grid-cols-1 gap-3 ${scope.isProvincial ? 'sm:grid-cols-[12rem_12rem_minmax(0,1fr)_auto]' : 'sm:grid-cols-[12rem_minmax(0,1fr)_auto]'}`}
        onSubmit={(e) => { e.preventDefault(); refilter(setSearch)(searchInput.trim()) }}>
        <label className="sr-only" htmlFor="verify-type">Type</label>
        <select id="verify-type" value={type} onChange={(e) => refilter(setType)(e.target.value)} className="input-field">
          <option value="">All types</option>
          {Object.entries(data?.types || {}).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
        </select>
        {scope.isProvincial && (
          <>
            <label className="sr-only" htmlFor="verify-town">Municipality</label>
            <select id="verify-town" value={town} onChange={(e) => refilter(setTown)(e.target.value)} className="input-field">
              <option value="">All municipalities</option>
              {towns.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
            </select>
          </>
        )}
        <div className="relative">
          <FiSearch className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={16} aria-hidden="true" />
          <label className="sr-only" htmlFor="verify-search">Search by name</label>
          <input id="verify-search" type="search" value={searchInput} onChange={(e) => setSearchInput(e.target.value)} maxLength={100} className="input-field pl-9" placeholder="Search by name" />
        </div>
        <button type="submit" className="btn-secondary justify-center">Search</button>
      </form>

      {loading ? (
        <LoadingSpinner size="lg" />
      ) : error ? (
        <div className="bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) p-8 text-center">
          <p className="text-gray-600 mb-4">We couldn&apos;t load the listings.</p>
          <button type="button" onClick={() => { setLoading(true); load() }} className="btn-secondary">Try again</button>
        </div>
      ) : rows.length === 0 ? (
        <div className="bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) p-10 text-center">
          <FiCheckCircle className="mx-auto text-primary-500" size={28} aria-hidden="true" />
          <p className="mt-2 text-gray-600">{filter === 'needs_attention' && !search && !type && !town ? 'Every listing is verified and up to date.' : 'No listings match these filters.'}</p>
        </div>
      ) : (
        <>
          <ul className="space-y-3">
            {rows.map((row) => (
              <li key={`${row.type}-${row.id}`}>
                <button type="button" onClick={() => setOpen({ type: row.type, id: row.id })}
                  className="block w-full text-left bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) p-4 sm:p-5 hover:border-primary-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
                  <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <p className="font-semibold text-gray-900 wrap-break-word">{row.name}</p>
                    <p className="text-xs text-gray-500">{row.type_label}{row.municipality ? ` · ${row.municipality}` : ''}</p>
                  </div>
                  <div className="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4">
                    {row.aspects.map((aspect) => (
                      <div key={aspect.aspect} className="flex flex-col gap-1">
                        <span className="text-xs font-medium text-gray-500">{aspect.label}</span>
                        <span><StatusBadge status={aspect.status}>{listLabel(aspect)}</StatusBadge></span>
                      </div>
                    ))}
                  </div>
                </button>
              </li>
            ))}
          </ul>
          <Pagination currentPage={data.current_page} lastPage={data.last_page}
            onPageChange={(value) => { setLoading(true); setPage(value); window.scrollTo({ top: 0, behavior: 'smooth' }) }} />
        </>
      )}

      {open && <VerifyDialog listing={open} onClose={() => setOpen(null)} onVerified={load} />}
      {editingSettings && (
        <SettingsDialog canEdit={scope.isProvincial} onClose={() => setEditingSettings(false)} onSaved={() => { setEditingSettings(false); load() }} />
      )}
    </div>
  )
}

function VerifyDialog({ listing, onClose, onVerified }) {
  const [details, setDetails] = useState(null)
  const [failed, setFailed] = useState(false)
  const [checked, setChecked] = useState([])
  const [note, setNote] = useState('')
  const [saving, setSaving] = useState(false)

  const load = useCallback(() => getVerification(listing.type, listing.id)
    .then((result) => { setDetails(result); setFailed(false) })
    .catch((error) => { setFailed(true); showApiError(error, 'This listing could not be opened.') }), [listing])

  useEffect(() => {
    load()
  }, [load])

  const aspects = details?.listing.aspects || []
  const canManage = details && details.listing.can_manage !== false
  const allChecked = aspects.length > 0 && checked.length === aspects.length
  const toggle = (key) => setChecked((current) => (current.includes(key) ? current.filter((k) => k !== key) : [...current, key]))

  const save = async () => {
    setSaving(true)
    try {
      const fingerprints = Object.fromEntries(aspects.filter((a) => checked.includes(a.aspect)).map((a) => [a.aspect, a.fingerprint]))
      const result = await verifyListing(listing.type, listing.id, { aspects: checked, note: note.trim() || undefined, fingerprints })
      toast.success(result.message)
      setDetails(result)
      setChecked([])
      setNote('')
      onVerified()
    } catch (error) {
      // The information changed while it was open: show what is published now.
      if (error.response?.data?.errors?.fingerprints) {
        toast.error(error.response.data.errors.fingerprints[0])
        setChecked([])
        load()
      } else {
        showApiError(error, 'The check could not be recorded.')
      }
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal
      title={details ? details.listing.name : 'Verify information'}
      subtitle={details ? `${details.listing.type_label}${details.listing.municipality ? ` · ${details.listing.municipality}` : ''}` : undefined}
      onClose={() => !saving && onClose()}
      busy={saving}
      size="xl"
      footer={canManage ? (
        <div className="flex flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-end">
          <button type="button" className="btn-secondary" disabled={saving} onClick={onClose}>Close</button>
          <button type="button" className="btn-primary justify-center" disabled={saving || checked.length === 0} onClick={save}>
            <FiCheckCircle size={16} aria-hidden="true" />
            {saving ? 'Saving…' : checked.length === 0 ? 'Mark as verified' : `Mark ${checked.length === aspects.length ? 'all' : checked.length} as verified`}
          </button>
        </div>
      ) : (
        <div className="flex justify-end"><button type="button" className="btn-secondary" onClick={onClose}>Close</button></div>
      )}
    >
      {!details ? (
        failed ? <p className="text-sm text-gray-600">This listing could not be opened.</p> : <LoadingSpinner />
      ) : (
        <div className="space-y-5 text-sm">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <a href={details.listing.path} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 font-medium text-primary-700 hover:text-primary-800">
              Open the public page <FiExternalLink size={13} aria-hidden="true" />
            </a>
            {canManage ? (
              <label className="inline-flex items-center gap-2 font-medium text-gray-700">
                <input type="checkbox" checked={allChecked} onChange={() => setChecked(allChecked ? [] : aspects.map((a) => a.aspect))}
                  className="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500" />
                Verify all
              </label>
            ) : (
              <ManagedBy office={details.listing.managed_by} />
            )}
          </div>

          {canManage && (
            <p className="flex gap-2 rounded-xl bg-gray-50 p-3 text-gray-600">
              <FiInfo className="mt-0.5 shrink-0 text-gray-400" aria-hidden="true" />
              Tick only what you actually checked, for example by calling the place or visiting it. This is the information visitors see now.
            </p>
          )}

          <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
            {aspects.map((aspect) => (
              <div key={aspect.aspect} className={`rounded-xl border p-4 ${checked.includes(aspect.aspect) ? 'border-primary-300 bg-primary-50/40' : 'border-gray-200'}`}>
                <div className="flex items-start justify-between gap-3">
                  {canManage ? (
                    <label className="inline-flex items-center gap-2 font-semibold text-gray-900">
                      <input type="checkbox" checked={checked.includes(aspect.aspect)} onChange={() => toggle(aspect.aspect)}
                        className="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500" />
                      {aspect.label}
                    </label>
                  ) : (
                    <p className="font-semibold text-gray-900">{aspect.label}</p>
                  )}
                  <StatusBadge status={aspect.status} />
                </div>
                <dl className="mt-3 space-y-1.5">
                  {aspect.display.length === 0 ? (
                    <p className="text-gray-500">Nothing listed.</p>
                  ) : aspect.display.map((entry, index) => (
                    <div key={`${entry.label}-${index}`} className="flex flex-wrap justify-between gap-x-3">
                      <dt className="text-gray-500 wrap-break-word">{entry.label}</dt>
                      <dd className="text-right font-medium text-gray-900 wrap-break-word">{entry.value}</dd>
                    </div>
                  ))}
                </dl>
                <p className="mt-3 border-t border-gray-100 pt-2 text-xs text-gray-500">
                  {aspect.verified_at
                    ? <>Last verified {dateTime(aspect.verified_at)}{aspect.verified_by ? ` by ${aspect.verified_by}` : ''}{aspect.status !== 'changed' && aspect.due_at ? ` · due again ${shortDate(aspect.due_at)}` : ''}</>
                    : 'Never verified'}
                  {aspect.status === 'changed' && <span className="block text-sky-700">The published information changed after this check.</span>}
                </p>
              </div>
            ))}
          </div>

          {canManage && (
            <label className="block">
              <span className="mb-1 block font-medium text-gray-700">Note <span className="font-normal text-gray-500">(optional, only admins see this)</span></span>
              <input value={note} onChange={(e) => setNote(e.target.value)} maxLength={500} className="input-field" placeholder="How you checked, for example: Called the front desk on 0917…" />
            </label>
          )}

          <div className="border-t border-gray-100 pt-5">
            <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500">Verification history</p>
            {details.history.length === 0 ? (
              <p className="text-gray-500">No checks recorded yet.</p>
            ) : (
              <ul className="divide-y divide-gray-100 rounded-xl border border-gray-200">
                {details.history.map((entry) => (
                  <li key={entry.id} className="flex flex-col gap-0.5 px-4 py-2.5 sm:flex-row sm:items-baseline sm:gap-3">
                    <span className="font-medium text-gray-900 sm:w-44 sm:shrink-0">{entry.label}</span>
                    <span className="text-gray-500 sm:w-56 sm:shrink-0">{dateTime(entry.verified_at)} · {entry.verified_by || 'An administrator'}</span>
                    {entry.note && <span className="text-gray-600 wrap-break-word">{entry.note}</span>}
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
      )}
    </Modal>
  )
}

function SettingsDialog({ canEdit, onClose, onSaved }) {
  const [values, setValues] = useState(null)
  const [defaults, setDefaults] = useState({})
  const [saving, setSaving] = useState(false)
  const [errors, setErrors] = useState({})

  useEffect(() => {
    getVerificationSettings()
      .then((result) => { setValues(Object.fromEntries(Object.entries(result.overdue_days).map(([k, v]) => [k, String(v)]))); setDefaults(result.defaults || {}) })
      .catch((error) => showApiError(error, 'The overdue periods could not be loaded.'))
  }, [])

  const save = async () => {
    setSaving(true)
    setErrors({})
    try {
      await updateVerificationSettings({ overdue_days: Object.fromEntries(Object.entries(values).map(([k, v]) => [k, Number(v)])) })
      toast.success('Overdue periods saved.')
      onSaved()
    } catch (error) {
      if (error.response?.status === 422) {
        setErrors(Object.fromEntries(Object.keys(values).map((k) => [k, error.response.data.errors?.[`overdue_days.${k}`]?.[0]]).filter(([, v]) => v)))
      } else {
        showApiError(error, 'The overdue periods could not be saved.')
      }
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal
      title="Overdue periods"
      subtitle="After how many days a check of each part is due again"
      onClose={() => !saving && onClose()}
      busy={saving}
      size="sm"
      footer={canEdit ? (
        <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" className="btn-secondary" disabled={saving} onClick={onClose}>Cancel</button>
          <button type="button" className="btn-primary justify-center" disabled={saving || !values} onClick={save}>{saving ? 'Saving…' : 'Save'}</button>
        </div>
      ) : (
        <div className="flex justify-end"><button type="button" className="btn-secondary" onClick={onClose}>Close</button></div>
      )}
    >
      {!values ? <LoadingSpinner /> : (
        <div className="space-y-4 text-sm">
          {Object.entries(ASPECT_LABELS).map(([key, label]) => (
            <label key={key} className="block">
              <span className="mb-1 block font-medium text-gray-700">{label}</span>
              <div className="flex items-center gap-2">
                <input type="number" min={1} max={3650} value={values[key] ?? ''} disabled={!canEdit || saving}
                  onChange={(e) => setValues((current) => ({ ...current, [key]: e.target.value }))}
                  className={`input-field w-32 ${errors[key] ? 'input-error' : ''}`} aria-invalid={!!errors[key]} />
                <span className="text-gray-500">days{defaults[key] ? ` (default ${defaults[key]})` : ''}</span>
              </div>
              {errors[key] && <p className="field-error">{errors[key]}</p>}
            </label>
          ))}
          {!canEdit && <ManagedBy office="the provincial administrator" />}
        </div>
      )}
    </Modal>
  )
}
