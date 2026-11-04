// frontend/src/components/admin/DestinationActivitiesEditor.jsx
import { FiImage, FiTrash2, FiX } from "react-icons/fi";
import { getImageUrl } from "../../utils/helpers";
import { activityDraft } from "../../utils/activityDrafts";

/**
 * Optional "Activities" section of the destination form. Changes are kept in
 * the form and only saved together with the destination.
 */
export default function DestinationActivitiesEditor({ activities, onChange }) {
  const update = (key, changes) =>
    onChange(activities.map((draft) => (draft.key === key ? { ...draft, ...changes } : draft)));

  const remove = (draft) => {
    if (draft.previewUrl) URL.revokeObjectURL(draft.previewUrl);
    onChange(activities.filter((a) => a.key !== draft.key));
  };

  const pickImage = (draft, file) => {
    if (!file) return;
    if (draft.previewUrl) URL.revokeObjectURL(draft.previewUrl);
    update(draft.key, { newImage: file, previewUrl: URL.createObjectURL(file), removeImage: false });
  };

  const clearImage = (draft) => {
    if (draft.newImage) {
      // Drop the newly picked file; a saved image (if any) shows again.
      URL.revokeObjectURL(draft.previewUrl);
      update(draft.key, { newImage: null, previewUrl: null });
    } else {
      update(draft.key, { removeImage: true });
    }
  };

  return (
    <div className="border border-gray-200 rounded-xl p-5 space-y-4">
      <div className="flex items-center justify-between gap-3">
        <div>
          <h3 className="font-semibold text-gray-900">Activities</h3>
          <p className="text-xs text-gray-500">Optional. Things visitors can do at this destination.</p>
        </div>
        <button type="button" onClick={() => onChange([...activities, activityDraft()])} className="btn-primary shrink-0">
          + Add Activity
        </button>
      </div>

      {activities.map((draft, index) => {
        const imageSrc = draft.previewUrl || (!draft.removeImage && draft.savedImage ? getImageUrl(draft.savedImage, { thumbnail: true }) : null);
        const fieldId = (name) => `activity-${draft.key}-${name}`;

        return (
          <div key={draft.key} className="rounded-xl border border-gray-200 bg-gray-50/60 p-4 space-y-3">
            <div className="flex items-center justify-between gap-2">
              <p className="text-sm font-semibold text-gray-800">
                Activity {index + 1}
                {!draft.id && <span className="ml-2 rounded bg-primary-50 px-1.5 py-0.5 text-xs font-medium text-primary-700">New</span>}
              </p>
              <button
                type="button"
                onClick={() => remove(draft)}
                className="inline-flex items-center gap-1 rounded-lg border border-red-200 px-3 py-1.5 text-sm text-red-600 hover:bg-red-50"
              >
                <FiTrash2 size={14} aria-hidden="true" /> Remove
              </button>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-[1fr_auto] gap-3">
              <div className="space-y-3 min-w-0">
                <div className="grid grid-cols-1 sm:grid-cols-[1fr_10rem] gap-3">
                  <div>
                    <label htmlFor={fieldId("name")} className="block text-xs font-medium text-gray-600 mb-1">
                      Activity name <span className="text-red-600">*</span>
                    </label>
                    <input
                      id={fieldId("name")}
                      className="input-field"
                      placeholder="e.g. Kayaking"
                      value={draft.name}
                      maxLength={255}
                      onChange={(e) => update(draft.key, { name: e.target.value })}
                      required
                    />
                  </div>
                  <div>
                    <label htmlFor={fieldId("type")} className="block text-xs font-medium text-gray-600 mb-1">Type</label>
                    <select
                      id={fieldId("type")}
                      className="input-field"
                      value={draft.type}
                      onChange={(e) => update(draft.key, { type: e.target.value })}
                    >
                      <option value="outdoor">Outdoor</option>
                      <option value="indoor">Indoor</option>
                    </select>
                  </div>
                </div>

                <div>
                  <label htmlFor={fieldId("description")} className="block text-xs font-medium text-gray-600 mb-1">Description</label>
                  <textarea
                    id={fieldId("description")}
                    className="input-field"
                    rows={2}
                    placeholder="What visitors can do"
                    value={draft.description}
                    onChange={(e) => update(draft.key, { description: e.target.value })}
                  />
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                  <div>
                    <label htmlFor={fieldId("price")} className="block text-xs font-medium text-gray-600 mb-1">Price (₱)</label>
                    <input
                      id={fieldId("price")}
                      type="number"
                      min="0"
                      step="0.01"
                      className="input-field"
                      placeholder="Leave blank if unknown"
                      value={draft.price}
                      onChange={(e) => update(draft.key, { price: e.target.value })}
                    />
                  </div>
                  <div>
                    <label htmlFor={fieldId("duration")} className="block text-xs font-medium text-gray-600 mb-1">Duration</label>
                    <div className="flex gap-2">
                      <input
                        id={fieldId("duration")}
                        type="number"
                        min="0"
                        step="any"
                        className="input-field min-w-0"
                        placeholder="e.g. 2"
                        value={draft.durationValue}
                        onChange={(e) => update(draft.key, { durationValue: e.target.value })}
                      />
                      <select
                        aria-label="Duration unit"
                        className="input-field w-28 shrink-0"
                        value={draft.durationUnit}
                        onChange={(e) => update(draft.key, { durationUnit: e.target.value })}
                      >
                        <option value="minutes">minutes</option>
                        <option value="hours">hours</option>
                        <option value="days">days</option>
                      </select>
                    </div>
                  </div>
                  <div>
                    <label htmlFor={fieldId("capacity")} className="block text-xs font-medium text-gray-600 mb-1">Capacity (persons)</label>
                    <input
                      id={fieldId("capacity")}
                      type="number"
                      min="1"
                      step="1"
                      className="input-field"
                      placeholder="Max persons"
                      value={draft.capacity}
                      onChange={(e) => update(draft.key, { capacity: e.target.value })}
                    />
                  </div>
                </div>
              </div>

              <div className="md:w-44">
                <p className="block text-xs font-medium text-gray-600 mb-1">Image (optional)</p>
                <div className="relative aspect-4/3 overflow-hidden rounded-lg border border-dashed border-gray-300 bg-white">
                  {imageSrc ? (
                    <>
                      <img src={imageSrc} alt={draft.name || "Activity"} className="h-full w-full object-cover" />
                      <button
                        type="button"
                        onClick={() => clearImage(draft)}
                        aria-label={draft.newImage ? "Remove selected image" : "Remove saved image"}
                        title={draft.newImage ? "Remove selected image" : "Remove image (applied when you save)"}
                        className="absolute top-1 right-1 flex h-8 w-8 items-center justify-center rounded-full bg-red-600 text-white shadow hover:bg-red-700"
                      >
                        <FiX size={14} />
                      </button>
                    </>
                  ) : (
                    <div className="flex h-full flex-col items-center justify-center text-gray-400">
                      <FiImage size={22} aria-hidden="true" />
                      <span className="mt-1 text-xs">No image</span>
                    </div>
                  )}
                </div>
                <label className="mt-2 block cursor-pointer rounded-lg border border-gray-300 px-2 py-1.5 text-center text-xs font-medium text-gray-700 hover:bg-gray-100">
                  {imageSrc ? "Replace image" : "Choose image"}
                  <input
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    className="sr-only"
                    onChange={(e) => {
                      pickImage(draft, e.target.files?.[0]);
                      e.target.value = "";
                    }}
                  />
                </label>
                {draft.removeImage && !draft.newImage && (
                  <button
                    type="button"
                    onClick={() => update(draft.key, { removeImage: false })}
                    className="mt-1 w-full text-xs font-medium text-amber-700 underline"
                  >
                    Undo image removal
                  </button>
                )}
              </div>
            </div>
          </div>
        );
      })}

      {activities.length === 0 && <p className="text-sm text-gray-500">No activities added yet.</p>}
    </div>
  );
}
