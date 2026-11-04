// Form drafts for destination activities (admin destination form).

const DURATION_UNITS = { minutes: 1, hours: 60, days: 1440 };

/** Form draft for a saved activity (or a blank one when `activity` is omitted). */
export function activityDraft(activity = null) {
  const minutes = activity?.duration_minutes ?? null;
  const unit = !minutes ? "hours" : minutes % 1440 === 0 ? "days" : minutes % 60 === 0 ? "hours" : "minutes";

  return {
    key: activity ? `a${activity.id}` : `new-${Date.now()}-${Math.random().toString(36).slice(2)}`,
    id: activity?.id ?? null,
    name: activity?.name ?? "",
    description: activity?.description ?? "",
    type: activity?.type ?? "outdoor",
    price: activity?.price ?? "",
    durationValue: minutes ? minutes / DURATION_UNITS[unit] : "",
    durationUnit: unit,
    capacity: activity?.capacity ?? "",
    savedImage: activity?.image_path ? activity : null,
    newImage: null,
    previewUrl: null,
    removeImage: false,
  };
}

/** Duration in whole minutes for the API, or "" when not set. */
export function draftDurationMinutes(draft) {
  const value = Number(draft.durationValue);
  return draft.durationValue === "" || !Number.isFinite(value) || value <= 0
    ? ""
    : Math.round(value * DURATION_UNITS[draft.durationUnit]);
}

/** Free preview URLs held by drafts (call when drafts are discarded). */
export function releaseActivityPreviews(drafts) {
  drafts.forEach((draft) => draft.previewUrl && URL.revokeObjectURL(draft.previewUrl));
}
