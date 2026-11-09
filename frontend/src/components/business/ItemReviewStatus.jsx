import { FiClock, FiAlertTriangle } from 'react-icons/fi'
import { getImageUrl } from '../../utils/helpers'

function display(value, format) {
  if (value === null || value === undefined || value === '') return '—'
  if (Array.isArray(value)) return value.length ? value.join(', ') : '—'
  return format ? format(value) : String(value)
}

/**
 * Owner-facing review state of a room, menu item or product:
 *  - new item pending / rejected (with the admin's reason);
 *  - proposed changes to an approved item: approved (public) vs proposed values,
 *    waiting for review or rejected with a reason.
 * fields: [[key, label, format?], ...]
 */
export default function ItemReviewStatus({ item, fields, noun = 'item' }) {
  const proposal = item.pending_changes
  const values = proposal?.values || {}
  const changed = fields.filter(([key]) => key in values)
  const newImages = proposal?.new_images || []
  const removals = proposal?.remove_image_ids?.length || 0

  if (item.status === 'pending') {
    return (
      <p className="text-xs text-yellow-800 bg-yellow-50 rounded-lg p-2 mb-2 flex gap-1.5">
        <FiClock className="shrink-0 mt-0.5" aria-hidden="true" />
        Waiting for Tourism Office approval. Tourists can&apos;t see this {noun} yet.
      </p>
    )
  }

  if (item.status === 'rejected') {
    return (
      <div className="text-xs text-red-800 bg-red-50 rounded-lg p-2 mb-2">
        <p className="font-semibold flex items-center gap-1.5"><FiAlertTriangle aria-hidden="true" /> Not approved</p>
        {item.admin_notes && <p className="mt-1 whitespace-pre-line">{item.admin_notes}</p>}
        <p className="mt-1">Edit this {noun} to correct it; saving sends it back for review.</p>
      </div>
    )
  }

  if (!proposal || !['pending', 'rejected'].includes(proposal.status)) return null

  const rejected = proposal.status === 'rejected'

  return (
    <div className={`text-xs rounded-lg p-2 mb-2 ${rejected ? 'bg-red-50 text-red-800' : 'bg-blue-50 text-blue-900'}`}>
      <p className="font-semibold flex items-center gap-1.5">
        {rejected ? <FiAlertTriangle aria-hidden="true" /> : <FiClock aria-hidden="true" />}
        {rejected ? 'Your proposed changes were not approved' : 'Changes waiting for review'}
      </p>
      {rejected && proposal.notes && <p className="mt-1 whitespace-pre-line">{proposal.notes}</p>}
      <p className="mt-1">
        {rejected
          ? 'Tourists still see the approved version. Edit the ' + noun + ' to correct and resubmit.'
          : 'Tourists still see the approved version until the Tourism Office approves these changes.'}
      </p>

      {changed.length > 0 && (
        <table className="w-full mt-2 text-left">
          <thead>
            <tr className="opacity-70">
              <th className="font-medium pr-2"></th>
              <th className="font-medium pr-2">Approved (public)</th>
              <th className="font-medium">Proposed</th>
            </tr>
          </thead>
          <tbody>
            {changed.map(([key, label, format]) => (
              <tr key={key} className="align-top">
                <th scope="row" className="font-medium pr-2 py-0.5">{label}</th>
                <td className="pr-2 py-0.5 wrap-anywhere">{display(item[key], format)}</td>
                <td className="py-0.5 wrap-anywhere font-medium">{display(values[key], format)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      {(newImages.length > 0 || removals > 0) && (
        <div className="mt-2">
          <p>
            {newImages.length > 0 && `${newImages.length} new photo${newImages.length === 1 ? '' : 's'} proposed. `}
            {removals > 0 && `${removals} photo${removals === 1 ? '' : 's'} to remove.`}
          </p>
          {newImages.length > 0 && (
            <div className="flex gap-1.5 mt-1">
              {newImages.map((image, index) => (
                <img key={image.image_path || index} src={getImageUrl(image, { thumbnail: true })} alt={`Proposed photo ${index + 1}`} className="w-10 h-10 rounded object-cover" />
              ))}
            </div>
          )}
        </div>
      )}
    </div>
  )
}
