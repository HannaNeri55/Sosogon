import axios from 'axios'
import { loginPathFor, expiredLoginPathFor } from '../utils/session'
import { CONNECTION_MESSAGE, isConnectionError } from '../utils/apiErrors'
import { showErrorDialog } from '../utils/dialogs'
import { clearSession, getStoredUser, getToken, saveStoredUser } from '../utils/authStorage'

// VITE_API_URL is the backend's address in production (e.g. https://stim-api.onrender.com).
// Left empty locally, so requests stay relative and go through the Vite dev proxy.
const api = axios.create({
  baseURL: `${(import.meta.env.VITE_API_URL ?? '').replace(/\/+$/, '')}/api/v1`,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
})

// Add token to requests
api.interceptors.request.use((config) => {
  const token = getToken()
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

// Sign-in pages show their own errors (wrong password, pending registration, closed account).
const AUTH_PATHS = ['/login', '/register', '/forgot-password', '/reset-password', '/logout']

function storedRole() {
  return getStoredUser()?.role // No readable user: undefined, the main login page.
}

// Laravel's stock wording for these is too terse to show as is.
const STOCK_MESSAGES = ['Too Many Attempts.', 'Service Unavailable', 'Server Error']
const serverMessage = (error) => {
  const message = error.response?.data?.message
  return typeof message === 'string' && !STOCK_MESSAGES.includes(message) ? message : null
}

/**
 * The dialog for a serious failure, or null when the page should handle it (validation errors,
 * not found, conflicts...). Loading something again is safe, so failed GETs offer "Try again".
 */
function dialogFor(error, isAuthAttempt) {
  const status = error.response?.status
  const retryable = (error.config?.method ?? 'get').toLowerCase() === 'get'

  if (isConnectionError(error)) {
    return { kind: 'connection', title: 'No connection', message: CONNECTION_MESSAGE, retryable }
  }
  if (status === 429) {
    return {
      kind: 'rate-limit',
      title: 'Please wait a moment',
      message: serverMessage(error) || "You've made too many requests in a short time. Please wait a minute, then try again.",
    }
  }
  if (status === 503) {
    return {
      kind: 'unavailable',
      title: 'Service busy',
      message: serverMessage(error) || 'This service is busy or under maintenance. Please try again in a few minutes.',
      retryable,
    }
  }
  if (status >= 500) {
    return {
      kind: 'server',
      title: 'Something went wrong on our side',
      message: 'The server ran into a problem. Please try again in a moment. If it keeps happening, contact the Tourism Office.',
      retryable,
    }
  }
  if (status === 403 && !isAuthAttempt) {
    const message = serverMessage(error)
    return {
      kind: 'forbidden',
      title: "You don't have access",
      // The role check's own message ("Unauthorized. Required role: admin") is for developers.
      message: message && !message.startsWith('Unauthorized.') && message !== 'This action is unauthorized.'
        ? message
        : "Your account doesn't have access to this. If you think this is a mistake, contact the Tourism Office.",
    }
  }
  return null
}

api.interceptors.response.use(
  (response) => response,
  async (error) => {
    if (axios.isCancel(error)) return Promise.reject(error)

    const url = error.config?.url || ''
    const isAuthAttempt = AUTH_PATHS.some((path) => url.includes(path))

    if (error.response?.status === 401 && !isAuthAttempt) {
      // A stored token being rejected means the session expired (24 h limit, or idle
      // logout for admins and business owners).
      const hadSession = !!getToken()
      const role = storedRole()
      clearSession()

      if (!hadSession) {
        window.location.href = loginPathFor(role)
        return Promise.reject(error)
      }

      error.errorDialogShown = true
      showErrorDialog({
        kind: 'session',
        title: 'Your session has ended',
        message: 'For your security, you were signed out. Please log in again to continue.',
        confirmLabel: 'Log in again',
      }).then(() => { window.location.href = expiredLoginPathFor(role) })
      return Promise.reject(error)
    }

    // The account was suspended or deactivated: only the Account Health page is available.
    if (error.response?.status === 403 && error.response.data?.code === 'account_restricted') {
      const stored = getStoredUser() // Unreadable: the page reloads it from the API anyway.
      if (stored) saveStoredUser({ ...stored, account_state: error.response.data.account_state })
      if (!window.location.pathname.startsWith('/account/status')) {
        window.location.href = '/account/status'
      }
      return Promise.reject(error)
    }

    const dialog = dialogFor(error, isAuthAttempt)
    if (dialog) {
      // Marked first, so the page's own error handling (showApiError) doesn't repeat it as a toast.
      error.errorDialogShown = true
      const choice = await showErrorDialog(dialog)
      // The request waits while the dialog is open; "Try again" sends it once more and the page
      // carries on as if the first attempt had worked (or sees the new failure).
      if (choice === 'retry' && dialog.retryable) return api.request(error.config)
    }

    return Promise.reject(error)
  }
)

export default api
