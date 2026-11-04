import { useCallback, useEffect, useState } from 'react'
import { Navigate, Link, useNavigate } from 'react-router-dom'
import { FiLogOut } from 'react-icons/fi'
import { getBusinessDashboard, getBusinessSubmissions } from '../../api/business'
import { useAuth } from '../../contexts/AuthContext'
import LoadingSpinner from '../../components/common/LoadingSpinner'
import SubmitListing from './SubmitListing'
import SubmissionStatus from './SubmissionStatus'
import ApplicationStatus from './ApplicationStatus'

export default function BusinessOnboarding() {
  const { logout, user } = useAuth()
  // The server blocks business actions until the registration is approved; show its status instead.
  const awaitingApproval = user?.role === 'business_owner' && user?.status && user.status !== 'active'
  const navigate = useNavigate()
  const [dashboard, setDashboard] = useState(null)
  const [submissions, setSubmissions] = useState([])
  const [loading, setLoading] = useState(true)
  const [showForm, setShowForm] = useState(false)

  const load = useCallback(() => {
    return Promise.all([getBusinessDashboard(), getBusinessSubmissions()]).then(([dash, subs]) => {
      setDashboard(dash)
      setSubmissions(subs.data || [])
    })
  }, [])

  useEffect(() => {
    if (awaitingApproval) return
    load().finally(() => setLoading(false))
  }, [load, awaitingApproval])

  const handleLogout = async () => {
    await logout()
    navigate('/')
  }

  if (awaitingApproval) {
    return (
      <div className="min-h-screen bg-gray-50">
        <header className="bg-white border-b border-gray-200">
          <div className="max-w-4xl mx-auto px-4 sm:px-6 flex items-center justify-between h-16">
            <Link to="/" className="flex items-center gap-2">
              <img src="/images/Logo.png" alt="Sorsogon" className="h-9 w-auto" />
            </Link>
            <button onClick={handleLogout} className="flex items-center gap-2 text-sm text-gray-500 hover:text-gray-700">
              <FiLogOut size={16} /> Logout
            </button>
          </div>
        </header>
        <main className="max-w-4xl mx-auto px-4 sm:px-6 py-10">
          <ApplicationStatus />
        </main>
      </div>
    )
  }

  if (loading) return <LoadingSpinner size="lg" />
  if (dashboard?.has_accommodation) return <Navigate to="/business/accommodation" replace />
  if (dashboard?.has_food_beverage) return <Navigate to="/business/food-beverage" replace />
  if (dashboard?.has_retail_shop) return <Navigate to="/business/retail-shop" replace />

  const handleSubmitted = () => {
    setShowForm(false)
    load()
  }

  return (
    <div className="min-h-screen bg-gray-50">
      <header className="bg-white border-b border-gray-200">
        <div className="max-w-4xl mx-auto px-4 sm:px-6 flex items-center justify-between h-16">
          <Link to="/" className="flex items-center gap-2">
            <img src="/images/Logo.png" alt="Sorsogon" className="h-9 w-auto" />
          </Link>
          <button onClick={handleLogout} className="flex items-center gap-2 text-sm text-gray-500 hover:text-gray-700">
            <FiLogOut size={16} /> Logout
          </button>
        </div>
      </header>

      <main className="max-w-4xl mx-auto px-4 sm:px-6 py-10">
        {submissions.length === 0 || showForm ? (
          <SubmitListing onSuccess={handleSubmitted} />
        ) : (
          <SubmissionStatus submissions={submissions} onChange={load} onAddNew={() => setShowForm(true)} />
        )}
      </main>
    </div>
  )
}
