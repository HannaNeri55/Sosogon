// frontend/src/components/admin/DestinationPhotoManager.jsx
import { FiRotateCcw, FiStar, FiX } from "react-icons/fi";
import { getImageUrl } from "../../utils/helpers";

/**
 * Photo grid for the admin destination form. Every change here is staged:
 * removals, new uploads and the cover choice are only sent when the form is
 * saved, and discarded if it is closed.
 *
 * coverKey is "e:<image id>" for a saved photo or "n:<key>" for a new one.
 */
export default function DestinationPhotoManager({
  existingImages,
  removedImageIds,
  newPhotos,
  coverKey,
  isEditing,
  onAddFiles,
  onRemoveExisting,
  onUndoRemovals,
  onRemoveNew,
  onSetCover,
}) {
  const visibleExisting = existingImages.filter((img) => !removedImageIds.includes(img.id));
  const photos = [
    ...visibleExisting.map((img) => ({
      key: `e:${img.id}`,
      src: getImageUrl(img, { thumbnail: true }),
      label: null,
      isNew: false,
      onRemove: () => onRemoveExisting(img.id),
    })),
    ...newPhotos.map((photo) => ({
      key: `n:${photo.key}`,
      src: photo.url,
      label: photo.file.name,
      isNew: true,
      onRemove: () => onRemoveNew(photo.key),
    })),
  ];

  return (
    <div>
      <label className="block text-sm font-medium text-gray-700 mb-2">
        Destination Images
        {photos.length > 0 && <span className="ml-1 font-normal text-gray-500">({photos.length})</span>}
      </label>

      {photos.length > 0 && (
        <ul className="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-3 mb-3">
          {photos.map((photo) => {
            const isCover = photo.key === coverKey;
            return (
              <li
                key={photo.key}
                className={`overflow-hidden rounded-lg border-2 bg-white ${
                  isCover ? "border-green-600" : "border-gray-200"
                }`}
              >
                <div className="relative">
                  <img src={photo.src} alt={photo.label || "Destination photo"} className="w-full aspect-square object-cover" />
                  <div className="absolute top-1.5 left-1.5 flex flex-col gap-1">
                    {isCover && (
                      <span className="rounded bg-green-600 px-2 py-0.5 text-xs font-semibold text-white">Cover</span>
                    )}
                    {photo.isNew && (
                      <span className="rounded bg-primary-600 px-2 py-0.5 text-xs font-semibold text-white">New</span>
                    )}
                  </div>
                  <button
                    type="button"
                    onClick={photo.onRemove}
                    aria-label={photo.isNew ? `Remove ${photo.label}` : "Remove this photo"}
                    title={photo.isNew ? "Remove from selection" : "Remove photo (applied when you save)"}
                    className="absolute top-1 right-1 flex h-9 w-9 items-center justify-center rounded-full bg-red-600 text-white shadow hover:bg-red-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-300"
                  >
                    <FiX size={16} />
                  </button>
                </div>
                <div className="p-2 space-y-1">
                  {photo.label && (
                    <p className="truncate text-xs text-gray-600" title={photo.label}>{photo.label}</p>
                  )}
                  {isCover ? (
                    <p className="flex min-h-8 items-center justify-center gap-1 text-xs font-medium text-green-700">
                      <FiStar size={12} aria-hidden="true" /> Cover photo
                    </p>
                  ) : (
                    <button
                      type="button"
                      onClick={() => onSetCover(photo.key)}
                      className="w-full min-h-8 rounded border border-gray-300 px-2 py-1.5 text-xs hover:bg-gray-100"
                    >
                      Set as Cover
                    </button>
                  )}
                </div>
              </li>
            );
          })}
        </ul>
      )}

      {removedImageIds.length > 0 && (
        <p className="mb-3 flex flex-wrap items-center gap-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
          {removedImageIds.length} saved photo{removedImageIds.length === 1 ? "" : "s"} will be deleted when you save.
          <button type="button" onClick={onUndoRemovals} className="inline-flex items-center gap-1 font-semibold underline">
            <FiRotateCcw size={12} aria-hidden="true" /> Undo
          </button>
        </p>
      )}

      <input
        type="file"
        multiple
        accept="image/jpeg,image/png,image/webp"
        onChange={(e) => {
          onAddFiles(Array.from(e.target.files));
          // Reset so the same file can be picked again after removing it.
          e.target.value = "";
        }}
        className="input-field"
      />
      <p className="text-xs text-gray-500 mt-1">
        {isEditing
          ? "Add more images (JPEG, PNG, WebP, max 5MB each, up to 10 per save). Changes to photos and the cover are applied when you save."
          : "Upload up to 10 images (JPEG, PNG, WebP, max 5MB each). The first photo is the cover unless you choose another."}
      </p>
    </div>
  );
}
