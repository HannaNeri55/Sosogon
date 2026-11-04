// frontend/src/pages/admin/ReviewItems.jsx
import { useCallback, useEffect, useState } from 'react'
import { FiCheck, FiX, FiRefreshCw, FiAlertTriangle } from 'react-icons/fi'
import toast from 'react-hot-toast'
import {
  getAdminAccommodationRooms, approveAccommodationRoom, rejectAccommodationRoom,
  getAdminMenuItems, approveMenuItem, rejectMenuItem,
  getAdminRetailProducts, approveRetailProduct, rejectRetailProduct,
  approveItemChanges, rejectItemChanges,
} from '../../api/admin'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import { getImageUrl, formatShortDate } from '../../utils/helpers'

/**
 * Review queue for rooms, menu items and retail products (Option 5b):
 * - "New items": items waiting for their first approval (status pending).
 * - "Proposed changes": edits to approved items; the approved version stays
 *   public until the change is approved (approval echoes the server's hash,
 *   so the exact reviewed version is what gets applied).
 * Approving an item never publishes its parent listing.
 */

const KINDS = {
  rooms: {
    label: 'Rooms', endpoint: 'accommodation-rooms', fetch: getAdminAccommodationRooms, approve: approveAccommodationRoom, reject: rejectAccommodationRoom,
    parent: (row) => row.accommodation,
    owner: (row) => row.accommodation?.user,
    municipality: (row) => row.accommodation?.municipality,
    title: (row) => [row.room_number, row.category].filter(Boolean).join(' · '),
    images: (row) => row.images || [],
    fields: [['room_number', 'Room'], ['category', 'Category'], ['price', 'Price'], ['description', 'Description'], ['room_features', 'Features']],
  },
  menu: {
    label: 'Menu items', endpoint: 'menu-items', fetch: getAdminMenuItems, approve: approveMenuItem, reject: rejectMenuItem,
    parent: (row) => row.food_beverage,
    owner: (row) => row.food_beverage?.user,
    municipality: (row) => row.food_beverage?.municipality,
    title: (row) => row.name,
    images: (row) => (row.image_path ? [row] : []),
    fields: [['name', 'Name'], ['price', 'Price'], ['description', 'Description']],
  },
  products: {
    label: 'Products', endpoint: 'retail-products', fetch: getAdminRetailProducts, approve: approveRetailProduct, reject: rejectRetailProduct,
    parent: (row) => row.shop,
    owner: (row) => row.shop?.user || row.user,
    municipality: (row) => row.municipality,
    title: (row) => row.name,
    images: (row) => row.images || [],
    fields: [['name', 'Name'], ['product_type', 'Type'], ['price', 'Price'], ['description', 'Description'], ['website', 'Website']],
  },
}

const REVIEW_FILTERS = [
  { value: 'new', label: 'New items', params: { status: 'pending' } },
  { value: 'changes', label: 'Proposed changes', params: { changes: 'pending' } },
  { value: 'rejected', label: 'Rejected new items', params: { status: 'rejected' } },
]

const PER_PAGE = 200

function display(value) {
  if (value === null || value === undefined || value === '') return '—'
  if (Array.isArray(value)) return value.length ? value.join(', ') : '—'
  return String(value)
}

function sameValue(a, b) {
  if (Array.isArray(a) || Array.isArray(b)) return JSON.stringify(a ?? []) === JSON.stringify(b ?? [])
  if (!Number.isNaN(Number(a)) && !Number.isNaN(Number(b)) && a !== '' && b !== '' && a !== null && b !== null) return Number(a) === Number(b)
  return (a ?? '') === (b ?? '')
}

function errorMessage(error, fallback) {
  const data = error?.response?.data
  return Object.values(data?.errors || {}).flat()[0] || data?.message || fallback
}

function Thumbs({ images, removedIds = [], label }) {
  if (!images.length) return <p className="text-xs text-gray-500">No photos.</p>
  return (
    <div className="flex flex-wrap gap-2">
      {images.map((image, index) => {
        const removed = image.id && removedIds.includes(image.id)
        return (
          <figure key={image.id || image.image_path || index} className="relative">
            <img src={getImageUrl(image, { thumbnail: true })} alt={`${label} ${index + 1}`} className={`w-20 h-20 object-cover rounded-lg border ${removed ? 'opacity-40 border-red-300' : 'border-gray-200'}`} />
            {removed && <figcaption className="absolute inset-x-0 bottom-0 text-center text-[11px] font-semibold bg-red-600 text-white rounded-b-lg">Remove</figcaption>}
          </figure>
        )
      })}
    </div>
  )
}

export default function ReviewItems() {
  const [kindFilter, setKindFilter] = useState('all')
  const [reviewFilter, setReviewFilter] = useState('new')
  const [rows, setRows] = useState([])
  const [truncated, setTruncated] = useState([])
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState('')
  const [actingKey, setActingKey] = useState(null)
  const [rejecting, setRejecting] = useState(null) // { key, notes, error }

  const load = useCallback(async () => {
    setLoading(true)
    setLoadError('')
    const params = { ...REVIEW_FILTERS.find((f) => f.value === reviewFilter).params, per_page: PER_PAGE }
    const kinds = kindFilter === 'all' ? Object.keys(KINDS) : [kindFilter]
    try {
      const pages = await Promise.all(kinds.map((kind) => KINDS[kind].fetch(params).then((page) => ({ kind, page }))))
      setRows(pages.flatMap(({ kind, page }) => (page.data || []).map((row) => ({ kind, row }))))
      setTruncated(pages.filter(({ page }) => (page.total ?? 0) > (page.data || []).length).map(({ kind }) => KINDS[kind].label))
    } catch (error) {
      setLoadError(errorMessage(error, 'Could not load the review queue.'))
    } finally {
      setLoading(false)
    }
  }, [kindFilter, reviewFilter])

  useEffect(() => {
    load()
  }, [load])

  const isChange = reviewFilter === 'changes'

  const approve = async ({ kind, row }) => {
    const key = `${kind}-${row.id}`
    if (actingKey) return
    const config = KINDS[kind]
    const question = isChange
      ? `Apply the proposed changes to "${config.title(row)}"? The approved version will be replaced with the proposed one.`
      : `Approve "${config.title(row)}"? It becomes visible to tourists once its ${config.label === 'Rooms' ? 'accommodation' : 'listing'} is published.`
    if (!window.confirm(question)) return

    setActingKey(key)
    try {
      const response = isChange
        ? await approveItemChanges(config.endpoint, row.id, row.pending_changes_hash)
        : await config.approve(row.id)
      toast.success(response.message || 'Approved.')
      await load()
    } catch (error) {
      toast.error(errorMessage(error, 'Could not approve this item.'))
      if (error.response?.status === 409 || error.response?.status === 404) await load()
    } finally {
      setActingKey(null)
    }
  }

  const submitRejection = async (event, { kind, row }) => {
    event.preventDefault()
    if (actingKey || !rejecting) return
    const notes = rejecting.notes.trim()
    const minimum = isChange ? 5 : 1
    if (notes.length < minimum) {
      setRejecting({ ...rejecting, error: 'Please tell the owner why, so they can correct it.' })
      return
    }

    setActingKey(rejecting.key)
    try {
      const config = KINDS[kind]
      const response = isChange ? await rejectItemChanges(config.endpoint, row.id, notes) : await config.reject(row.id, notes)
      toast.success(response.message || 'Rejected.')
      setRejecting(null)
      await load()
    } catch (error) {
      setRejecting((current) => current && { ...current, error: errorMessage(error, 'Could not reject this item.') })
      if (error.response?.status === 409 || error.response?.status === 404) await load()
    } finally {
      setActingKey(null)
    }
  }

  return (
    <div>
      <div className="mb-6">
        <p className="text-primary-600 font-medium mb-2">Admin</p>
        <h1 className="text-2xl sm:text-3xl font-heading font-bold text-gray-900">Rooms, Menus &amp; Products</h1>
        <p className="text-sm text-gray-500 mt-2 max-w-3xl">
          Approve new rooms, dishes and products, and review changes to approved ones. Approved versions stay visible to
          tourists until a change is approved. Approving an item does not publish its listing.
        </p>
      </div>

      <div className="flex flex-wrap items-center gap-3 mb-5">
        <div className="flex flex-wrap gap-2" role="tablist" aria-label="Review type">
          {REVIEW_FILTERS.map((option) => (
            <button key={option.value} type="button" role="tab" aria-selected={reviewFilter === option.value}
              onClick={() => { setReviewFilter(option.value); setRejecting(null) }}
              className={`min-h-9 px-3.5 rounded-full text-sm font-medium border transition-colors ${reviewFilter === option.value ? 'bg-primary-600 text-white border-primary-600' : 'bg-white text-gray-700 border-gray-200 hover:bg-gray-50'}`}>
              {option.label}
            </button>
          ))}
        </div>
        <label className="flex items-center gap-2 text-sm text-gray-600">
          Item type
          <select className="input-field py-1.5 w-auto" value={kindFilter} onChange={(e) => { setKindFilter(e.target.value); setRejecting(null) }}>
            <option value="all">All</option>
            {Object.entries(KINDS).map(([value, config]) => <option key={value} value={value}>{config.label}</option>)}
          </select>
        </label>
        <button type="button" onClick={load} disabled={loading} className="ml-auto inline-flex items-center gap-1.5 min-h-9 px-3 text-sm text-gray-600 hover:text-gray-900 disabled:opacity-50">
          <FiRefreshCw size={14} aria-hidden="true" /> Refresh
        </button>
      </div>

      {truncated.length > 0 && !loading && (
        <p className="text-xs text-amber-700 bg-amber-50 rounded-lg p-3 mb-4">Showing the first {PER_PAGE} {truncated.join(', ').toLowerCase()}. Review these, then refresh to load more.</p>
      )}

      {loading ? (
        <LoadingSpinner size="lg" />
      ) : loadError ? (
        <div role="alert" className="bg-red-50 border border-red-200 text-red-800 rounded-xl p-5 text-sm flex flex-wrap items-center gap-3">
          <FiAlertTriangle aria-hidden="true" /> {loadError}
          <button type="button" onClick={load} className="font-semibold underline">Try again</button>
        </div>
      ) : rows.length === 0 ? (
        <div className="bg-white border border-gray-100 rounded-xl p-10 text-center text-sm text-gray-500">Nothing to review here.</div>
      ) : (
        <ul className="space-y-4">
          {rows.map((entry) => {
            const { kind, row } = entry
            const config = KINDS[kind]
            const key = `${kind}-${row.id}`
            const busy = actingKey === key
            const parent = config.parent(row)
            const pending = row.pending_changes
            const proposedValues = pending?.values || {}
            const changedFields = config.fields.filter(([field]) => field in proposedValues)
            const newImages = pending?.new_images || []
            const removedIds = pending?.remove_image_ids || []
            const isRejecting = rejecting?.key === key

            return (
              <li key={key} className="bg-white border border-gray-100 rounded-xl p-5 sm:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3 mb-4">
                  <div className="min-w-0">
                    <p className="text-xs font-medium uppercase tracking-wide text-gray-500">{config.label.replace(/s$/, '')}</p>
                    <h2 className="font-heading font-semibold text-xl text-gray-900 wrap-anywhere">{config.title(row)}</h2>
                    <p className="text-sm text-gray-500">
                      {parent?.name || 'Unknown listing'}
                      {config.municipality(row)?.name ? ` · ${config.municipality(row).name}` : ''}
                      {config.owner(row)?.name ? ` · Owner: ${config.owner(row).name}` : ''}
                    </p>
                  </div>
                  <span className={`text-xs font-medium px-2.5 py-1 rounded-full ${isChange ? 'bg-blue-100 text-blue-700' : row.status === 'rejected' ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-800'}`}>
                    {isChange ? 'Change to approved item' : row.status === 'rejected' ? 'Rejected' : 'New item'}
                  </span>
                </div>

                {parent && parent.status !== 'approved' && (
                  <p className="text-xs text-gray-600 bg-gray-50 rounded-lg p-2.5 mb-3">
                    The listing &quot;{parent.name}&quot; is not published yet ({parent.status}). Approving this item won&apos;t make it public until the listing passes publication review.
                  </p>
                )}

                {isChange ? (
                  <>
                    {changedFields.length > 0 && (
                      <div className="overflow-x-auto mb-4">
                        <table className="w-full min-w-[32rem] text-sm">
                          <thead>
                            <tr className="text-left text-xs text-gray-500 border-b border-gray-100">
                              <th className="py-2 pr-4 font-medium">Field</th>
                              <th className="py-2 pr-4 font-medium">Approved now (public)</th>
                              <th className="py-2 font-medium">Proposed</th>
                            </tr>
                          </thead>
                          <tbody>
                            {changedFields.map(([field, label]) => (
                              <tr key={field} className="border-b border-gray-50 align-top">
                                <th scope="row" className="py-2 pr-4 font-medium text-gray-700">{label}</th>
                                <td className="py-2 pr-4 text-gray-700 whitespace-pre-line wrap-anywhere">{display(row[field])}</td>
                                <td className={`py-2 whitespace-pre-line wrap-anywhere ${sameValue(row[field], proposedValues[field]) ? 'text-gray-700' : 'text-blue-800 font-medium'}`}>{display(proposedValues[field])}</td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      </div>
                    )}
                    <div className="grid gap-4 sm:grid-cols-2 mb-4">
                      <div>
                        <p className="text-xs font-medium text-gray-500 mb-2">Current photos{removedIds.length ? ` (${removedIds.length} to remove)` : ''}</p>
                        <Thumbs images={config.images(row)} removedIds={removedIds} label="Current photo" />
                      </div>
                      {newImages.length > 0 && (
                        <div>
                          <p className="text-xs font-medium text-gray-500 mb-2">{kind === 'rooms' ? 'Photos to add' : 'Replacement photo'}</p>
                          <Thumbs images={newImages} label="Proposed photo" />
                        </div>
                      )}
                    </div>
                    {pending?.submitted_at && <p className="text-xs text-gray-500 mb-3">Proposed {formatShortDate(pending.submitted_at)}</p>}
                  </>
                ) : (
                  <>
                    <dl className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 mb-4">
                      {config.fields.map(([field, label]) => (
                        <div key={field}>
                          <dt className="text-xs text-gray-500">{label}</dt>
                          <dd className="text-sm text-gray-900 whitespace-pre-line wrap-anywhere">{display(row[field])}</dd>
                        </div>
                      ))}
                    </dl>
                    <div className="mb-4"><Thumbs images={config.images(row)} label={`${config.title(row)} photo`} /></div>
                    {row.status === 'rejected' && row.admin_notes && (
                      <p className="text-sm bg-red-50 text-red-800 rounded-lg p-3 mb-3">Rejection reason: {row.admin_notes}</p>
                    )}
                  </>
                )}

                {(isChange || row.status === 'pending') && !isRejecting && (
                  <div className="flex flex-wrap gap-2">
                    <button type="button" onClick={() => approve(entry)} disabled={Boolean(actingKey)}
                      className="inline-flex items-center gap-2 min-h-10 px-4 rounded-lg bg-green-600 text-white text-sm font-semibold hover:bg-green-700 disabled:opacity-50">
                      <FiCheck aria-hidden="true" /> {busy ? 'Working…' : isChange ? 'Approve changes' : 'Approve'}
                    </button>
                    <button type="button" onClick={() => setRejecting({ key, notes: '', error: '' })} disabled={Boolean(actingKey)}
                      className="inline-flex items-center gap-2 min-h-10 px-4 rounded-lg border border-red-200 text-red-700 text-sm font-semibold hover:bg-red-50 disabled:opacity-50">
                      <FiX aria-hidden="true" /> {isChange ? 'Reject changes' : 'Reject'}
                    </button>
                  </div>
                )}
                {row.status === 'rejected' && !isChange && (
                  <p className="text-sm text-gray-500">Waiting for the owner to correct it; their edit sends it back for review.</p>
                )}

                {isRejecting && (
                  <form onSubmit={(event) => submitRejection(event, entry)} className="mt-2 space-y-2">
                    <label htmlFor={`notes-${key}`} className="block text-sm font-medium text-gray-700">Reason (shown to the owner)</label>
                    <textarea id={`notes-${key}`} className="input-field min-h-20" maxLength={1000} required
                      value={rejecting.notes} aria-invalid={Boolean(rejecting.error)} aria-describedby={`notes-help-${key}`}
                      onChange={(e) => setRejecting({ ...rejecting, notes: e.target.value, error: '' })} />
                    <p id={`notes-help-${key}`} className={`text-xs ${rejecting.error ? 'text-red-600' : 'text-gray-500'}`}>
                      {rejecting.error || (isChange ? 'The approved version stays public.' : 'The item stays hidden from tourists.')}
                    </p>
                    <div className="flex flex-wrap gap-2">
                      <button type="submit" disabled={busy} className="min-h-10 px-4 rounded-lg bg-red-600 text-white text-sm font-semibold hover:bg-red-700 disabled:opacity-50">
                        {busy ? 'Rejecting…' : 'Confirm rejection'}
                      </button>
                      <button type="button" onClick={() => setRejecting(null)} disabled={busy} className="min-h-10 px-4 rounded-lg text-sm text-gray-600 hover:bg-gray-100 disabled:opacity-50">Cancel</button>
                    </div>
                  </form>
                )}
              </li>
            )
          })}
        </ul>
      )}
    </div>
  )
}
