// frontend/src/pages/admin/ReviewItems.jsx
import { useCallback, useEffect, useRef, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { FiAlertTriangle, FiCheck, FiClock, FiEdit3, FiInbox, FiLayers, FiRefreshCw, FiSearch, FiX, FiXCircle } from 'react-icons/fi'
import toast from 'react-hot-toast'
import { bulkReviewItems, getItemReviews, updateAccommodationRoom } from '../../api/admin'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import Modal from '../../components/common/Modal'
import Pagination from '../../components/common/Pagination'
import StatCard from '../../components/common/StatCard'
import ItemReviewModal from '../../components/admin/ItemReviewModal'
import { formatShortDate } from '../../utils/helpers'
import { ITEM_KINDS, REVIEW_TABS, priceLabel, reviewError } from '../../utils/itemReview'
import RoomFormModal from '../business/accommodation/RoomFormModal'

/**
 * Review queue for rooms, menu items and retail products:
 * - "New items": items waiting for their first approval (status pending).
 * - "Proposed changes": edits to approved items; the approved version stays
 *   public until the change is approved (approval echoes the server's hash,
 *   so the exact reviewed version is what gets applied).
 * - "Rejected new items": kept for the record until the owner corrects them.
 * Approving an item never publishes its parent listing.
 * Filters live in the URL, so they survive a refresh and the Back button.
 */

const DEFAULTS = { tab: 'new', kind: '', business: '', q: '', from: '', to: '', sort: 'newest', page: '1', per_page: '25' }
const PER_PAGE_OPTIONS = ['25', '50', '100']
const SEARCH_DELAY_MS = 300

const TH = 'text-left px-3 py-2.5 text-xs font-semibold uppercase tracking-wider text-gray-500'
const TD = 'px-3 py-2 text-sm text-gray-600 align-top'

const STATUS = {
  new: ['Pending', 'bg-yellow-100 text-yellow-800'],
  changes: ['Change proposed', 'bg-blue-100 text-blue-700'],
  rejected: ['Rejected', 'bg-red-100 text-red-700'],
}

const plural = (count, word = 'item') => `${count} ${word}${count === 1 ? '' : 's'}`
const verb = (action) => (action === 'approve' ? 'approved' : 'rejected')

function SelectAll({ checked, indeterminate, onChange, disabled }) {
  return (
    <input
      type="checkbox"
      aria-label="Select all items on this page"
      className="h-4 w-4 rounded border-gray-300 text-primary-600"
      checked={checked}
      disabled={disabled}
      ref={(element) => { if (element) element.indeterminate = indeterminate }}
      onChange={onChange}
    />
  )
}

export default function ReviewItems() {
  const [params, setParams] = useSearchParams()
  const query = params.toString()
  const filter = (name) => params.get(name) ?? DEFAULTS[name]
  const tab = REVIEW_TABS.some((option) => option.value === filter('tab')) ? filter('tab') : 'new'
  const isChange = tab === 'changes'
  const isRejected = tab === 'rejected'

  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState('')
  const [searchText, setSearchText] = useState(filter('q'))
  const [selected, setSelected] = useState(() => new Map())
  const [reviewingKey, setReviewingKey] = useState(null)
  const [editingOption, setEditingOption] = useState(null)
  const [busy, setBusy] = useState(false)
  const [bulk, setBulk] = useState(null) // { action, notes, error }
  const [outcome, setOutcome] = useState(null) // { action, succeeded, failures }
  const latestRequest = useRef(0)
  const searchTimer = useRef(null)

  const update = useCallback((patch, { keepSelection = false } = {}) => {
    setParams((current) => {
      const next = new URLSearchParams(current)
      Object.entries(patch).forEach(([name, value]) => {
        if (value && value !== DEFAULTS[name]) next.set(name, value)
        else next.delete(name)
      })
      if (!('page' in patch)) next.delete('page')
      return next
    }, { replace: true })
    if (!keepSelection) setSelected(new Map())
  }, [setParams])

  const load = useCallback(async () => {
    const request = ++latestRequest.current
    setLoading(true)
    try {
      const result = await getItemReviews(Object.fromEntries(new URLSearchParams(query)))
      if (request !== latestRequest.current) return null
      setLoadError('')
      setData({ ...result, forTab: tab })
      if (result.data.length === 0 && result.current_page > 1) {
        update({ page: String(result.last_page) }, { keepSelection: true })
      }
      return result
    } catch (error) {
      if (request === latestRequest.current) setLoadError(reviewError(error, 'Could not load the review queue.'))
      return null
    } finally {
      if (request === latestRequest.current) setLoading(false)
    }
  }, [query, tab, update])

  useEffect(() => {
    load()
  }, [load])

  // Rows from another tab must never sit under this tab's actions while the new ones load.
  const showing = data?.forTab === tab ? data : null
  const rows = showing?.data ?? []
  const counts = data?.counts ?? {}
  const reviewingIndex = rows.findIndex((entry) => entry.key === reviewingKey)
  const reviewing = reviewingIndex >= 0 ? rows[reviewingIndex] : null
  const pageSelected = rows.filter((entry) => selected.has(entry.key)).length
  const selectedEntries = [...selected.values()]
  const unpublished = selectedEntries.filter(({ kind, row }) => {
    const parent = ITEM_KINDS[kind].parent(row)
    return parent && parent.status !== 'approved'
  }).length
  const hasFilters = ['kind', 'business', 'q', 'from', 'to'].some((name) => filter(name))
  const columns = isRejected ? 6 : 8

  const toggleRow = (entry) => setSelected((current) => {
    const next = new Map(current)
    if (next.has(entry.key)) next.delete(entry.key)
    else next.set(entry.key, entry)
    return next
  })

  const togglePage = () => setSelected((current) => {
    const next = new Map(current)
    if (pageSelected === rows.length) rows.forEach((entry) => next.delete(entry.key))
    else rows.forEach((entry) => next.set(entry.key, entry))
    return next
  })

  const onSearch = (value) => {
    setSearchText(value)
    clearTimeout(searchTimer.current)
    searchTimer.current = setTimeout(() => update({ q: value.trim() }), SEARCH_DELAY_MS)
  }

  const clearFilters = () => {
    clearTimeout(searchTimer.current)
    setSearchText('')
    update({ kind: '', business: '', q: '', from: '', to: '' })
  }

  const decide = (action, entries, notes) => bulkReviewItems({
    action,
    scope: tab,
    notes: notes || undefined,
    items: entries.map(({ kind, id, row }) => ({ kind, id, changes_hash: isChange ? row.pending_changes_hash : undefined })),
  })

  const decideOne = async (action, notes) => {
    const entry = reviewing
    const nextKey = rows[reviewingIndex + 1]?.key ?? rows[reviewingIndex - 1]?.key ?? null
    setBusy(true)
    try {
      const result = await decide(action, [entry], notes)
      const [item] = result.results
      if (item.ok) {
        toast.success(item.message || `Item ${verb(action)}.`)
        setSelected((current) => {
          const next = new Map(current)
          next.delete(entry.key)
          return next
        })
        setReviewingKey(nextKey)
      } else {
        toast.error(item.message || 'This item could not be processed.')
      }
      await load()
    } catch (error) {
      toast.error(reviewError(error, 'This item could not be processed.'))
    } finally {
      setBusy(false)
    }
  }

  const submitBulk = async (event) => {
    event.preventDefault()
    const notes = bulk.notes.trim()
    if (bulk.action === 'reject' && notes.length < (isChange ? 5 : 1)) {
      setBulk({ ...bulk, error: isChange ? 'Please give a reason of at least 5 characters, so the owners can correct it.' : 'Please tell the owners why, so they can correct it.' })
      return
    }

    setBusy(true)
    try {
      const result = await decide(bulk.action, selectedEntries, notes)
      const failures = result.results.filter((item) => !item.ok)
      setBulk(null)
      setSelected(new Map())
      if (failures.length) setOutcome({ action: bulk.action, succeeded: result.succeeded, failures })
      else toast.success(`${plural(result.succeeded)} ${verb(bulk.action)}. 0 failed.`)
      await load()
    } catch (error) {
      setBulk((current) => current && { ...current, error: reviewError(error, 'Could not process these items. Nothing was changed.') })
    } finally {
      setBusy(false)
    }
  }

  const currentBusiness = filter('business')
  const businessOptions = data?.businesses ?? []
  const count = selected.size

  const listControls = (
    <>
      {hasFilters && <button type="button" onClick={clearFilters} className="text-sm text-gray-500 underline hover:text-gray-800">Clear filters</button>}
      <label className="flex items-center gap-1.5 text-xs font-medium text-gray-600">
        Sort
        <select className="input-field py-1 w-auto" value={filter('sort')} onChange={(event) => update({ sort: event.target.value }, { keepSelection: true })}>
          <option value="newest">Newest first</option>
          <option value="oldest">Oldest first</option>
        </select>
      </label>
    </>
  )

  return (
    <div>
      <div className="mb-5">
        <p className="text-primary-600 font-medium mb-2">Admin</p>
        <h1 className="text-2xl sm:text-3xl font-heading font-bold text-gray-900">Rooms, Menus &amp; Products</h1>
        <p className="text-sm text-gray-500 mt-2 max-w-3xl">
          Approve new rooms, dishes and products, and review changes to approved ones. Approved versions stay visible to
          tourists until a change is approved. Approving an item does not publish its listing.
        </p>
      </div>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
        <StatCard icon={FiInbox} label="Pending New Items" value={counts.new ?? '—'} accent="yellow" />
        <StatCard icon={FiEdit3} label="Pending Changes" value={counts.changes ?? '—'} />
        <StatCard icon={FiXCircle} label="Rejected Items" value={counts.rejected ?? '—'} accent="red" />
        <StatCard icon={FiLayers} label="Total Submissions" value={counts.total ?? '—'} accent="green" />
      </div>

      <div className="flex flex-wrap items-center gap-2 mb-4" role="tablist" aria-label="Review type">
        {REVIEW_TABS.map((option) => (
          <button key={option.value} type="button" role="tab" aria-selected={tab === option.value}
            onClick={() => update({ tab: option.value, business: '' })}
            className={`min-h-9 px-3.5 rounded-full text-sm font-medium border transition-colors ${tab === option.value ? 'bg-primary-600 text-white border-primary-600' : 'bg-white text-gray-700 border-gray-200 hover:bg-gray-50'}`}>
            {option.label} <span className="tabular-nums opacity-80">({counts[option.value] ?? 0})</span>
          </button>
        ))}
        <button type="button" onClick={load} disabled={loading} className="ml-auto inline-flex items-center gap-1.5 min-h-9 px-3 text-sm text-gray-600 hover:text-gray-900 disabled:opacity-50">
          <FiRefreshCw size={14} aria-hidden="true" className={loading ? 'animate-spin' : ''} /> Refresh
        </button>
      </div>

      <div className="bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) p-3 sm:p-4 mb-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3">
        <label className="text-xs font-medium text-gray-700 sm:col-span-2 lg:col-span-2">
          Search
          <span className="relative block mt-1">
            <FiSearch size={15} aria-hidden="true" className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
            <input type="search" className="input-field py-1.5 pl-9" placeholder="Item or business name" value={searchText} onChange={(event) => onSearch(event.target.value)} />
          </span>
        </label>
        <label className="text-xs font-medium text-gray-700">
          Item type
          <select className="input-field py-1.5 mt-1" value={filter('kind')} onChange={(event) => update({ kind: event.target.value, business: currentBusiness && event.target.value && !currentBusiness.startsWith(`${event.target.value}:`) ? '' : currentBusiness })}>
            <option value="">All</option>
            {Object.entries(ITEM_KINDS).map(([value, config]) => <option key={value} value={value}>{config.plural}</option>)}
          </select>
        </label>
        <label className="text-xs font-medium text-gray-700 lg:col-span-1">
          Business
          <select className="input-field py-1.5 mt-1" value={currentBusiness} onChange={(event) => update({ business: event.target.value })}>
            <option value="">All businesses</option>
            {currentBusiness && !businessOptions.some((option) => option.key === currentBusiness) && <option value={currentBusiness}>Selected business (nothing left)</option>}
            {businessOptions.map((option) => <option key={option.key} value={option.key}>{option.name}</option>)}
          </select>
        </label>
        <label className="text-xs font-medium text-gray-700">
          {isRejected ? 'Rejected from' : 'Submitted from'}
          <input type="date" className="input-field py-1.5 mt-1" value={filter('from')} max={filter('to') || undefined} onChange={(event) => update({ from: event.target.value })} />
        </label>
        <label className="text-xs font-medium text-gray-700">
          To
          <input type="date" className="input-field py-1.5 mt-1" value={filter('to')} min={filter('from') || undefined} onChange={(event) => update({ to: event.target.value })} />
        </label>
      </div>

      <section className="bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card)" aria-label="Submissions">
        <div className="sticky top-16 z-10 bg-white rounded-t-2xl border-b border-gray-200 px-3 sm:px-4 py-2.5 flex flex-wrap items-center gap-x-3 gap-y-2">
          {!isRejected && (
            <>
              <p className="text-sm font-semibold text-gray-900 tabular-nums" aria-live="polite">{count} selected</p>
              {count > 0 && <button type="button" onClick={() => setSelected(new Map())} className="text-sm text-gray-500 underline hover:text-gray-800">Clear</button>}
              <button type="button" disabled={!count || busy} onClick={() => setBulk({ action: 'approve', notes: '', error: '' })}
                className="inline-flex items-center gap-1.5 min-h-9 px-3 rounded-lg bg-green-600 text-white text-sm font-semibold hover:bg-green-700 disabled:opacity-40 disabled:cursor-not-allowed">
                <FiCheck size={15} aria-hidden="true" /> <span><span className="hidden sm:inline">Bulk </span>Approve</span>
              </button>
              <button type="button" disabled={!count || busy} onClick={() => setBulk({ action: 'reject', notes: '', error: '' })}
                className="inline-flex items-center gap-1.5 min-h-9 px-3 rounded-lg border border-red-200 text-red-700 text-sm font-semibold hover:bg-red-50 disabled:opacity-40 disabled:cursor-not-allowed">
                <FiX size={15} aria-hidden="true" /> <span><span className="hidden sm:inline">Bulk </span>Reject</span>
              </button>
            </>
          )}
          <div className="hidden sm:flex ml-auto flex-wrap items-center gap-3">{listControls}</div>
        </div>
        <div className="sm:hidden flex flex-wrap items-center justify-end gap-3 px-3 py-2 border-b border-gray-100">{listControls}</div>

        {loading && !showing ? (
          <div className="py-16"><LoadingSpinner size="lg" /></div>
        ) : loadError ? (
          <div role="alert" className="m-4 bg-red-50 border border-red-200 text-red-800 rounded-xl p-4 text-sm flex flex-wrap items-center gap-3">
            <FiAlertTriangle aria-hidden="true" /> {loadError}
            <button type="button" onClick={load} className="font-semibold underline">Try again</button>
          </div>
        ) : (
          <table className={`w-full ${loading ? 'opacity-70' : ''} transition-opacity`}>
            <thead className="bg-gray-50/80 border-b border-gray-200">
              <tr>
                {!isRejected && <th scope="col" className="pl-3 sm:pl-4 pr-1 py-2.5 w-8"><SelectAll checked={rows.length > 0 && pageSelected === rows.length} indeterminate={pageSelected > 0 && pageSelected < rows.length} disabled={rows.length === 0} onChange={togglePage} /></th>}
                <th scope="col" className={`${TH} ${isRejected ? 'pl-3 sm:pl-4' : ''}`}>Item Name</th>
                <th scope="col" className={`${TH} hidden md:table-cell`}>Type</th>
                <th scope="col" className={`${TH} hidden md:table-cell`}>Business</th>
                {isRejected ? (
                  <>
                    <th scope="col" className={`${TH} hidden lg:table-cell`}>Rejection Reason</th>
                    <th scope="col" className={`${TH} hidden sm:table-cell`}>Date Rejected</th>
                  </>
                ) : (
                  <>
                    <th scope="col" className={`${TH} hidden lg:table-cell`}>Price</th>
                    <th scope="col" className={`${TH} hidden sm:table-cell`}>Submitted</th>
                    <th scope="col" className={`${TH} hidden xl:table-cell`}>Status</th>
                  </>
                )}
                <th scope="col" className={`${TH} text-right pr-3 sm:pr-4`}>Actions</th>
              </tr>
            </thead>
            <tbody>
              {rows.length === 0 && (
                <tr><td colSpan={columns} className="px-6 py-12 text-center text-sm text-gray-500">{hasFilters ? 'No submissions match these filters.' : 'Nothing to review here.'}</td></tr>
              )}
              {rows.map((entry) => {
                const { kind, row } = entry
                const config = ITEM_KINDS[kind]
                const parent = config.parent(row)
                const [statusLabel, statusClass] = STATUS[tab]
                const isSelected = selected.has(entry.key)
                return (
                  <tr key={entry.key} className={`border-b border-gray-100 last:border-b-0 transition-colors duration-150 ${isSelected ? 'bg-primary-50/60' : 'hover:bg-primary-50/40'}`}>
                    {!isRejected && (
                      <td className="pl-3 sm:pl-4 pr-1 py-2 align-top">
                        <input type="checkbox" aria-label={`Select ${entry.name}`} className="mt-0.5 h-4 w-4 rounded border-gray-300 text-primary-600" checked={isSelected} onChange={() => toggleRow(entry)} />
                      </td>
                    )}
                    <td className={`${TD} ${isRejected ? 'pl-3 sm:pl-4' : ''}`}>
                      <p className="font-semibold text-gray-900 wrap-anywhere">{entry.name}</p>
                      <p className="md:hidden text-xs text-gray-500 wrap-anywhere">{config.type} · {entry.business_name || 'Unknown listing'}</p>
                      {!isRejected && <p className="lg:hidden text-xs text-gray-500">{priceLabel(kind, row, isChange)}</p>}
                    </td>
                    <td className={`${TD} hidden md:table-cell whitespace-nowrap`}>{config.type}</td>
                    <td className={`${TD} hidden md:table-cell`}>
                      <p className="wrap-anywhere">{entry.business_name || 'Unknown listing'}</p>
                      {parent && parent.status !== 'approved' && (
                        <span className="inline-flex items-center gap-1 text-xs text-gray-500"><FiClock size={11} aria-hidden="true" /> Listing not published</span>
                      )}
                    </td>
                    {isRejected ? (
                      <>
                        <td className={`${TD} hidden lg:table-cell`}><p className="line-clamp-2 wrap-anywhere">{row.admin_notes || '—'}</p></td>
                        <td className={`${TD} hidden sm:table-cell whitespace-nowrap`}>{formatShortDate(entry.submitted_on)}</td>
                      </>
                    ) : (
                      <>
                        <td className={`${TD} hidden lg:table-cell whitespace-nowrap`}>{priceLabel(kind, row, isChange)}</td>
                        <td className={`${TD} hidden sm:table-cell whitespace-nowrap`}>{formatShortDate(entry.submitted_on)}</td>
                        <td className={`${TD} hidden xl:table-cell whitespace-nowrap`}><span className={`text-xs font-medium px-2 py-0.5 rounded-full ${statusClass}`}>{statusLabel}</span></td>
                      </>
                    )}
                    <td className={`${TD} text-right pr-3 sm:pr-4`}>
                      <button type="button" onClick={() => setReviewingKey(entry.key)} aria-label={`View ${entry.name}`} className="inline-flex items-center min-h-8 px-3 rounded-lg border border-gray-200 text-gray-700 text-sm font-semibold hover:bg-gray-50">View</button>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        )}

        {showing && showing.total > 0 && (
          <div className="border-t border-gray-100 px-3 sm:px-4 py-3 flex flex-wrap items-center justify-between gap-3">
            <p className="text-xs text-gray-500 tabular-nums">Showing {showing.from}–{showing.to} of {showing.total}</p>
            <div className="[&>div]:mt-0">
              <Pagination currentPage={showing.current_page} lastPage={showing.last_page} onPageChange={(page) => update({ page: String(page) }, { keepSelection: true })} />
            </div>
            <label className="flex items-center gap-1.5 text-xs font-medium text-gray-600">
              Rows
              <select className="input-field py-1 w-auto" value={filter('per_page')} onChange={(event) => update({ per_page: event.target.value }, { keepSelection: true })}>
                {PER_PAGE_OPTIONS.map((value) => <option key={value} value={value}>{value}</option>)}
              </select>
            </label>
          </div>
        )}
      </section>

      {reviewing && (
        <ItemReviewModal
          key={reviewing.key}
          entry={reviewing}
          tab={tab}
          busy={busy}
          onClose={() => setReviewingKey(null)}
          onDecide={decideOne}
          onCorrect={() => { setEditingOption(reviewing.row); setReviewingKey(null) }}
          onPrevious={reviewingIndex > 0 ? () => setReviewingKey(rows[reviewingIndex - 1].key) : null}
          onNext={reviewingIndex < rows.length - 1 ? () => setReviewingKey(rows[reviewingIndex + 1].key) : null}
        />
      )}

      {bulk && (
        <Modal
          size="sm"
          busy={busy}
          onClose={() => setBulk(null)}
          title={bulk.action === 'approve' ? 'Confirm bulk approval' : 'Confirm bulk rejection'}
          footer={(
            <>
              <button type="button" onClick={() => setBulk(null)} disabled={busy} className="min-h-10 px-4 rounded-lg text-sm text-gray-600 hover:bg-gray-100 disabled:opacity-50">Cancel</button>
              <button type="submit" form="bulk-review-form" disabled={busy}
                className={`min-h-10 px-4 rounded-lg text-white text-sm font-semibold disabled:opacity-50 ${bulk.action === 'approve' ? 'bg-green-600 hover:bg-green-700' : 'bg-red-600 hover:bg-red-700'}`}>
                {busy ? 'Working…' : `${bulk.action === 'approve' ? 'Approve' : 'Reject'} ${plural(count)}`}
              </button>
            </>
          )}
        >
          <form id="bulk-review-form" onSubmit={submitBulk} className="space-y-3">
            <p className="text-sm text-gray-800">
              Are you sure you want to {bulk.action} {isChange ? 'the proposed changes for ' : ''}{count} selected item{count === 1 ? '' : 's'}?
            </p>
            {bulk.action === 'approve' && unpublished > 0 && !isChange && (
              <p className="text-xs text-gray-600 bg-gray-50 rounded-lg p-2.5">
                {plural(unpublished)} belong{unpublished === 1 ? 's' : ''} to a listing that isn&apos;t published yet, and will stay hidden until that listing passes publication review.
              </p>
            )}
            {bulk.action === 'approve' && isChange && <p className="text-xs text-gray-600 bg-gray-50 rounded-lg p-2.5">Each approved version will be replaced with the proposed one.</p>}
            {bulk.action === 'reject' && (
              <div>
                <label htmlFor="bulk-review-notes" className="block text-sm font-medium text-gray-700 mb-1">Reason (shown to every owner)</label>
                <textarea id="bulk-review-notes" autoFocus className="input-field min-h-20" maxLength={1000} value={bulk.notes} aria-invalid={Boolean(bulk.error)} onChange={(event) => setBulk({ ...bulk, notes: event.target.value, error: '' })} />
                <p className="text-xs text-gray-500 mt-1">{isChange ? 'The approved versions stay public.' : 'These items stay hidden from tourists.'}</p>
              </div>
            )}
            {bulk.error && <p role="alert" className="text-sm text-red-600">{bulk.error}</p>}
          </form>
        </Modal>
      )}

      {outcome && (
        <Modal
          title="Bulk action finished"
          subtitle={`${outcome.succeeded} ${verb(outcome.action)}, ${outcome.failures.length} failed`}
          onClose={() => setOutcome(null)}
          footer={<button type="button" onClick={() => setOutcome(null)} className="min-h-10 px-4 rounded-lg bg-primary-600 text-white text-sm font-semibold hover:bg-primary-700">Done</button>}
        >
          <ul className="space-y-2">
            {outcome.failures.map((item) => (
              <li key={`${item.kind}-${item.id}`} className="text-sm border-b border-gray-100 pb-2 last:border-b-0">
                <p className="font-semibold text-gray-900 wrap-anywhere">{item.name || `${ITEM_KINDS[item.kind].type} #${item.id}`}</p>
                <p className="text-red-700">{item.message}</p>
              </li>
            ))}
          </ul>
        </Modal>
      )}

      {editingOption && (
        <RoomFormModal
          room={editingOption}
          showPhotos={false}
          save={(payload) => updateAccommodationRoom(editingOption.id, payload)}
          onClose={() => setEditingOption(null)}
          onSaved={() => { setEditingOption(null); load() }}
        />
      )}
    </div>
  )
}
