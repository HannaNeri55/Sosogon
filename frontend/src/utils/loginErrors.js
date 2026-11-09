import { FiLock, FiUserX } from 'react-icons/fi'

const EMAIL_PATTERN = /^\S+@\S+\.\S+$/

/** Checks the sign-in form before sending it. Returns { email?, password? } messages. */
export function validateLogin({ email, password }) {
  const errors = {}
  if (!email.trim()) errors.email = 'Enter your email address.'
  else if (!EMAIL_PATTERN.test(email.trim())) errors.email = 'Enter a valid email address, like name@example.com.'
  if (!password) errors.password = 'Enter your password.'
  return errors
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
