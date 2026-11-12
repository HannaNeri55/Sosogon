import { Navigate } from 'react-router-dom'
import { useAuth } from '../contexts/AuthContext'
import { homePathFor } from '../utils/session'

/**
 * Sign-in and registration pages are for signed-out visitors. When this browser is already signed
 * in (a reload, another tab, or a refused attempt to use another account), they send the visitor
 * to that account's dashboard instead. A sign-in made on the page itself is left to the page,
 * which decides where to go next (e.g. back to the review the tourist was writing).
 */
export default function GuestOnlyRoute({ children }) {
  const { user, loading, signedInHere } = useAuth()

  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <div className="animate-spin rounded-full h-10 w-10 border-b-2 border-primary-500"></div>
      </div>
    )
  }

  if (user && !signedInHere) {
    return <Navigate to={homePathFor(user)} replace />
  }

  return children
}
