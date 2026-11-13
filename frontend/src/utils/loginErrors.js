import { FiLock, FiUserX } from 'react-icons/fi'
import { validateEmail } from './authValidation'

/**
 * The sign-in form's checks, as rules for useLiveValidation: a well-formed email and a
 * password. The password's strength isn't checked here (older accounts may predate the rules).
 */
export const LOGIN_RULES = {
  email: (v) => validateEmail(v.email),
  password: (v) => (v.password ? undefined : 'Enter your password.'),
}

/** An email that can't become valid by typing more (a space, a second @) is flagged at once. */
export const LOGIN_SHOW_NOW = {
  email: (v) => /\s/.test(v.email.trim()) || (v.email.match(/@/g) || []).length > 1 || v.email.trim().startsWith('@'),
}

/**
 * Turns a failed sign-in into what the form shows:
 *  - `fields`: messages under the inputs (`true` marks a field red without a message of its own)
 *  - `alert`: the dialog to open, or null
 * Connection problems and too many attempts get the app's error dialog instead (api/axios.js).
 */
export function loginErrors(error) {
  if (error?.errorDialogShown) return { fields: {}, alert: null }

  const status = error?.response?.status
  const data = error?.response?.data

  // Wrong email or password: both fields, since the server doesn't say which one was wrong.
  if (status === 401) {
    return {
      fields: { credentials: true, email: true, password: 'Incorrect email or password.' },
      alert: {
        icon: FiLock,
        title: "We couldn't log you in",
        message: "The email or password you entered doesn't match our records. Please check your details and try again.",
        button: 'Try again',
      },
    }
  }

  // The details were right, but the account can't sign in (closed, or registration under review).
  if (status === 403) {
    return {
      fields: {},
      alert: {
        icon: FiUserX,
        title: data?.code === 'account_closed' ? 'This account is closed' : "You can't log in yet",
        message: data?.message || 'This account cannot sign in right now.',
        button: 'OK',
      },
    }
  }

  // Rejected by the server's own checks (e.g. a malformed email).
  if (status === 422 && data?.errors) {
    const first = (name) => data.errors[name]?.[0]
    return { fields: { email: first('email'), password: first('password') }, alert: null }
  }

  return {
    fields: {},
    alert: {
      icon: FiLock,
      title: "We couldn't log you in",
      message: data?.message || 'Something went wrong. Please try again.',
      button: 'OK',
    },
  }
}
