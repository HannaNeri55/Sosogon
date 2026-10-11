import { createContext, useCallback, useContext, useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { loginUser, registerUser, logoutUser, getUser } from '../api/auth'
import useIdleLogout from '../hooks/useIdleLogout'
import { expiredLoginPathFor } from '../utils/session'
import toast from 'react-hot-toast'

const AuthContext = createContext(null)

// Revokes the token on the server (best effort) and forgets it in this tab.
async function endSession() {
  try {
    await logoutUser()
  } catch {
    // Already invalid or offline: clearing it locally is what matters.
  }
  sessionStorage.removeItem('token')
  sessionStorage.removeItem('user')
}

export function AuthProvider({ children }) {
  const navigate = useNavigate()
  const [user, setUser] = useState(null)
  const [loading, setLoading] = useState(() => !!sessionStorage.getItem('token'))

  useEffect(() => {
    const token = sessionStorage.getItem('token')
    if (token) {
      getUser()
        .then((data) => setUser(data))
        .catch(() => {
          sessionStorage.removeItem('token')
          sessionStorage.removeItem('user')
        })
        .finally(() => setLoading(false))
    }
  }, [])

  const login = async (credentials) => {
    const data = await loginUser(credentials)
    sessionStorage.setItem('token', data.token)
    sessionStorage.setItem('user', JSON.stringify(data.user))
    setUser(data.user)
    toast.success(`Welcome back, ${data.user.name}!`)
    return data.user
  }

  const register = async (formData) => {
    const data = await registerUser(formData)
    sessionStorage.setItem('token', data.token)
    sessionStorage.setItem('user', JSON.stringify(data.user))
    setUser(data.user)
    toast.success('Account created successfully!')
    return data.user
  }

  const logout = async () => {
    await endSession()
    setUser(null)
    toast.success('Logged out.')
  }

  const handleIdle = useCallback(async () => {
    const role = user?.role
    await endSession()
    setUser(null)
    navigate(expiredLoginPathFor(role))
  }, [user, navigate])

  useIdleLogout(user, handleIdle)

  const value = {
    user,
    loading,
    login,
    register,
    logout,
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