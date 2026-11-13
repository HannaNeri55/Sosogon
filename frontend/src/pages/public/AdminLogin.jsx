import { useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '../../contexts/AuthContext'
import { FiLock, FiMail, FiShield } from 'react-icons/fi'
import SessionExpiredNotice from '../../components/common/SessionExpiredNotice'
import LoginAlert from '../../components/common/LoginAlert'
import { LOGIN_RULES, LOGIN_SHOW_NOW, loginErrors } from '../../utils/loginErrors'
import useLiveValidation from '../../hooks/useLiveValidation'
import AuthField, { PasswordField } from '../../components/auth/AuthField'

export default function AdminLogin() {
  const { login } = useAuth()
  const navigate = useNavigate()
  const [loading, setLoading] = useState(false)
  const [formData, setFormData] = useState({ email: '', password: '' })
  // Fields are checked while the user types (useLiveValidation); a refused sign-in opens a
  // dialog (see utils/loginErrors.js).
  const live = useLiveValidation(formData, LOGIN_RULES, { now: LOGIN_SHOW_NOW })
  const [alert, setAlert] = useState(null)
  const [wrongCredentials, setWrongCredentials] = useState(false)
  const emailRef = useRef(null)
  const passwordRef = useRef(null)

  const change = (name, value) => {
    setFormData((current) => ({ ...current, [name]: value }))
    live.changed(name)
    // A wrong-credentials error belongs to both fields: editing either clears it.
    if (wrongCredentials) {
      setWrongCredentials(false)
      live.clearServerErrors()
    }
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    if (!live.submit()) {
      (LOGIN_RULES.email(formData) ? emailRef : passwordRef).current?.focus()
      return
    }

    setLoading(true)
    try {
      const user = await login(formData)
      if (user.role !== 'admin') {
        setAlert({
          icon: FiShield,
          title: 'Administrators only',
          message: 'This login is for Tourism Office staff. Please use the main login page for your account.',
          button: 'OK',
        })
        return
      }
      navigate('/admin')
    } catch (error) {
      const result = loginErrors(error)
      const { credentials, ...fields } = result.fields
      setWrongCredentials(!!credentials)
      live.setServerErrors(fields)
      setAlert(result.alert)
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="min-h-screen bg-gray-50 flex items-center justify-center px-4 py-12">
      <div className="max-w-md w-full bg-white rounded-2xl shadow-sm border border-gray-100 p-6 sm:p-8">
        <div className="text-center mb-8">
          <div className="w-14 h-14 rounded-full bg-primary-50 flex items-center justify-center mx-auto mb-4">
            <FiShield size={28} className="text-primary-600" />
          </div>
          <h1 className="text-2xl font-heading font-bold text-gray-900 mb-2">Admin Login</h1>
          <p className="text-gray-500 text-sm">Tourism Office staff access only</p>
        </div>

        <SessionExpiredNotice />

        <form onSubmit={handleSubmit} className="space-y-5" noValidate>
          <AuthField
            id="admin-email"
            inputRef={emailRef}
            label="Email Address"
            icon={FiMail}
            name="email"
            type="email"
            inputMode="email"
            autoComplete="username"
            autoCapitalize="none"
            spellCheck={false}
            placeholder="name@example.com"
            hint="Your Tourism Office account email."
            value={formData.email}
            error={live.errors.email}
            describedBy={wrongCredentials ? 'admin-password-error' : undefined}
            onChange={(e) => change('email', e.target.value)}
            onBlur={() => live.blurred('email')}
          />
          <PasswordField
            id="admin-password"
            inputRef={passwordRef}
            label="Password"
            icon={FiLock}
            name="password"
            autoComplete="current-password"
            placeholder="Enter your password"
            value={formData.password}
            error={live.errors.password}
            onChange={(e) => change('password', e.target.value)}
            onBlur={() => live.blurred('password')}
          />
          <button type="submit" className="btn-primary w-full justify-center" disabled={loading}>
            {loading ? 'Authenticating...' : 'Login as Admin'}
          </button>
        </form>

        <LoginAlert
          alert={alert}
          onClose={() => {
            setAlert(null)
            if (wrongCredentials) passwordRef.current?.select()
          }}
        />

        <p className="text-center text-xs text-gray-400 mt-6">
          Admin accounts are managed by the system administrator.
        </p>
      </div>
    </div>
  )
}
