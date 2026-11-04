import { useState } from 'react'
import { FiArrowLeft, FiArrowRight, FiStar, FiTrash2, FiRotateCcw } from 'react-icons/fi'

/**
 * One ordered gallery of saved and newly selected photos. Cover, order and
 * removals are local until the page saves (see usePhotoArrangement).
 * Reorder with the Earlier/Later buttons (keyboard friendly) or by dragging.
 */
export default function ListingPhotoManager({ photos, savedImages, getImageUrl, onRemoveNew, disabled = false, label = 'Listing photo' }) {
  const [dragIndex, setDragIndex] = useState(null)
  const savedById = new Map(savedImages.map((image) => [image.id, image]))
  const total = photos.items.length

  return (
    <div>
      {photos.hasChanges && (
        <p role="status" className="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg p-3 mb-3">
          Photo changes are not saved yet. They are applied when you save.
          {photos.removedCount > 0 && (
            <>
              {' '}{photos.removedCount} saved photo{photos.removedCount === 1 ? '' : 's'} will be removed.{' '}
              <button type="button" onClick={photos.undoRemovals} disabled={disabled} className="inline-flex items-center gap-1 font-semibold underline">
                <FiRotateCcw size={12} aria-hidden="true" /> Undo removals
              </button>
            </>
          )}
        </p>
      )}

      {total === 0 ? (
        <p className="border border-dashed border-gray-200 rounded-xl p-6 text-center text-sm text-gray-500 mb-4">No photos yet.</p>
      ) : (
        <ol className="grid grid-cols-2 sm:grid-cols-3 gap-3 mb-4" aria-label="Photo order">
          {photos.items.map((ref, index) => {
            const isNew = typeof ref !== 'number'
            const isCover = ref === photos.cover
            const src = isNew ? photos.previewUrl(ref) : getImageUrl(savedById.get(ref))
            const name = `${label} ${index + 1} of ${total}`

            return (
              <li
                key={isNew ? `new-${ref.name}-${ref.lastModified}-${index}` : `saved-${ref}`}
                draggable={!disabled}
                onDragStart={() => setDragIndex(index)}
                onDragOver={(e) => e.preventDefault()}
                onDrop={(e) => {
                  e.preventDefault()
                  if (dragIndex !== null) photos.moveTo(dragIndex, index)
                  setDragIndex(null)
                }}
                className={`border-2 rounded-xl overflow-hidden bg-white ${isCover ? 'border-green-600' : isNew ? 'border-amber-300' : 'border-gray-200'} ${dragIndex === index ? 'opacity-50' : ''}`}
              >
                <div className="relative">
                  <img src={src} alt={name} className="w-full h-32 object-cover bg-gray-100" />
                  <span className="absolute top-2 left-2 bg-black/70 text-white text-xs px-2 py-0.5 rounded-md">{index + 1}</span>
                  {isCover && (
                    <span className="absolute bottom-2 left-2 bg-green-700 text-white text-xs px-2 py-1 rounded-md flex items-center gap-1">
                      <FiStar size={12} aria-hidden="true" /> Cover
                    </span>
                  )}
                  <span className={`absolute bottom-2 right-2 text-xs px-2 py-1 rounded-md ${isNew ? 'bg-amber-100 text-amber-800' : 'bg-white/90 text-gray-700'}`}>
                    {isNew ? 'New – not saved' : 'Saved'}
                  </span>
                  <button
                    type="button"
                    onClick={() => (isNew ? onRemoveNew(ref) : photos.removeSaved(ref))}
                    disabled={disabled}
                    className="absolute top-2 right-2 bg-white/95 text-red-600 p-2 rounded-full hover:bg-red-50 disabled:opacity-50"
                    aria-label={`Remove ${name}`}
                    title={isNew ? 'Remove from selection' : 'Remove when you save'}
                  >
                    <FiTrash2 size={14} aria-hidden="true" />
                  </button>
                </div>

                <div className="p-2 space-y-2">
                  <button
                    type="button"
                    onClick={() => photos.setCover(ref)}
                    disabled={disabled || isCover}
                    aria-pressed={isCover}
                    className={`w-full min-h-9 text-xs rounded-lg px-2 py-2 font-medium ${isCover ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-700 hover:bg-primary-50 hover:text-primary-700'} disabled:opacity-70`}
                  >
                    {isCover ? 'Cover photo' : 'Set as cover'}
                  </button>
                  <div className="flex items-center justify-between gap-2">
                    <button
                      type="button"
                      onClick={() => photos.move(ref, -1)}
                      disabled={disabled || index === 0}
                      aria-label={`Move ${name} earlier`}
                      className="flex items-center justify-center gap-1 flex-1 min-h-9 border border-gray-200 rounded-lg text-xs text-gray-600 hover:bg-gray-50 disabled:opacity-30"
                    >
                      <FiArrowLeft size={13} aria-hidden="true" /> Earlier
                    </button>
                    <button
                      type="button"
                      onClick={() => photos.move(ref, 1)}
                      disabled={disabled || index === total - 1}
                      aria-label={`Move ${name} later`}
                      className="flex items-center justify-center gap-1 flex-1 min-h-9 border border-gray-200 rounded-lg text-xs text-gray-600 hover:bg-gray-50 disabled:opacity-30"
                    >
                      Later <FiArrowRight size={13} aria-hidden="true" />
                    </button>
                  </div>
                </div>
              </li>
            )
          })}
        </ol>
      )}
    </div>
  )
}
