import { useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '../../contexts/AuthContext'
import { FiShield } from 'react-icons/fi'
import SessionExpiredNotice from '../../components/common/SessionExpiredNotice'
import LoginAlert from '../../components/common/LoginAlert'
import { loginErrors, validateLogin } from '../../utils/loginErrors'

export default function AdminLogin() {
  const { login } = useAuth()
  const navigate = useNavigate()
  const [loading, setLoading] = useState(false)
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
      setErrors(result.fields)
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
          <div>
            <label htmlFor="admin-email" className="block text-sm font-medium text-gray-700 mb-2">Email Address</label>
            <input
              ref={emailRef}
              id="admin-email"
              name="email"
              type="email"
              autoComplete="username"
              className={`input-field ${errors.email ? 'input-error' : ''}`}
              value={formData.email}
              onChange={(e) => change('email', e.target.value)}
              aria-invalid={!!errors.email}
              aria-describedby={typeof errors.email === 'string' ? 'admin-email-error' : errors.credentials ? 'admin-password-error' : undefined}
            />
            {typeof errors.email === 'string' && <p id="admin-email-error" className="field-error">{errors.email}</p>}
          </div>
          <div>
            <label htmlFor="admin-password" className="block text-sm font-medium text-gray-700 mb-2">Password</label>
            <input
              ref={passwordRef}
              id="admin-password"
              name="password"
              type="password"
              autoComplete="current-password"
              className={`input-field ${errors.password ? 'input-error' : ''}`}
              value={formData.password}
              onChange={(e) => change('password', e.target.value)}
              aria-invalid={!!errors.password}
              aria-describedby={errors.password ? 'admin-password-error' : undefined}
            />
            {typeof errors.password === 'string' && <p id="admin-password-error" className="field-error">{errors.password}</p>}
          </div>
          <button type="submit" className="btn-primary w-full justify-center" disabled={loading}>
            {loading ? 'Authenticating...' : 'Login as Admin'}
          </button>
        </form>

        <LoginAlert
          alert={alert}
          onClose={() => {
            setAlert(null)
            if (errors.credentials) passwordRef.current?.select()
          }}
        />

        <p className="text-center text-xs text-gray-400 mt-6">
          Admin accounts are managed by the system administrator.
        </p>
      </div>
    </div>
  )
}
