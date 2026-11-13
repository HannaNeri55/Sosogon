// frontend/src/components/admin/ItemReviewModal.jsx
import { useState } from 'react'
import { FiCheck, FiChevronLeft, FiChevronRight, FiX } from 'react-icons/fi'
import Modal from '../common/Modal'
import { formatShortDate, getImageUrl } from '../../utils/helpers'
import { ITEM_KINDS, changedFields, displayValue, photosAfterChange, photosChanged } from '../../utils/itemReview'

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

function ProposedChange({ kind, row }) {
  const changes = changedFields(kind, row)
  const config = ITEM_KINDS[kind]
  const hasPhotoChange = photosChanged(row)
  const removedIds = row.pending_changes?.remove_image_ids || []

  return (
    <>
      <p className="text-sm font-semibold text-gray-900 mb-2">
        What changed: {changes.length} field{changes.length === 1 ? '' : 's'}{hasPhotoChange ? ' and photos' : ''}
      </p>
      {changes.length > 0 && (
        <div className="overflow-x-auto mb-4">
          <table className="w-full min-w-[30rem] text-sm">
            <thead>
              <tr className="text-left text-xs text-gray-500 border-b border-gray-100">
                <th scope="col" className="py-2 pr-4 font-medium">Field</th>
                <th scope="col" className="py-2 pr-4 font-medium">Approved now (public)</th>
                <th scope="col" className="py-2 font-medium">Proposed</th>
              </tr>
            </thead>
            <tbody>
              {changes.map(({ field, label, current, proposed }) => (
                <tr key={field} className="border-b border-gray-50 align-top">
                  <th scope="row" className="py-2 pr-4 font-medium text-gray-700">{label}</th>
                  <td className="py-2 pr-4 text-gray-700 whitespace-pre-line wrap-anywhere">{displayValue(current)}</td>
                  <td className="py-2 whitespace-pre-line wrap-anywhere text-blue-800 font-medium">{displayValue(proposed)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {hasPhotoChange ? (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 mb-4">
          <div>
            <p className="text-xs font-medium text-gray-500 mb-2">Photos now (public)</p>
            <Thumbs images={config.images(row)} removedIds={removedIds} label="Current photo" />
          </div>
          <div>
            <p className="text-xs font-medium text-gray-500 mb-2">Photos after approval</p>
            <Thumbs images={photosAfterChange(kind, row)} label="Photo after approval" />
          </div>
        </div>
      ) : (
        <div className="mb-4">
          <p className="text-xs font-medium text-gray-500 mb-2">Photos (unchanged)</p>
          <Thumbs images={config.images(row)} label="Current photo" />
        </div>
      )}
      <p className="text-xs text-gray-500">The approved version stays visible to tourists until this change is approved.</p>
    </>
  )
}

function ItemDetails({ kind, row }) {
  const config = ITEM_KINDS[kind]
  return (
    <>
      <dl className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 mb-4">
        {config.fields.map(([field, label]) => (
          <div key={field}>
            <dt className="text-xs text-gray-500">{label}</dt>
            <dd className="text-sm text-gray-900 whitespace-pre-line wrap-anywhere">{displayValue(row[field])}</dd>
          </div>
        ))}
      </dl>
      <Thumbs images={config.images(row)} label={`${config.title(row)} photo`} />
    </>
  )
}

/** Full details of one queue item, with its decision buttons and previous/next through the page. */
export default function ItemReviewModal({ entry, tab, busy, onClose, onDecide, onCorrect, onPrevious, onNext }) {
  const { kind, row } = entry
  const config = ITEM_KINDS[kind]
  const parent = config.parent(row)
  const isChange = tab === 'changes'
  const decidable = tab !== 'rejected'
  const [rejecting, setRejecting] = useState(false)
  const [notes, setNotes] = useState('')
  const [notesError, setNotesError] = useState('')

  const confirmRejection = (event) => {
    event.preventDefault()
    if (notes.trim().length < (isChange ? 5 : 1)) {
      setNotesError(isChange ? 'Please give a reason of at least 5 characters, so the owner can correct it.' : 'Please tell the owner why, so they can correct it.')
      return
    }
    onDecide('reject', notes.trim())
  }

  const footer = (
    <>
      <div className="flex gap-1 mr-auto">
        <button type="button" onClick={onPrevious} disabled={!onPrevious || busy} aria-label="Previous item" className="inline-flex items-center justify-center min-h-10 min-w-10 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50 disabled:opacity-40"><FiChevronLeft aria-hidden="true" /></button>
        <button type="button" onClick={onNext} disabled={!onNext || busy} aria-label="Next item" className="inline-flex items-center justify-center min-h-10 min-w-10 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50 disabled:opacity-40"><FiChevronRight aria-hidden="true" /></button>
      </div>
      {decidable && !rejecting && (
        <>
          {kind === 'rooms' && (
            <button type="button" onClick={onCorrect} disabled={busy} className="inline-flex items-center min-h-10 px-4 rounded-lg border border-gray-200 text-gray-700 text-sm font-semibold hover:bg-gray-50 disabled:opacity-50">Correct details</button>
          )}
          <button type="button" onClick={() => setRejecting(true)} disabled={busy} className="inline-flex items-center gap-2 min-h-10 px-4 rounded-lg border border-red-200 text-red-700 text-sm font-semibold hover:bg-red-50 disabled:opacity-50">
            <FiX aria-hidden="true" /> {isChange ? 'Reject changes' : 'Reject'}
          </button>
          <button type="button" onClick={() => onDecide('approve')} disabled={busy} className="inline-flex items-center gap-2 min-h-10 px-4 rounded-lg bg-green-600 text-white text-sm font-semibold hover:bg-green-700 disabled:opacity-50">
            <FiCheck aria-hidden="true" /> {busy ? 'Working…' : isChange ? 'Approve changes' : 'Approve'}
          </button>
        </>
      )}
    </>
  )

  return (
    <Modal
      size="lg"
      busy={busy}
      onClose={onClose}
      title={config.title(row)}
      subtitle={[config.type, parent?.name || 'Unknown listing', config.municipality(row)?.name, config.owner(row)?.name && `Owner: ${config.owner(row).name}`].filter(Boolean).join(' · ')}
      footer={footer}
    >
      {parent && parent.status !== 'approved' && (
        <p className="text-xs text-gray-600 bg-gray-50 rounded-lg p-2.5 mb-4">
          The listing &quot;{parent.name}&quot; is not published yet ({parent.status}). Approving this item won&apos;t make it public until the listing passes publication review.
        </p>
      )}

      {tab === 'rejected' && (
        <div className="bg-red-50 text-red-800 rounded-lg p-3 mb-4 text-sm">
          <p className="font-semibold">Rejection reason</p>
          <p className="whitespace-pre-line wrap-anywhere">{row.admin_notes || 'No reason was recorded.'}</p>
          <p className="text-xs mt-1">Rejected {formatShortDate(entry.submitted_on)}. Waiting for the owner to correct it; their edit sends it back for review.</p>
        </div>
      )}

      {isChange ? <ProposedChange kind={kind} row={row} /> : <ItemDetails kind={kind} row={row} />}

      {rejecting && (
        <form onSubmit={confirmRejection} className="mt-5 space-y-2 border-t border-gray-100 pt-4">
          <label htmlFor="item-review-notes" className="block text-sm font-medium text-gray-700">Reason (shown to the owner)</label>
          <textarea id="item-review-notes" autoFocus className="input-field min-h-20" maxLength={1000} value={notes} aria-invalid={Boolean(notesError)} aria-describedby="item-review-notes-help"
            onChange={(event) => { setNotes(event.target.value); setNotesError('') }} />
          <p id="item-review-notes-help" className={`text-xs ${notesError ? 'text-red-600' : 'text-gray-500'}`}>
            {notesError || (isChange ? 'The approved version stays public.' : 'The item stays hidden from tourists.')}
          </p>
          <div className="flex flex-wrap gap-2">
            <button type="submit" disabled={busy} className="min-h-10 px-4 rounded-lg bg-red-600 text-white text-sm font-semibold hover:bg-red-700 disabled:opacity-50">{busy ? 'Rejecting…' : 'Confirm rejection'}</button>
            <button type="button" onClick={() => setRejecting(false)} disabled={busy} className="min-h-10 px-4 rounded-lg text-sm text-gray-600 hover:bg-gray-100 disabled:opacity-50">Cancel</button>
          </div>
        </form>
      )}
    </Modal>
  )
}
