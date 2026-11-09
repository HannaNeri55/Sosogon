import { useState } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import { useAuth } from '../../contexts/AuthContext'
import { postAuthPath } from '../../utils/pendingItinerary'
import { FiCompass, FiUser, FiMail, FiPhone, FiLock } from 'react-icons/fi'
import { showApiError } from '../../utils/apiErrors'
import { focusFirstInvalid } from '../../utils/formErrors'

function Field({ label, name, type = 'text', value, onChange, error, required = true, icon: Icon, autoComplete = 'off' }) {
  return (
    <div>
      <label className="block text-sm font-medium text-gray-700 mb-1.5">
        {label} {required && <span className="text-red-500">*</span>}
      </label>
      <div className="relative">
        {Icon && <Icon size={16} className="absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400" />}
        <input
          name={name}
          type={type}
          autoComplete={autoComplete}
          className={`input-field ${Icon ? 'pl-10' : ''} ${error ? 'input-error' : ''}`}
          value={value}
          onChange={onChange}
          aria-invalid={!!error}
        />
      </div>
      {error && <p className="field-error">{Array.isArray(error) ? error[0] : error}</p>}
    </div>
  )
}

export default function TouristRegister() {
  const { register } = useAuth()
  const navigate = useNavigate()
  const [loading, setLoading] = useState(false)
  const [errors, setErrors] = useState({})
  const [agreed, setAgreed] = useState(false)
  const [formData, setFormData] = useState({
    name: '',
    email: '',
    phone: '',
    password: '',
    password_confirmation: '',
    role: 'tourist',
  })

  const validate = () => {
    const newErrors = {}
    if (!formData.name.trim()) newErrors.name = 'Full name is required'
    if (!formData.email.trim()) newErrors.email = 'Email is required'
    else if (!/\S+@\S+\.\S+/.test(formData.email)) newErrors.email = 'Invalid email format'
    if (!formData.phone.trim()) newErrors.phone = 'Phone number is required'
    else if (formData.phone.length < 10) newErrors.phone = 'Phone must be at least 10 digits'
    if (!formData.password) newErrors.password = 'Password is required'
    else if (formData.password.length < 8) newErrors.password = 'Password must be at least 8 characters'
    if (formData.password !== formData.password_confirmation) newErrors.password_confirmation = 'Passwords do not match'
    setErrors(newErrors)
    return Object.keys(newErrors).length === 0
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    if (!validate()) {
      focusFirstInvalid()
      return
    }

    setLoading(true)
    try {
      const user = await register(formData)
      navigate(postAuthPath(user))
    } catch (e) {
      const serverErrors = e.response?.data?.errors
      if (serverErrors) {
        setErrors(serverErrors)
        focusFirstInvalid()
      } else {
        showApiError(e, 'Registration failed')
      }
    } finally {
      setLoading(false)
    }
  }

  const handleInputChange = (name, value) => {
    setFormData({ ...formData, [name]: value })
    setErrors({ ...errors, [name]: '' })
  }

  return (
    <div className="min-h-screen bg-gradient-to-br from-gray-50 via-gray-50 to-primary-50/40 flex items-center justify-center px-4 py-12">
      <div className="max-w-lg w-full bg-white rounded-2xl shadow-lg shadow-gray-200/60 border border-gray-100 overflow-hidden">
        <div className="h-1.5 bg-gradient-to-r from-primary-500 to-primary-600" />
        <div className="p-6 sm:p-8">
          <div className="text-center mb-8">
            <div className="w-14 h-14 rounded-full bg-primary-50 ring-4 ring-primary-50/50 flex items-center justify-center mx-auto mb-4">
              <FiCompass size={26} className="text-primary-600" />
            </div>
            <h1 className="text-2xl font-heading font-bold text-gray-900 mb-2">Create your tourist account</h1>
            <p className="text-gray-500 text-sm">Explore Sorsogon and plan your trips with a free account</p>
          </div>

          <form onSubmit={handleSubmit} className="space-y-4">
            <Field
              label="Full Name"
              name="name"
              autoComplete="off"
              icon={FiUser}
              value={formData.name}
              error={errors.name}
              onChange={(e) => handleInputChange('name', e.target.value)}
            />
            <Field
              label="Email Address"
              name="email"
              type="email"
              autoComplete="off"
              icon={FiMail}
              value={formData.email}
              error={errors.email}
              onChange={(e) => handleInputChange('email', e.target.value)}
            />
            <Field
              label="Phone Number"
              name="phone"
              type="tel"
              autoComplete="off"
              icon={FiPhone}
              value={formData.phone}
              error={errors.phone}
              onChange={(e) => handleInputChange('phone', e.target.value)}
            />
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <Field
                label="Password"
                name="password"
                type="password"
                autoComplete="new-password"
                icon={FiLock}
                value={formData.password}
                error={errors.password}
                onChange={(e) => handleInputChange('password', e.target.value)}
              />
              <Field
                label="Confirm Password"
                name="password_confirmation"
                type="password"
                autoComplete="new-password"
                icon={FiLock}
                value={formData.password_confirmation}
                error={errors.password_confirmation}
                onChange={(e) => handleInputChange('password_confirmation', e.target.value)}
              />
            </div>

            <label className="flex items-start gap-2.5 text-sm text-gray-600">
              <input
                type="checkbox"
                checked={agreed}
                onChange={(e) => setAgreed(e.target.checked)}
                className="mt-0.5 h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500"
              />
              <span>
                I agree to the{' '}
                <Link to="/privacy-policy" className="text-primary-600 font-medium hover:text-primary-700">
                  Privacy Policy
                </Link>{' '}
                and{' '}
                <Link to="/terms-and-conditions" className="text-primary-600 font-medium hover:text-primary-700">
                  Terms &amp; Conditions
                </Link>
              </span>
            </label>

            <button type="submit" className="btn-primary w-full justify-center mt-2" disabled={loading || !agreed}>
              {loading ? 'Creating account...' : 'Create Tourist Account'}
            </button>
          </form>

          <p className="text-center text-sm text-gray-500 mt-6">
            Already have an account?{' '}
            <Link to="/login" className="text-primary-600 font-medium">Login</Link>
          </p>
        </div>
      </div>
    </div>
  )
}