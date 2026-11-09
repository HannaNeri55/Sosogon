import toast from 'react-hot-toast'

export const CONNECTION_MESSAGE = "Can't reach the server. Check your internet connection and try again."

/** The request got no answer at all: no internet, server down, or blocked. Cancelled requests don't count. */
export function isConnectionError(error) {
  return Boolean(error?.isAxiosError) && !error.response && error.code !== 'ERR_CANCELED'
}

/**
 * The message to show for a failed API request: the server's validation errors (the first one,
 * or all of them on separate lines with `allErrors`), else its message, else `fallback`.
 * Axios's own wording ("Request failed with status code 500") is never shown.
 */
export function getErrorMessage(error, fallback = 'Something went wrong. Please try again.', { allErrors = false } = {}) {
  if (isConnectionError(error)) return CONNECTION_MESSAGE

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
