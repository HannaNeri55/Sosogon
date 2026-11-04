import { Navigate } from 'react-router-dom'
import { useAuth } from '../contexts/AuthContext'
import { isRestrictedAccount } from '../api/account'

export default function ProtectedRoute({ children, roles = [], businessType, allowRestricted = false }) {
  const { user, loading, isAuthenticated } = useAuth()

  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <div className="animate-spin rounded-full h-10 w-10 border-b-2 border-primary-500"></div>
      </div>
    )
  }

  if (!isAuthenticated) {
    return <Navigate to="/login" replace />
  }

  if (roles.length > 0 && !roles.includes(user.role)) {
    return <Navigate to="/" replace />
  }

  // Suspended or deactivated accounts only reach the Account Health page (the API enforces this too).
  if (!allowRestricted && isRestrictedAccount(user)) {
    return <Navigate to="/account/status" replace />
  }

  // Admins have no business_type of their own and manage every dashboard, so the
  // type check only applies to business_owner users.
  // Owners whose registration isn't approved see their application status at /business
  // (the API refuses dashboard actions for them anyway).
  if (businessType && user.role === 'business_owner' && user.status && user.status !== 'active') {
    return <Navigate to="/business" replace />
  }

  if (businessType && user.role !== 'admin' && user.business_type !== businessType) {
    return <Navigate to="/forbidden" replace state={{ attemptedType: businessType }} />
  }

  return children
}