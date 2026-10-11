import { Navigate } from 'react-router-dom'
import { useAuth } from '../contexts/AuthContext'

export default function ProtectedRoute({ children, roles = [], businessType }) {
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

  // Admins have no business_type of their own and manage every dashboard, so the
  // type check only applies to business_owner users.
  if (businessType && user.role !== 'admin' && user.business_type !== businessType) {
    return <Navigate to="/forbidden" replace state={{ attemptedType: businessType }} />
  }

  return children
}