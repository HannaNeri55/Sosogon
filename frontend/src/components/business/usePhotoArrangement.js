import { useEffect, useMemo, useState } from 'react'

/**
 * Local (unsaved) arrangement of a listing gallery: saved photos plus newly
 * selected files, in one order, with a chosen cover and saved photos marked for
 * removal. Nothing is sent until the page saves; appendTo() adds the fields the
 * owner update endpoint understands (photo_order[], cover, remove_image_ids[]).
 *
 * Items are refs: a saved photo's id (number) or a selected File object.
 */
export default function usePhotoArrangement(savedImages, newFiles) {
  const [order, setOrder] = useState(null)
  const [cover, setCover] = useState(null)
  const [removed, setRemoved] = useState([])

  const savedIds = useMemo(() => savedImages.map((image) => image.id), [savedImages])
  const savedCover = useMemo(() => savedImages.find((image) => image.is_primary)?.id ?? null, [savedImages])

  // Reconcile the requested order with what exists now: drop refs that are gone,
  // append anything new at the end.
  const items = useMemo(() => {
    const present = (ref) => (typeof ref === 'number' ? savedIds.includes(ref) && !removed.includes(ref) : newFiles.includes(ref))
    const base = (order ?? [...savedIds, ...newFiles]).filter(present)
    const missing = [...savedIds.filter((id) => !removed.includes(id)), ...newFiles].filter((ref) => !base.includes(ref))
    return [...base, ...missing]
  }, [order, savedIds, newFiles, removed])

  const effectiveCover = items.includes(cover) ? cover : items.includes(savedCover) ? savedCover : (items[0] ?? null)

  const previews = useMemo(() => new Map(newFiles.map((file) => [file, URL.createObjectURL(file)])), [newFiles])
  useEffect(() => () => previews.forEach((url) => URL.revokeObjectURL(url)), [previews])

  const defaultOrder = [...savedIds, ...newFiles]
  const hasChanges =
    removed.length > 0 ||
    newFiles.length > 0 ||
    items.some((ref, index) => ref !== defaultOrder[index]) ||
    effectiveCover !== (savedCover ?? defaultOrder[0] ?? null)

  const moveTo = (from, to) => {
    if (from === to || to < 0 || to >= items.length) return
    const next = [...items]
    const [moved] = next.splice(from, 1)
    next.splice(to, 0, moved)
    setOrder(next)
  }

  const token = (ref) => (typeof ref === 'number' ? `e:${ref}` : `n:${newFiles.indexOf(ref)}`)

  return {
    items,
    cover: effectiveCover,
    removedCount: removed.length,
    hasChanges,
    previewUrl: (file) => previews.get(file),
    setCover,
    move: (ref, direction) => moveTo(items.indexOf(ref), items.indexOf(ref) + direction),
    moveTo,
    removeSaved: (id) => setRemoved((current) => (current.includes(id) ? current : [...current, id])),
    undoRemovals: () => setRemoved([]),
    reset: () => {
      setOrder(null)
      setCover(null)
      setRemoved([])
    },
    /** Adds the arrangement to a save request; new files must already be appended as images[] in newFiles order. */
    appendTo: (payload) => {
      if (!hasChanges) return
      items.forEach((ref) => payload.append('photo_order[]', token(ref)))
      if (effectiveCover !== null) payload.append('cover', token(effectiveCover))
      removed.forEach((id) => payload.append('remove_image_ids[]', id))
    },
  }
}
