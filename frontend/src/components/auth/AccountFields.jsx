import { FiLock, FiMail, FiPhone, FiUser } from 'react-icons/fi'
import AuthField, { PasswordChecklist, PasswordField } from './AuthField'

/** Props for an input whose rules useLiveValidation checks. */
const bind = (name, values, live, onChange) => ({
  name,
  value: values[name],
  error: live.errors[name],
  onChange: (e) => onChange(name, e.target.value),
  onBlur: () => live.blurred(name),
})

/** Full name, email and phone, with format hints. */
export function PersonalFields({ idPrefix, values, live, onChange }) {
  return (
    <>
      <AuthField
        id={`${idPrefix}-name`}
        label="Full Name"
        required
        icon={FiUser}
        autoComplete="name"
        maxLength={100}
        placeholder="Juan Dela Cruz"
        hint="Your first and last name, e.g. Juan Dela Cruz. Letters only."
        {...bind('name', values, live, onChange)}
      />
      <AuthField
        id={`${idPrefix}-email`}
        label="Email Address"
        required
        icon={FiMail}
        type="email"
        inputMode="email"
        autoComplete="email"
        autoCapitalize="none"
        spellCheck={false}
        maxLength={255}
        placeholder="juandelacruz@gmail.com"
        hint="e.g. juandelacruz@gmail.com"
        {...bind('email', values, live, onChange)}
      />
      <AuthField
        id={`${idPrefix}-phone`}
        label="Phone Number"
        required
        icon={FiPhone}
        type="tel"
        inputMode="tel"
        autoComplete="tel"
        maxLength={20}
        placeholder="09171234567"
        hint="11-digit mobile number: 09XXXXXXXXX. From abroad, start with + and your country code."
        {...bind('phone', values, live, onChange)}
      />
    </>
  )
}

/** Password (with the requirements checklist) and its confirmation, each with a show/hide button. */
export function PasswordFields({ idPrefix, values, live, onChange, labels = ['Password', 'Confirm Password'] }) {
  const passwordError = live.errors.password
  // The checklist explains what's missing, so the message under the field only covers an
  // empty password or a message from the server.
  const passwordMessage = passwordError && values.password && passwordError === live.problems.password ? true : passwordError

  return (
    <>
      <div>
        <PasswordField
          id={`${idPrefix}-password`}
          label={labels[0]}
          required
          icon={FiLock}
          autoComplete="new-password"
          placeholder="At least 8 characters"
          describedBy={`${idPrefix}-password-rules`}
          {...bind('password', values, live, onChange)}
          error={passwordMessage}
        />
        <PasswordChecklist id={`${idPrefix}-password-rules`} password={values.password} showMissing={!!passwordError} />
      </div>
      <PasswordField
        id={`${idPrefix}-password-confirmation`}
        label={labels[1]}
        required
        icon={FiLock}
        autoComplete="new-password"
        placeholder="Re-enter your password"
        hint="Type the same password again."
        {...bind('password_confirmation', values, live, onChange)}
      />
    </>
  )
}
