// frontend/src/pages/public/Login.jsx
import { useRef, useState } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { FiMail, FiLock, FiEye, FiEyeOff } from 'react-icons/fi'
import { useAuth } from '../../contexts/AuthContext'
import { postAuthPath } from '../../utils/pendingItinerary'
import { loginErrors, validateLogin } from '../../utils/loginErrors'
import SessionExpiredNotice from '../../components/common/SessionExpiredNotice'
import LoginAlert from '../../components/common/LoginAlert'

export default function Login() {
  const { login } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [loading, setLoading] = useState(false)
  const [showPassword, setShowPassword] = useState(false)
  const [formData, setFormData] = useState({ email: '', password: '' })
  // Messages under each field, and the dialog for a refused sign-in (see utils/loginErrors.js).
  const [errors, setErrors] = useState({})
  const [alert, setAlert] = useState(null)
  const emailRef = useRef(null)
  const passwordRef = useRef(null)

  const change = (name, value) => {
    setFormData({ ...formData, [name]: value })
    // Typing again clears the red. A wrong-credentials error belongs to both fields.
    setErrors((current) => (current.credentials ? {} : { ...current, [name]: undefined }))
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    const invalid = validateLogin(formData)
    setErrors(invalid)
    if (invalid.email || invalid.password) {
      (invalid.email ? emailRef : passwordRef).current?.focus()
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
      const result = loginErrors(error)
      setErrors(result.fields)
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
            <div>
              <label htmlFor="login-email" className="block text-sm font-medium text-gray-700 mb-2">Email</label>
              <div className="relative">
                <FiMail className={`absolute left-3 top-1/2 -translate-y-1/2 ${errors.email ? 'text-red-500' : 'text-gray-400'}`} size={16} />
                <input
                  ref={emailRef}
                  id="login-email"
                  name="email"
                  type="email"
                  autoComplete="username"
                  className={`input-field pl-9 ${errors.email ? 'input-error' : ''}`}
                  value={formData.email}
                  onChange={(e) => change('email', e.target.value)}
                  aria-invalid={!!errors.email}
                  aria-describedby={typeof errors.email === 'string' ? 'login-email-error' : errors.credentials ? 'login-password-error' : undefined}
                />
              </div>
              {typeof errors.email === 'string' && <p id="login-email-error" className="field-error">{errors.email}</p>}
            </div>
            <div>
              <label htmlFor="login-password" className="block text-sm font-medium text-gray-700 mb-2">Password</label>
              <div className="relative">
                <FiLock className={`absolute left-3 top-1/2 -translate-y-1/2 ${errors.password ? 'text-red-500' : 'text-gray-400'}`} size={16} />
                <input
                  ref={passwordRef}
                  id="login-password"
                  name="password"
                  type={showPassword ? 'text' : 'password'}
                  autoComplete="current-password"
                  className={`input-field pl-9 pr-9 ${errors.password ? 'input-error' : ''}`}
                  value={formData.password}
                  onChange={(e) => change('password', e.target.value)}
                  aria-invalid={!!errors.password}
                  aria-describedby={errors.password ? 'login-password-error' : undefined}
                />
                <button
                  type="button"
                  onClick={() => setShowPassword(!showPassword)}
                  className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                  tabIndex={-1}
                >
                  {showPassword ? <FiEyeOff size={16} /> : <FiEye size={16} />}
                </button>
              </div>
              {typeof errors.password === 'string' && <p id="login-password-error" className="field-error">{errors.password}</p>}
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
              if (errors.credentials) passwordRef.current?.select()
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