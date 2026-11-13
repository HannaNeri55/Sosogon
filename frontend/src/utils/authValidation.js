/*
 * Rules for the login, registration and password reset forms. The server checks the same rules
 * with the same wording (backend AuthController and App\Rules\StrongPassword), so a message
 * seen while typing is the one the server would give.
 */

/** name@domain.tld: no spaces, one @, and a dot in the domain followed by at least 2 letters. */
export const EMAIL_PATTERN = /^[^\s@]+@[^\s@.]+(\.[^\s@.]+)*\.[A-Za-z]{2,}$/

/** Letters (any language, e.g. ñ) with spaces, periods, hyphens and apostrophes between them. */
const NAME_PATTERN = /^[\p{L}\p{M}][\p{L}\p{M} .'’-]*$/u

/** A Philippine mobile number (09XXXXXXXXX or +639XXXXXXXXX), or another country's number with its + code. */
const PHONE_PATTERN = /^(09\d{9}|\+639\d{9}|\+(?!63)[1-9]\d{6,14})$/

/** "0917 123 4567" and "0917-123-4567" are read (and stored by the server) as 09171234567. */
export const normalizePhone = (value) => value.replace(/[\s\-().]/g, '')

/** The official password requirements, in the order the checklist shows them. */
export const PASSWORD_REQUIREMENTS = [
  { key: 'length', label: 'At least 8 characters', missing: 'at least 8 characters', test: (p) => [...p].length >= 8 },
  { key: 'uppercase', label: 'One uppercase letter (A–Z)', missing: 'an uppercase letter (A–Z)', test: (p) => /[A-Z]/.test(p) },
  { key: 'lowercase', label: 'One lowercase letter (a–z)', missing: 'a lowercase letter (a–z)', test: (p) => /[a-z]/.test(p) },
  { key: 'number', label: 'One number (0–9)', missing: 'a number (0–9)', test: (p) => /[0-9]/.test(p) },
  { key: 'special', label: 'One special character (e.g. ! @ # $ % & *)', missing: 'a special character (e.g. ! @ # $ % & *)', test: (p) => /[^A-Za-z0-9\s]/.test(p) },
  { key: 'spaces', label: 'No spaces', test: (p) => !/\s/.test(p) },
]

export const passwordMeetsRequirements = (password) => PASSWORD_REQUIREMENTS.every((r) => r.test(password))

export function validateName(value) {
  const name = value.trim()
  if (!name) return 'Enter your full name.'
  if (!NAME_PATTERN.test(name)) return 'Use letters only. Spaces, periods, hyphens and apostrophes are fine.'
  if ([...name].length < 2) return 'Enter your full name (at least 2 letters).'
  if ([...name].length > 100) return 'Keep your name to 100 characters or fewer.'
  return undefined
}

export function validateEmail(value) {
  const email = value.trim()
  if (!email) return 'Enter your email address.'
  if (email.length > 255) return 'Keep your email address to 255 characters or fewer.'
  if (!EMAIL_PATTERN.test(email)) return 'Enter a valid email address, like juandelacruz@gmail.com.'
  return undefined
}

export function validatePhone(value) {
  if (!value.trim()) return 'Enter your phone number.'
  if (!PHONE_PATTERN.test(normalizePhone(value))) return 'Enter a mobile number like 09171234567. From abroad, start with + and your country code.'
  return undefined
}

/** Never trims or changes the password: it is checked exactly as typed. */
export function validatePassword(value) {
  if (!value) return 'Enter a password.'
  const missing = PASSWORD_REQUIREMENTS.filter((r) => r.missing && !r.test(value)).map((r) => r.missing)
  if (missing.length) {
    const last = missing.pop()
    return `Your password needs ${missing.length ? `${missing.join(', ')} and ` : ''}${last}.`
  }
  if (/\s/.test(value)) return 'Your password must not contain spaces.'
  return undefined
}

export function validateConfirmation(value, password) {
  if (!value) return 'Re-enter your password.'
  if (value !== password) return 'Passwords do not match.'
  return undefined
}

/**
 * Whether a confirmation being typed is already known to be wrong: it no longer matches the
 * start of the password, or it is at least as long and still different. A correct one that
 * isn't finished yet ("Hann" for "Hanna123!") is left alone until the user leaves the field.
 */
export const confirmationClearlyWrong = (value, password) => value !== '' && (!password.startsWith(value) || value.length >= password.length) && value !== password

/** The account fields every registration form has, as rules for useLiveValidation. */
export const ACCOUNT_RULES = {
  name: (v) => validateName(v.name),
  email: (v) => validateEmail(v.email),
  phone: (v) => validatePhone(v.phone),
  password: (v) => validatePassword(v.password),
  password_confirmation: (v) => validateConfirmation(v.password_confirmation, v.password),
}

/**
 * Mistakes no amount of further typing can fix, shown at once instead of after a pause:
 * a digit or symbol in a name, a space or second @ in an email, a letter in a phone number,
 * a space in a password, a confirmation that has gone wrong.
 */
export const ACCOUNT_SHOW_NOW = {
  name: (v) => /[^\p{L}\p{M} .'’-]/u.test(v.name),
  email: (v) => /\s/.test(v.email.trim()) || (v.email.match(/@/g) || []).length > 1 || v.email.trim().startsWith('@') || v.email.includes('..'),
  phone: (v) => /[^\d\s\-().+]/.test(v.phone) || normalizePhone(v.phone).replace('+', '').length > 15,
  password: (v) => /\s/.test(v.password),
  password_confirmation: (v) => confirmationClearlyWrong(v.password_confirmation, v.password),
}

export const EMAIL_TAKEN = 'This email is already registered. Log in instead, or use a different email.'
