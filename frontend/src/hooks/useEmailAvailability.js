import { useEffect } from 'react'
import { checkEmailAvailable } from '../api/auth'
import { EMAIL_TAKEN, validateEmail } from '../utils/authValidation'

/**
 * Once the email looks valid and the user pauses, asks the server whether it's already
 * registered, and shows "already registered" on the field (through useLiveValidation's `live`).
 * An answer for an email the user has since changed is dropped. Failures are ignored:
 * registration checks again on submit.
 */
export default function useEmailAvailability(email, live) {
  const { setServerErrors } = live

  useEffect(() => {
    const value = email.trim()
    if (validateEmail(value)) return undefined
    let current = true
    const timer = setTimeout(() => {
      checkEmailAvailable(value)
        .then(({ available }) => {
          if (!available && current) setServerErrors({ email: EMAIL_TAKEN })
        })
        .catch(() => {})
    }, 500)
    return () => {
      current = false
      clearTimeout(timer)
    }
  }, [email, setServerErrors])
}
