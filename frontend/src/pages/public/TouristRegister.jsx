import { useState } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import { useAuth } from '../../contexts/AuthContext'
import { postAuthPath } from '../../utils/pendingItinerary'
import { FiCompass } from 'react-icons/fi'
import { showApiError } from '../../utils/apiErrors'
import { focusFirstInvalid } from '../../utils/formErrors'
import { ACCOUNT_RULES, ACCOUNT_SHOW_NOW } from '../../utils/authValidation'
import useLiveValidation from '../../hooks/useLiveValidation'
import useEmailAvailability from '../../hooks/useEmailAvailability'
import { PasswordFields, PersonalFields } from '../../components/auth/AccountFields'

export default function TouristRegister() {
  const { register } = useAuth()
  const navigate = useNavigate()
  const [loading, setLoading] = useState(false)
  const [agreed, setAgreed] = useState(false)
  const [formData, setFormData] = useState({
    name: '',
    email: '',
    phone: '',
    password: '',
    password_confirmation: '',
    role: 'tourist',
  })
  // Each field is checked while the user types (see useLiveValidation); the server checks again.
  const live = useLiveValidation(formData, ACCOUNT_RULES, { now: ACCOUNT_SHOW_NOW })
  useEmailAvailability(formData.email, live)

  const handleSubmit = async (e) => {
    e.preventDefault()
    if (!live.submit()) {
      focusFirstInvalid()
      return
    }

    setLoading(true)
    try {
      // The password is sent exactly as typed; name and email lose stray outer spaces.
      const user = await register({ ...formData, name: formData.name.trim(), email: formData.email.trim() })
      navigate(postAuthPath(user))
    } catch (e) {
      const serverErrors = e.response?.status === 422 && e.response.data?.errors
      if (serverErrors) {
        live.setServerErrors(serverErrors)
        focusFirstInvalid()
      } else {
        showApiError(e, 'Registration failed')
      }
    } finally {
      setLoading(false)
    }
  }

  const handleInputChange = (name, value) => {
    setFormData((current) => ({ ...current, [name]: value }))
    live.changed(name)
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

          <form onSubmit={handleSubmit} className="space-y-4" noValidate>
            <PersonalFields idPrefix="tourist" values={formData} live={live} onChange={handleInputChange} />
            <PasswordFields idPrefix="tourist" values={formData} live={live} onChange={handleInputChange} />

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
