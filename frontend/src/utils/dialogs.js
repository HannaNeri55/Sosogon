// The app's two global dialogs, shown by <DialogHost /> (mounted once in main.jsx):
//  - error dialogs for serious request failures, opened by the API client (api/axios.js)
//  - confirm dialogs, the styled replacement for window.confirm()
// Plain functions rather than React context, so the API client can open them too.

let state = { errors: [], confirm: null }
const listeners = new Set()

function update(patch) {
  state = { ...state, ...patch }
  listeners.forEach((listener) => listener())
}

export function subscribeDialogs(listener) {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

export function getDialogs() {
  return state
}

/**
 * Shows an error dialog and resolves with 'retry' or 'close' once the user answers.
 * Another failure of the same kind while one is waiting joins it (ten failed requests during an
 * outage are one dialog), and dialogs of other kinds wait their turn. `retryable` offers
 * "Try again"; `confirmLabel` renames the single button when there's nothing to retry.
 */
export function showErrorDialog({ kind, title, message, retryable = false, confirmLabel }) {
  return new Promise((resolve) => {
    const waiter = { resolve, retryable }
    const same = state.errors.find((entry) => entry.kind === kind)

    if (same) {
      update({ errors: state.errors.map((entry) => (entry === same ? { ...entry, waiters: [...entry.waiters, waiter] } : entry)) })
    } else {
      update({ errors: [...state.errors, { id: `${kind}-${Date.now()}`, kind, title, message, confirmLabel, waiters: [waiter] }] })
    }
  })
}

/** Answers the error dialog on screen: every request waiting on it gets the choice. */
export function closeErrorDialog(choice) {
  const [current, ...rest] = state.errors
  if (!current) return
  update({ errors: rest })
  current.waiters.forEach((waiter) => waiter.resolve(choice))
}

/**
 * Styled replacement for window.confirm(): resolves true for the confirm button, false otherwise.
 *   if (!(await confirmDialog({ title: 'Delete this event?', danger: true }))) return
 */
export function confirmDialog({ title, message, confirmLabel = 'Confirm', cancelLabel = 'Cancel', danger = false }) {
  // Only one at a time: a new question cancels one left open.
  state.confirm?.resolve(false)

  return new Promise((resolve) => {
    update({ confirm: { title, message, confirmLabel, cancelLabel, danger, resolve } })
  })
}

export function answerConfirm(answer) {
  const current = state.confirm
  if (!current) return
  update({ confirm: null })
  current.resolve(answer)
}
