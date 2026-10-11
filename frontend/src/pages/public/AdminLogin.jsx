import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '../../contexts/AuthContext'
import toast from 'react-hot-toast'
import { FiShield } from 'react-icons/fi'
import SessionExpiredNotice from '../../components/common/SessionExpiredNotice'

export default function AdminLogin() {
  const { login } = useAuth()
  const navigate = useNavigate()
  const [loading, setLoading] = useState(false)
  const [formData, setFormData] = useState({ email: '', password: '' })

  const handleSubmit = async (e) => {
    e.preventDefault()
    setLoading(true)
    try {
      const user = await login(formData)
      if (user.role !== 'admin') {
        toast.error('This login is for administrators only.')
        return
      }
      navigate('/admin')
    } catch (e) {
      toast.error(e.response?.data?.message || 'Invalid admin credentials')
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

        <form onSubmit={handleSubmit} className="space-y-5">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">Email Address</label>
            <input name="email" type="email" autoComplete="username" className="input-field" value={formData.email} onChange={(e) => setFormData({ ...formData, email: e.target.value })} required />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">Password</label>
            <input name="password" type="password" autoComplete="current-password" className="input-field" value={formData.password} onChange={(e) => setFormData({ ...formData, password: e.target.value })} required />
          </div>
          <button type="submit" className="btn-primary w-full justify-center" disabled={loading}>
            {loading ? 'Authenticating...' : 'Login as Admin'}
          </button>
        </form>

        <p className="text-center text-xs text-gray-400 mt-6">
          Admin accounts are managed by the system administrator.
        </p>
      </div>
    </div>
  )
}