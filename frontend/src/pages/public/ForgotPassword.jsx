import { useState } from 'react'
import { Link } from 'react-router-dom'
import { FiMail, FiCheckCircle } from 'react-icons/fi'
import { forgotPassword } from '../../api/auth'
import toast from 'react-hot-toast'

export default function ForgotPassword() {
  const [email, setEmail] = useState('')
  const [loading, setLoading] = useState(false)
  const [submitted, setSubmitted] = useState(false)
  const [message, setMessage] = useState('')

  const handleSubmit = async (e) => {
    e.preventDefault()
    setLoading(true)
    try {
      const data = await forgotPassword(email)
      setMessage(data.message || "If an account with that email exists, we've sent a reset link.")
      setSubmitted(true)
    } catch (e) {
      toast.error(e.response?.data?.message || 'Something went wrong. Please try again.')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="min-h-screen bg-gray-50 flex items-center justify-center px-4 py-12">
      <div className="max-w-md w-full bg-white rounded-2xl shadow-sm border border-gray-100 p-6 sm:p-8">
        {submitted ? (
          <div className="text-center">
            <div className="w-16 h-16 rounded-full bg-primary-50 flex items-center justify-center mx-auto mb-6">
              <FiCheckCircle size={32} className="text-primary-600" />
            </div>
            <h1 className="text-2xl font-heading font-bold text-gray-900 mb-3">Check your email</h1>
            <p className="text-gray-600 mb-8 leading-relaxed">{message}</p>
            <Link to="/login" className="btn-primary">Return to Login</Link>
          </div>
        ) : (
          <>
            <div className="mb-8">
              <h1 className="text-2xl font-heading font-bold text-gray-900 mb-2">Forgot your password?</h1>
              <p className="text-gray-500 text-sm">
                Enter the email associated with your account and we'll send you a reset link.
              </p>
            </div>

            <form onSubmit={handleSubmit} className="space-y-5">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">Email</label>
                <div className="relative">
                  <FiMail className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={16} />
                  <input
                    name="email"
                    type="email"
                    autoComplete="username"
                    className="input-field pl-9"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    required
                  />
                </div>
              </div>
              <button type="submit" className="btn-primary w-full justify-center" disabled={loading}>
                {loading ? 'Sending...' : 'Send Reset Link'}
              </button>
            </form>

            <p className="text-center text-sm text-gray-500 mt-6">
              Remember your password?{' '}
              <Link to="/login" className="text-primary-600 font-medium hover:text-primary-700">
                Login
              </Link>
            </p>
          </>
        )}
      </div>
    </div>
  )
}
