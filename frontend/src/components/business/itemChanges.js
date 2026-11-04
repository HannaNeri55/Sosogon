/**
 * Helpers for rooms, menu items and products that have proposed changes
 * (Option 5b). `pending_changes` is only included in the owner's own API
 * responses; public pages never receive it.
 */

/** The values the owner is currently proposing, falling back to the approved ones. */
export function withProposedValues(item) {
  const proposal = item?.pending_changes
  if (!proposal || !['pending', 'rejected'].includes(proposal.status)) return item
  return { ...item, ...(proposal.values || {}) }
}
