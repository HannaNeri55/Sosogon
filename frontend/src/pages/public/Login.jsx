// frontend/src/pages/public/Login.jsx
import { useRef, useState } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { FiMail, FiLock } from 'react-icons/fi'
import { useAuth } from '../../contexts/AuthContext'
import { postAuthPath } from '../../utils/pendingItinerary'
import { LOGIN_RULES, LOGIN_SHOW_NOW, loginErrors } from '../../utils/loginErrors'
import useLiveValidation from '../../hooks/useLiveValidation'
import AuthField, { PasswordField } from '../../components/auth/AuthField'
import SessionExpiredNotice from '../../components/common/SessionExpiredNotice'
import LoginAlert from '../../components/common/LoginAlert'

export default function Login() {
  const { login } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
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
      // Tourists sent here from a page (e.g. "Write a Review") go back to it.
      const from = location.state?.from
      const restricted = ['suspended', 'deactivated'].includes(user.account_state)
      navigate(!restricted && user.role === 'tourist' && from?.startsWith('/') ? from : postAuthPath(user))
    } catch (error) {
      // The email and password stay as typed, so the user can correct them.
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
    <div className="min-h-screen flex">
      {/* Photo side */}
      <div className="hidden lg:block lg:w-1/2 relative">
        <img src="/images/destinations-hero.jpg" alt="Sorsogon" className="w-full h-full object-cover" />
        <div className="absolute inset-0 bg-gradient-to-t from-primary-900/80 via-primary-900/30 to-primary-900/10" />
        <div className="absolute inset-0 flex flex-col justify-end p-10">
          <div>
            <h2 className="text-3xl font-heading font-bold text-white mb-3 leading-tight">
              Discover the beauty of Sorsogon
            </h2>
            <p className="text-primary-100">
              Explore verified destinations, accommodations, local food, and products across the province.
            </p>
          </div>
        </div>
      </div>

      {/* Form side */}
      <div className="w-full lg:w-1/2 flex items-center justify-center bg-gray-50 px-4 py-12">
        <div className="max-w-md w-full">
          <div className="mb-8">
            <h1 className="text-3xl font-heading font-bold text-gray-900 mb-2">Welcome back</h1>
            <p className="text-gray-500">Login to access your account</p>
          </div>

          <SessionExpiredNotice />

          <form onSubmit={handleSubmit} className="space-y-5" noValidate>
            <AuthField
              id="login-email"
              inputRef={emailRef}
              label="Email"
              icon={FiMail}
              name="email"
              type="email"
              inputMode="email"
              autoComplete="username"
              autoCapitalize="none"
              spellCheck={false}
              placeholder="juandelacruz@gmail.com"
              hint="The email address you registered with."
              value={formData.email}
              error={live.errors.email}
              describedBy={wrongCredentials ? 'login-password-error' : undefined}
              onChange={(e) => change('email', e.target.value)}
              onBlur={() => live.blurred('email')}
            />
            <div>
              <PasswordField
                id="login-password"
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
              <div className="text-right mt-2">
                <Link to="/forgot-password" className="text-sm text-primary-600 font-medium hover:text-primary-700">
                  Forgot password?
                </Link>
              </div>
            </div>
            <button type="submit" className="btn-primary w-full justify-center" disabled={loading}>
              {loading ? 'Logging in...' : 'Login'}
            </button>
          </form>

          <LoginAlert
            alert={alert}
            onClose={() => {
              setAlert(null)
              if (wrongCredentials) passwordRef.current?.select()
            }}
          />

          <p className="text-center text-sm text-gray-500 mt-6">
            Don't have an account?{' '}
            <Link to="/get-started" className="text-primary-600 font-medium hover:text-primary-700">
              Register
            </Link>
          </p>
        </div>
      </div>
    </div>
  )
}