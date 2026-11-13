import { useCallback, useEffect, useRef, useState } from 'react'

/**
 * Errors that follow the user while they type (login, registration, password reset).
 *
 * `rules` maps a field name to `(values) => message | undefined`. A field's problem is shown:
 *  - once the user pauses typing in it (`delay` ms), or leaves it, or submits the form;
 *  - right away when `now[name](values)` says the value is already known to be wrong.
 * After that the field is checked on every keystroke, and its message disappears the moment the
 * value is fine. An untouched empty field shows nothing until the form is submitted.
 *
 * Server messages (422 responses, "email already registered") are shown on their field until
 * that field is edited.
 */
export default function useLiveValidation(values, rules, { delay = 700, now = {} } = {}) {
  const [revealed, setRevealed] = useState({})
  const [server, setServer] = useState({})
  const timers = useRef({})

  useEffect(() => () => Object.values(timers.current).forEach(clearTimeout), [])

  const reveal = useCallback((name) => setRevealed((r) => (r[name] ? r : { ...r, [name]: true })), [])

  /** Call after updating a field's value. */
  const changed = useCallback((name) => {
    setServer((s) => {
      if (!(name in s)) return s
      const rest = { ...s }
      delete rest[name]
      return rest
    })
    clearTimeout(timers.current[name])
    timers.current[name] = setTimeout(() => reveal(name), delay)
  }, [delay, reveal])

  const blurred = useCallback((name) => {
    clearTimeout(timers.current[name])
    reveal(name)
  }, [reveal])

  const problems = Object.fromEntries(Object.entries(rules).map(([name, rule]) => [name, rule(values)]))

  const errors = {}
  for (const [name, message] of Object.entries(server)) errors[name] = message
  for (const [name, problem] of Object.entries(problems)) {
    if (!errors[name] && problem && (revealed[name] || now[name]?.(values))) errors[name] = problem
  }

  /** On submit: shows every problem. Returns true when there are none (server messages included). */
  const submit = () => {
    setRevealed(Object.fromEntries(Object.keys(rules).map((name) => [name, true])))
    return Object.values(problems).every((p) => !p) && Object.keys(server).length === 0
  }

  /** Server messages by field (arrays are reduced to their first message). */
  const setServerErrors = useCallback((found) => {
    setServer((s) => ({
      ...s,
      ...Object.fromEntries(Object.entries(found).filter(([, m]) => m).map(([k, m]) => [k, Array.isArray(m) ? m[0] : m])),
    }))
  }, [])

  const clearServerErrors = useCallback(() => setServer({}), [])

  return { errors, problems, revealed, changed, blurred, submit, setServerErrors, clearServerErrors }
}
