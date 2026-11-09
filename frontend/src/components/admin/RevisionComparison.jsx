import { FiAlertCircle, FiArrowRight } from 'react-icons/fi'
import { getImageUrl, IMAGE_FALLBACK_SVG } from '../../utils/helpers'

const FIELD_LABELS = {
  name: 'Business Name',
  description: 'Description',
  contact_number: 'Contact Number',
  email: 'Email Address',
  website: 'Website',
  operating_hours: 'Operating Hours',
  price_range: 'Price Range',
  amenities: 'Amenities',
  offering_arrangement: 'What the property offers',
  classifications: 'Classifications',
  check_in_time: 'Check-in time',
  check_out_time: 'Check-out time',
  address: 'Address',
  municipality_id: 'Municipality',
}

function normalize(value) {
  if (value === null || value === undefined || value === '') return null
  if (Array.isArray(value)) return JSON.stringify([...value].map(String).sort())
  return String(value).trim()
}

const differs = (a, b) => normalize(a) !== normalize(b)

function display(value) {
  if (Array.isArray(value)) return value.length ? value.join(', ') : '—'
  if (value === null || value === undefined || value === '') return '—'
  return String(value)
}

function Thumb({ image, badge, tone }) {
  return (
    <div className="relative shrink-0">
      <img
        src={getImageUrl(image?.thumbnail_path || image?.image_path)}
        alt=""
        onError={(event) => {
          event.currentTarget.onerror = null
          event.currentTarget.src = IMAGE_FALLBACK_SVG
        }}
        className={`h-16 w-20 rounded-lg object-cover ${tone || ''}`}
      />
      {badge && (
        <span className="absolute inset-x-0 bottom-0 rounded-b-lg bg-black/70 text-center text-[11px] text-white">
          {badge}
        </span>
      )}
    </div>
  )
}

/**
 * Published (live) listing vs the owner's proposed revision: changed fields
 * side by side, and photos added, removed, re-ordered or a new cover.
 * Uses only what the admin detail endpoint returns (listing, its images and
 * the revision's proposed_data / proposed_gallery / base_snapshot).
 */
export default function RevisionComparison({ listing, revision }) {
  const proposedData = revision?.proposed_data || {}
  const base = revision?.base_snapshot || null
  const baseData = base?.data || null

  const municipality = (value) =>
    Number(value) === Number(listing.municipality_id) && listing.municipality?.name
      ? listing.municipality.name
      : value ? `Municipality ID ${value}` : '—'

  const fields = Object.keys(proposedData)
    .map((key) => {
      const live = listing[key]
      const proposed = proposedData[key]
      // With a snapshot, the owner's change is measured against what was
      // published when they started; without one, against the live listing.
      const reference = baseData && key in baseData ? baseData[key] : live
      return {
        key,
        label: FIELD_LABELS[key] || key,
        live,
        proposed,
        ownerChanged: differs(reference, proposed),
        liveChangedSince: Boolean(baseData && key in baseData && differs(baseData[key], live)),
      }
    })
    .filter((field) => field.ownerChanged || field.liveChangedSince)

  const format = (key, value) => (key === 'municipality_id' ? municipality(value) : display(value))

  // Photos: published images vs proposed gallery entries ("existing:<id>" keeps a published photo).
  const published = [...(listing.images || [])].sort(
    (a, b) => Number(a.sort_order ?? 0) - Number(b.sort_order ?? 0) || a.id - b.id
  )
  const publishedKeys = published.map((image) => `existing:${image.id}`)
  const proposedGallery = Array.isArray(revision?.proposed_gallery) ? revision.proposed_gallery : null

  let photos = null
  if (proposedGallery) {
    const proposedKeys = proposedGallery.map((entry) => entry.key)
    const removed = published.filter((image) => !proposedKeys.includes(`existing:${image.id}`))
    const added = proposedGallery.filter((entry) => !publishedKeys.includes(entry.key))
    const keptOrderPublished = publishedKeys.filter((key) => proposedKeys.includes(key))
    const keptOrderProposed = proposedKeys.filter((key) => publishedKeys.includes(key))
    const reordered = keptOrderPublished.some((key, index) => key !== keptOrderProposed[index])
    const publishedCover = published.find((image) => image.is_primary)
    const proposedCover = proposedGallery.find((entry) => entry.is_primary)
    const coverChanged = (publishedCover ? `existing:${publishedCover.id}` : null) !== (proposedCover?.key ?? null)
    const baseKeys = (base?.gallery || []).map((entry) => entry.key)
    const liveGalleryChangedSince = Boolean(base?.gallery) &&
      (baseKeys.length !== publishedKeys.length || baseKeys.some((key, index) => key !== publishedKeys[index]))

    photos = { removed, added, reordered, coverChanged, proposedCover, liveGalleryChangedSince, proposedGallery }
  }

  const hasPhotoChanges = photos && (photos.removed.length || photos.added.length || photos.reordered || photos.coverChanged)

  return (
    <section className="space-y-4">
      <div>
        <h3 className="font-semibold text-gray-900">Published vs Proposed</h3>
        <p className="text-sm text-gray-500">
          Only values the owner changed are listed. The published version stays visible to tourists until approval.
        </p>
      </div>

      {!base && (
        <p className="flex gap-2 rounded-lg bg-gray-50 p-3 text-xs text-gray-600">
          <FiAlertCircle className="mt-0.5 shrink-0" />
          This revision was started before change tracking existed, so it is compared with the current published listing.
        </p>
      )}

      {fields.length === 0 ? (
        <p className="rounded-lg border p-3 text-sm text-gray-600">No listing details were changed.</p>
      ) : (
        <div className="overflow-x-auto rounded-xl border">
          <table className="w-full min-w-[520px] text-left text-sm">
            <thead className="bg-gray-50 text-xs text-gray-500">
              <tr>
                <th className="px-3 py-2 font-medium">Field</th>
                <th className="px-3 py-2 font-medium">Published now</th>
                <th className="px-3 py-2 font-medium">Proposed</th>
              </tr>
            </thead>
            <tbody>
              {fields.map((field) => (
                <tr key={field.key} className="border-t align-top">
                  <th scope="row" className="px-3 py-2 font-medium text-gray-700">
                    {field.label}
                    {field.liveChangedSince && (
                      <span className="mt-1 block text-xs font-normal text-amber-700">
                        {field.ownerChanged
                          ? 'Also changed on the published listing since the owner started — approval will be blocked as a conflict.'
                          : 'Changed on the published listing since the owner started; the published value will be kept.'}
                      </span>
                    )}
                  </th>
                  <td className="whitespace-pre-line px-3 py-2 text-gray-600 wrap-anywhere">{format(field.key, field.live)}</td>
                  <td className={`whitespace-pre-line px-3 py-2 wrap-anywhere ${field.ownerChanged ? 'bg-green-50 font-medium text-gray-900' : 'text-gray-500'}`}>
                    {field.ownerChanged ? format(field.key, field.proposed) : 'Unchanged by owner'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <div className="space-y-3 rounded-xl border p-4">
        <h4 className="text-sm font-semibold text-gray-800">Photos</h4>

        {!photos ? (
          <p className="text-sm text-gray-600">The revision does not include a proposed gallery.</p>
        ) : !hasPhotoChanges ? (
          <p className="text-sm text-gray-600">No photo changes ({published.length} published photos kept as they are).</p>
        ) : (
          <>
            <p className="flex flex-wrap items-center gap-2 text-sm text-gray-700">
              {published.length} published <FiArrowRight aria-hidden="true" /> {photos.proposedGallery.length} proposed
            </p>

            {photos.added.length > 0 && (
              <div>
                <p className="mb-1 text-xs font-medium text-green-700">Added ({photos.added.length})</p>
                <div className="flex gap-2 overflow-x-auto pb-1">
                  {photos.added.map((entry) => <Thumb key={entry.key} image={entry} badge="New" />)}
                </div>
              </div>
            )}

            {photos.removed.length > 0 && (
              <div>
                <p className="mb-1 text-xs font-medium text-red-700">Removed ({photos.removed.length})</p>
                <div className="flex gap-2 overflow-x-auto pb-1">
                  {photos.removed.map((image) => <Thumb key={image.id} image={image} badge="Removed" tone="opacity-60 grayscale" />)}
                </div>
              </div>
            )}

            {photos.coverChanged && (
              <div>
                <p className="mb-1 text-xs font-medium text-gray-700">New cover photo</p>
                {photos.proposedCover ? <Thumb image={photos.proposedCover} badge="Cover" /> : <p className="text-sm text-gray-600">No cover chosen.</p>}
              </div>
            )}

            {photos.reordered && (
              <p className="text-sm text-gray-700">The order of the kept photos was changed (see the proposed gallery above).</p>
            )}
          </>
        )}

        {photos?.liveGalleryChangedSince && (
          <p className="flex gap-2 rounded-lg bg-amber-50 p-2 text-xs text-amber-800">
            <FiAlertCircle className="mt-0.5 shrink-0" />
            The published gallery changed after the owner started this revision.
            {hasPhotoChanges ? ' Approval will be blocked as a conflict.' : ' The current published photos will be kept.'}
          </p>
        )}
      </div>
    </section>
  )
}
