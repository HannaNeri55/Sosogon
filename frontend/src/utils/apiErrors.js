import toast from 'react-hot-toast'

export const CONNECTION_MESSAGE = "You're offline. Check your internet connection and try again."

// The device is online but the server didn't answer: it may be waking up after a quiet spell
// (the hosting puts it to sleep), busy, or the answer took too long. Not a connection problem.
export const SERVER_UNREACHABLE_MESSAGE = "SOSOGON's server didn't respond. It may be starting up after a quiet period. Please wait a minute and try again."

/** The request got no answer at all: no internet, server down, or blocked. Cancelled requests don't count. */
export function isConnectionError(error) {
  return Boolean(error?.isAxiosError) && !error.response && error.code !== 'ERR_CANCELED'
}

/**
 * Whether the device itself has no network. Browsers report navigator.onLine === false only when
 * there is no network at all, so an unanswered request while it is true is the server's problem.
 */
export const isDeviceOffline = () => typeof navigator !== 'undefined' && navigator.onLine === false

/**
 * The message to show for a failed API request: the server's validation errors (the first one,
 * or all of them on separate lines with `allErrors`), else its message, else `fallback`.
 * Axios's own wording ("Request failed with status code 500") is never shown.
 */
export function getErrorMessage(error, fallback = 'Something went wrong. Please try again.', { allErrors = false } = {}) {
  if (isConnectionError(error)) return isDeviceOffline() ? CONNECTION_MESSAGE : SERVER_UNREACHABLE_MESSAGE

  const data = error?.response?.data
  const messages = Object.values(data?.errors ?? {}).flat().filter(Boolean)
  if (messages.length) return allErrors ? messages.join('\n') : messages[0]

  return (typeof data?.message === 'string' && data.message) || fallback
}

/**
 * Toast for a failed request, unless the error dialog already told the user about it
 * (no connection, server errors, too many requests, no access: see api/axios.js).
 */
export function showApiError(error, fallback, options) {
  if (error?.errorDialogShown) return
  toast.error(getErrorMessage(error, fallback, options))
}
