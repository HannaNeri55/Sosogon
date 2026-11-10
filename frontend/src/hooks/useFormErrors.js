import { useCallback, useEffect, useState } from 'react'
import { focusFirstInvalid } from '../utils/formErrors'

// Plain-language versions of the browser's own checks (required, type="email", min, ...).
function nativeMessage(el) {
  const v = el.validity
  if (v.valueMissing) {
    if (el.type === 'file') return 'Choose a file.'
    if (el.type === 'checkbox') return 'Check this box to continue.'
    if (el.tagName === 'SELECT' || el.type === 'radio') return 'Choose an option.'
    return 'This field is required.'
  }
  if (v.typeMismatch) {
    if (el.type === 'email') return 'Enter a valid email address, like name@example.com.'
    if (el.type === 'url') return 'Enter a full web address, like https://example.com.'
  }
  if (v.badInput || v.stepMismatch) return el.type === 'number' ? 'Enter a valid number.' : 'Enter a valid value.'
  if (v.rangeUnderflow) return `Must be ${el.min} or more.`
  if (v.rangeOverflow) return `Must be ${el.max} or less.`
  if (v.tooShort) return `Use at least ${el.minLength} characters.`
  if (v.tooLong) return `Use ${el.maxLength} characters or fewer.`
  if (v.patternMismatch) return el.title || 'Use the format shown.'
  return el.validationMessage
}

// The error for a field named `name`: an exact match, or the first one for its items
// (the server reports "images.2" for a field named "images" or "images[]").
export function errorFor(errors, name) {
  if (!name) return undefined
  const base = name.replace(/\[\]$/, '')
  const key = name in errors ? name : base in errors ? base : Object.keys(errors).find((k) => k.startsWith(`${base}.`))
  const message = key && errors[key]
  return Array.isArray(message) ? message[0] : message || undefined
}

const fieldsOf = (form) => [...(form?.querySelectorAll('input[name], select[name], textarea[name]') ?? [])]

/**
 * Field-level errors for a form: each problem turns its field red (aria-invalid, styled in
 * index.css) with a message under it (<FieldError>). Errors are found three ways:
 *  - the browser's checks declared on the fields (required, type="email", min, maxLength...)
 *  - an optional `validate()` for rules the markup can't express, returning { name: message }
 *  - the server's 422 response, via fromResponse(error) in the submit handler's catch
 * Server errors that match no field show in <FormErrorSummary> above the form.
 *
 * `formRef` is the <form>'s ref (useRef, or e.g. useScrollToForm's), so fields can be found.
 *
 *   const formRef = useRef(null)
 *   const form = useFormErrors(formRef)
 *   const handleSubmit = (e) => { e.preventDefault(); if (!form.check()) return; ... }
 *   <form ref={formRef} noValidate onChange={form.onChange} onSubmit={handleSubmit}>
 *     ... <FieldError form={form} name="title" />
 */
export default function useFormErrors(formRef, { validate } = {}) {
  const [errors, setErrors] = useState({})
  const [unmatched, setUnmatched] = useState([])

  // Fields are marked red through aria-invalid, matched by their name attribute. Only marks
  // set here are removed (data-form-invalid), so a field's own aria-invalid is left alone.
  useEffect(() => {
    for (const el of fieldsOf(formRef.current)) {
      if (errorFor(errors, el.name)) {
        el.setAttribute('aria-invalid', 'true')
        el.dataset.formInvalid = ''
      } else if ('formInvalid' in el.dataset) {
        el.removeAttribute('aria-invalid')
        delete el.dataset.formInvalid
      }
    }
  }, [errors, formRef])

  const show = useCallback((found, extra = []) => {
    setErrors(found)
    setUnmatched(extra)
    if (Object.keys(found).length || extra.length) focusFirstInvalid()
  }, [])

  /**
   * Runs the checks; returns true when the form may be sent. `extra` adds errors that depend on
   * how the form is being sent (e.g. { images: 'Add 5 photos' } only when submitting for review).
   */
  const check = (extra = {}) => {
    const found = {}
    for (const el of fieldsOf(formRef.current)) {
      if (!el.willValidate || el.disabled || found[el.name]) continue
      if (!el.checkValidity()) found[el.name] = nativeMessage(el)
    }
    const rules = { ...(validate?.() ?? {}), ...extra }
    Object.assign(found, Object.fromEntries(Object.entries(rules).filter(([, message]) => message)))
    show(found)
    return Object.keys(found).length === 0
  }

  /**
   * Shows a 422 response's errors on the form. Returns true when it did, so the caller can
   * skip its own error message:  catch (e) { if (!form.fromResponse(e)) showApiError(e, '...') }
   */
  const fromResponse = (error) => {
    const serverErrors = error?.response?.status === 422 && error.response.data?.errors
    if (!serverErrors) return false
    const names = [
      ...fieldsOf(formRef.current).map((el) => el.name),
      ...[...(formRef.current?.querySelectorAll('[data-field-error]') ?? [])].map((el) => el.dataset.fieldError),
    ]
    const extra = Object.entries(serverErrors)
      .filter(([key]) => !names.some((name) => errorFor({ [key]: true }, name)))
      .map(([, message]) => (Array.isArray(message) ? message[0] : message))
    show(serverErrors, extra)
    return true
  }

  const clear = useCallback((name) => {
    setErrors((current) => {
      if (!errorFor(current, name)) return current
      const base = name.replace(/\[\]$/, '')
      return Object.fromEntries(Object.entries(current).filter(([key]) => key !== name && key !== base && !key.startsWith(`${base}.`)))
    })
  }, [])

  /** For the <form>'s onChange: editing a field clears its error. */
  const onChange = useCallback((event) => clear(event.target.name), [clear])

  return { errors, unmatched, onChange, check, fromResponse, setErrors: show, clear }
}
