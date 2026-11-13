import { useState } from 'react'
import { FiCheck, FiCircle, FiEye, FiEyeOff, FiX } from 'react-icons/fi'
import { PASSWORD_REQUIREMENTS } from '../../utils/authValidation'

/**
 * A labelled input for the sign-in and registration forms: an optional icon on the left, the
 * format hint below it (subtle gray), replaced by the error (red border, red message) while the
 * value is wrong. `trailing` sits inside the input on the right (e.g. the show-password button).
 */
export default function AuthField({ id, label, required = false, icon: Icon, hint, error, trailing, describedBy, inputRef, className = '', ...inputProps }) {
  const message = typeof error === 'string' ? error : null
  const hintId = hint && !message ? `${id}-hint` : undefined
  const errorId = message ? `${id}-error` : undefined

  return (
    <div className={className}>
      <label htmlFor={id} className="block text-sm font-medium text-gray-700 mb-1.5">
        {label} {required && <span className="text-red-500" aria-hidden="true">*</span>}
      </label>
      <div className="relative">
        {Icon && <Icon size={16} aria-hidden="true" className={`pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 ${error ? 'text-red-500' : 'text-gray-400'}`} />}
        <input
          id={id}
          ref={inputRef}
          required={required}
          className={`input-field ${Icon ? 'pl-10' : ''} ${trailing ? 'pr-11' : ''} ${error ? 'input-error' : ''}`}
          aria-invalid={!!error}
          aria-describedby={[errorId, hintId, describedBy].filter(Boolean).join(' ') || undefined}
          {...inputProps}
        />
        {trailing}
      </div>
      {message ? (
        <p id={errorId} className="field-error" role="alert">{message}</p>
      ) : (
        hint && <p id={hintId} className="mt-1.5 text-xs text-gray-500">{hint}</p>
      )}
    </div>
  )
}

/** AuthField for a password, with its own show/hide button inside the input. */
export function PasswordField(props) {
  const [visible, setVisible] = useState(false)

  return (
    <AuthField
      {...props}
      type={visible ? 'text' : 'password'}
      autoCapitalize="none"
      autoCorrect="off"
      spellCheck={false}
      trailing={
        <button
          type="button"
          onClick={() => setVisible((v) => !v)}
          // Keep the cursor in the input when the button is clicked.
          onMouseDown={(e) => e.preventDefault()}
          className="absolute right-1 top-1/2 -translate-y-1/2 flex h-9 w-9 items-center justify-center rounded-md text-gray-400 hover:text-gray-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
          aria-label={visible ? 'Hide password' : 'Show password'}
          aria-pressed={visible}
          aria-controls={props.id}
        >
          {visible ? <FiEyeOff size={17} aria-hidden="true" /> : <FiEye size={17} aria-hidden="true" />}
        </button>
      }
    />
  )
}

/**
 * The password requirements, updated as the user types: gray until met, green once met, red
 * when still missing after the user has left the field or tried to submit (`showMissing`).
 */
export function PasswordChecklist({ id, password, showMissing }) {
  return (
    <div id={id} className="mt-2 rounded-lg bg-gray-50 px-3 py-2.5">
      <p className="text-xs font-medium text-gray-600 mb-1.5">Password requirements:</p>
      <ul className="grid grid-cols-1 sm:grid-cols-2 gap-x-3 gap-y-1">
        {PASSWORD_REQUIREMENTS.map((requirement) => {
          // "No spaces" only counts once something has been typed.
          const met = password !== '' && requirement.test(password)
          const missing = !met && showMissing
          const Icon = met ? FiCheck : missing ? FiX : FiCircle
          return (
            <li key={requirement.key} className={`flex items-start gap-1.5 text-xs ${met ? 'text-secondary-700' : missing ? 'text-red-600' : 'text-gray-500'}`}>
              <Icon size={13} className="mt-0.5 shrink-0" aria-hidden="true" />
              <span>
                {requirement.label}
                <span className="sr-only">{met ? ' (met)' : ' (not met yet)'}</span>
              </span>
            </li>
          )
        })}
      </ul>
    </div>
  )
}
