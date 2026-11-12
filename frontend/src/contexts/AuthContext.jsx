import { createContext, useCallback, useContext, useState, useEffect, useRef } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { loginUser, registerUser, logoutUser, getUser } from '../api/auth'
import useIdleLogout from '../hooks/useIdleLogout'
import { expiredLoginPathFor } from '../utils/session'
import { clearOfflineCopies } from '../utils/offlineItineraries'
import { stopPushForThisBrowser } from '../utils/pushNotifications'
import { clearSession, getStoredUser, getToken, saveSession, saveStoredUser, TOKEN_KEY, USER_KEY } from '../utils/authStorage'
import toast from 'react-hot-toast'

const AuthContext = createContext(null)

// Revokes the token on the server (best effort) and forgets it in this browser (every tab).
async function endSession() {
  // While still signed in: this browser stops getting this account's alerts.
  await stopPushForThisBrowser().catch(() => {})
  try {
    await logoutUser()
  } catch {
    // Already invalid or offline: clearing it locally is what matters.
  }
  clearSession()
}

// The server refused a login or registration because this browser is already signed in (maybe in
// another tab after this page opened): the browser's account stays, never replaced.
const alreadySignedIn = (error) => error?.response?.status === 409 && error.response.data?.code === 'already_authenticated'

export function AuthProvider({ children }) {
  const navigate = useNavigate()
  const location = useLocation()
  const [user, setUser] = useState(null)
  const [loading, setLoading] = useState(() => !!getToken())
  // The page visit (router location key) this tab signed in on: that page decides where to go
  // next. On any later visit, a reload or another tab signing in, sign-in pages send the account
  // to its dashboard.
  const [signedInOn, setSignedInOn] = useState(null)
  const signedInHere = signedInOn !== null && signedInOn === location.key
  const userRef = useRef(null)
  useEffect(() => { userRef.current = user }, [user])

  useEffect(() => {
    if (getToken()) {
      getUser()
        .then((data) => setUser(data))
        .catch(() => clearSession())
        .finally(() => setLoading(false))
    }
  }, [])

  // Every tab of this browser follows the one account: signing in, out or a profile update in
  // another tab shows here too.
  useEffect(() => {
    const onStorage = (event) => {
      if (event.key !== null && event.key !== TOKEN_KEY && event.key !== USER_KEY) return
      if (!getToken()) {
        if (userRef.current) toast('You were logged out in another tab.')
        setUser(null)
        setSignedInOn(null)
      } else if (event.key === USER_KEY && userRef.current) {
        const stored = getStoredUser()
        if (stored) setUser(stored)
      } else {
        getUser()
          .then((data) => {
            setUser(data)
            setSignedInOn(null)
          })
          .catch(() => {})
      }
    }
    window.addEventListener('storage', onStorage)
    return () => window.removeEventListener('storage', onStorage)
  }, [])

  // Loads the account this browser is signed in to and says why the attempt was refused; the
  // sign-in page then sends this tab to that account's dashboard.
  const adoptBrowserAccount = async (error, attemptedEmail) => {
    error.errorDialogShown = true
    try {
      const current = await getUser()
      setUser(current)
      setSignedInOn(null)
      if (attemptedEmail && current.email?.toLowerCase() === attemptedEmail.trim().toLowerCase()) {
        toast.success(`You're already logged in, ${current.name}.`)
        return
      }
    } catch {
      // The other tab's session just ended: the page stays, and logging in again works.
    }
    toast.error(error.response.data.message, { duration: 7000 })
  }

  const login = async (credentials) => {
    try {
      const data = await loginUser(credentials)
      saveSession(data.token, data.user)
      setSignedInOn(location.key)
      setUser(data.user)
      toast.success(`Welcome back, ${data.user.name}!`)
      return data.user
    } catch (error) {
      if (alreadySignedIn(error)) await adoptBrowserAccount(error, credentials.email)
      throw error
    }
  }

  const register = async (formData) => {
    try {
      const data = await registerUser(formData)
      saveSession(data.token, data.user)
      setSignedInOn(location.key)
      setUser(data.user)
      toast.success('Account created successfully!')
      return data.user
    } catch (error) {
      if (alreadySignedIn(error)) await adoptBrowserAccount(error)
      throw error
    }
  }

  const logout = async () => {
    await endSession()
    clearOfflineCopies().catch(() => {})
    setUser(null)
    setSignedInOn(null)
    toast.success('Logged out.')
  }

  const handleIdle = useCallback(async () => {
    const role = user?.role
    await endSession()
    setUser(null)
    setSignedInOn(null)
    navigate(expiredLoginPathFor(role))
  }, [user, navigate])

  useIdleLogout(user, handleIdle)

  // Merge fresh fields (e.g. account_state after a suspension ends) into the signed-in user.
  const updateUser = useCallback((patch) => {
    setUser((current) => {
      if (!current) return current
      const next = { ...current, ...patch }
      saveStoredUser(next)
      return next
    })
  }, [])

  const value = {
    user,
    loading,
    login,
    register,
    logout,
    updateUser,
    signedInHere,
    isAuthenticated: !!user,
    isAdmin: user?.role === 'admin',
    isTourist: user?.role === 'tourist',
    isBusinessOwner: user?.role === 'business_owner',
  }

  return (
    <AuthContext.Provider value={value}>
      {children}
    </AuthContext.Provider>
  )
}

// eslint-disable-next-line react-refresh/only-export-components
export function useAuth() {
  const context = useContext(AuthContext)
  if (!context) throw new Error('useAuth must be used within AuthProvider')
  return context
}