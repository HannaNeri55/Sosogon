import { FiAlertCircle } from 'react-icons/fi'
import { errorFor } from '../../hooks/useFormErrors'

/** The message under a field, from useFormErrors(). Renders nothing while the field is fine. */
export default function FieldError({ form, name }) {
  const message = errorFor(form.errors, name)
  // The empty marker tells useFormErrors this name has a place on the form, so a server error
  // for it isn't also listed in the summary.
  if (!message) return <span hidden data-field-error={name} />
  return <p className="field-error" role="alert" data-field-error={name}>{message}</p>
}

/** Server errors that belong to no field on the form, shown above it. */
export function FormErrorSummary({ form }) {
  if (!form.unmatched.length) return null
  return (
    <div role="alert" className="mb-4 flex gap-2.5 rounded-lg border border-red-200 bg-red-50 p-3.5 text-sm text-red-700">
      <FiAlertCircle size={16} className="mt-0.5 shrink-0" aria-hidden="true" />
      <ul className="space-y-1">
        {form.unmatched.map((message) => <li key={message}>{message}</li>)}
      </ul>
    </div>
  )
}
