import { useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { FiLock, FiEye, FiEyeOff, FiAlertTriangle } from 'react-icons/fi'
import { resetPassword } from '../../api/auth'
import toast from 'react-hot-toast'

export default function ResetPassword() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const token = searchParams.get('token')
  const email = searchParams.get('email')

  const [loading, setLoading] = useState(false)
  const [showPassword, setShowPassword] = useState(false)
  const [expired, setExpired] = useState(false)
  const [formData, setFormData] = useState({ password: '', password_confirmation: '' })
  const [errors, setErrors] = useState({})

  if (!token || !email) {
    return (
      <div className="min-h-screen bg-gray-50 flex items-center justify-center px-4 py-12">
        <div className="max-w-md w-full bg-white rounded-2xl shadow-sm border border-gray-100 p-8 text-center">
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

  const validate = () => {
    const newErrors = {}
    if (!formData.password) newErrors.password = 'Password is required'
    else if (formData.password.length < 8) newErrors.password = 'Password must be at least 8 characters'
    if (formData.password !== formData.password_confirmation) newErrors.password_confirmation = 'Passwords do not match'
    setErrors(newErrors)
    return Object.keys(newErrors).length === 0
  }

  const handleInputChange = (name, value) => {
    setFormData({ ...formData, [name]: value })
    setErrors({ ...errors, [name]: '' })
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    if (!validate()) return

    setLoading(true)
    try {
      const data = await resetPassword({ token, email, ...formData })
      toast.success(data.message || 'Your password has been reset successfully.')
      navigate('/login')
    } catch (e) {
      const serverErrors = e.response?.data?.errors
      if (serverErrors) {
        setErrors(serverErrors)
        Object.values(serverErrors).flat().forEach((msg) => toast.error(msg))
        if (serverErrors.email) setExpired(true)
      } else {
        toast.error(e.response?.data?.message || 'Something went wrong. Please try again.')
      }
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="min-h-screen bg-gray-50 flex items-center justify-center px-4 py-12">
      <div className="max-w-md w-full bg-white rounded-2xl shadow-sm border border-gray-100 p-8">
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

        <form onSubmit={handleSubmit} className="space-y-5">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">New Password</label>
            <div className="relative">
              <FiLock className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={16} />
              <input
                name="password"
                type={showPassword ? 'text' : 'password'}
                autoComplete="new-password"
                className={`input-field pl-9 pr-9 ${errors.password ? 'border-red-400 focus:ring-red-400' : ''}`}
                value={formData.password}
                onChange={(e) => handleInputChange('password', e.target.value)}
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
            {errors.password && <p className="text-red-500 text-xs mt-1">{Array.isArray(errors.password) ? errors.password[0] : errors.password}</p>}
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">Confirm New Password</label>
            <div className="relative">
              <FiLock className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={16} />
              <input
                name="password_confirmation"
                type={showPassword ? 'text' : 'password'}
                autoComplete="new-password"
                className={`input-field pl-9 ${errors.password_confirmation ? 'border-red-400 focus:ring-red-400' : ''}`}
                value={formData.password_confirmation}
                onChange={(e) => handleInputChange('password_confirmation', e.target.value)}
              />
            </div>
            {errors.password_confirmation && <p className="text-red-500 text-xs mt-1">{Array.isArray(errors.password_confirmation) ? errors.password_confirmation[0] : errors.password_confirmation}</p>}
          </div>

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
