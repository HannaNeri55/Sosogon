import { useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { FiAlertTriangle } from 'react-icons/fi'
import { resetPassword } from '../../api/auth'
import toast from 'react-hot-toast'
import { showApiError } from '../../utils/apiErrors'
import { focusFirstInvalid } from '../../utils/formErrors'
import { ACCOUNT_RULES, ACCOUNT_SHOW_NOW } from '../../utils/authValidation'
import useLiveValidation from '../../hooks/useLiveValidation'
import { PasswordFields } from '../../components/auth/AccountFields'

const RULES = { password: ACCOUNT_RULES.password, password_confirmation: ACCOUNT_RULES.password_confirmation }
const SHOW_NOW = { password: ACCOUNT_SHOW_NOW.password, password_confirmation: ACCOUNT_SHOW_NOW.password_confirmation }

export default function ResetPassword() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const token = searchParams.get('token')
  const email = searchParams.get('email')

  const [loading, setLoading] = useState(false)
  const [expired, setExpired] = useState(false)
  const [formData, setFormData] = useState({ password: '', password_confirmation: '' })
  // The same requirements as registration, checked while the user types.
  const live = useLiveValidation(formData, RULES, { now: SHOW_NOW })

  if (!token || !email) {
    return (
      <div className="min-h-screen bg-gray-50 flex items-center justify-center px-4 py-12">
        <div className="max-w-md w-full bg-white rounded-2xl shadow-sm border border-gray-100 p-6 sm:p-8 text-center">
          <div className="w-16 h-16 rounded-full bg-red-50 flex items-center justify-center mx-auto mb-6">
            <FiAlertTriangle size={32} className="text-red-600" />
          </div>
          <h1 className="text-2xl font-heading font-bold text-gray-900 mb-3">Invalid or missing reset link</h1>
          <p className="text-gray-600 mb-8 leading-relaxed">
            This password reset link is missing required information. Please request a new one.
          </p>
          <Link to="/forgot-password" className="btn-primary">Request a new link</Link>
        </div>
      </div>
    )
  }

  const handleInputChange = (name, value) => {
    setFormData((current) => ({ ...current, [name]: value }))
    live.changed(name)
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    if (!live.submit()) {
      focusFirstInvalid()
      return
    }

    setLoading(true)
    try {
      const data = await resetPassword({ token, email, ...formData })
      toast.success(data.message || 'Your password has been reset successfully.')
      navigate('/login')
    } catch (e) {
      const serverErrors = e.response?.status === 422 && e.response.data?.errors
      if (serverErrors) {
        live.setServerErrors(serverErrors)
        focusFirstInvalid()
        if (serverErrors.email) setExpired(true)
      } else {
        showApiError(e, 'Something went wrong. Please try again.')
      }
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="min-h-screen bg-gray-50 flex items-center justify-center px-4 py-12">
      <div className="max-w-md w-full bg-white rounded-2xl shadow-sm border border-gray-100 p-6 sm:p-8">
        <div className="mb-8">
          <h1 className="text-2xl font-heading font-bold text-gray-900 mb-2">Set a new password</h1>
          <p className="text-gray-500 text-sm">
            Resetting password for <span className="font-medium text-gray-700">{email}</span>
          </p>
        </div>

        {expired && (
          <div className="mb-5 flex items-start gap-2.5 bg-red-50 border border-red-200 rounded-lg p-3.5 text-sm text-red-700">
            <FiAlertTriangle size={16} className="shrink-0 mt-0.5" />
            <span>
              This link is invalid or has expired.{' '}
              <Link to="/forgot-password" className="font-medium underline">Request a new link</Link>
            </span>
          </div>
        )}

        <form onSubmit={handleSubmit} className="space-y-5" noValidate>
          <PasswordFields
            idPrefix="reset"
            values={formData}
            live={live}
            onChange={handleInputChange}
            labels={['New Password', 'Confirm New Password']}
          />

          <button type="submit" className="btn-primary w-full justify-center" disabled={loading}>
            {loading ? 'Resetting...' : 'Reset Password'}
          </button>
        </form>

        <p className="text-center text-sm text-gray-500 mt-6">
          Remember your password?{' '}
          <Link to="/login" className="text-primary-600 font-medium hover:text-primary-700">
            Login
          </Link>
        </p>
      </div>
    </div>
  )
}
