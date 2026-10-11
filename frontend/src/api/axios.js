import axios from 'axios'
import { loginPathFor, expiredLoginPathFor } from '../utils/session'

// VITE_API_URL is the backend's address in production (e.g. https://stim-api.onrender.com).
// Left empty locally, so requests stay relative and go through the Vite dev proxy.
const api = axios.create({
  baseURL: `${import.meta.env.VITE_API_URL ?? ''}/api/v1`,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
})

// Add token to requests
api.interceptors.request.use((config) => {
  const token = sessionStorage.getItem('token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

api.interceptors.response.use(
  (response) => response,
  (error) => {
    const url = error.config?.url || ''
    const isAuthAttempt = url.includes('/login') || url.includes('/register')

    if (error.response?.status === 401 && !isAuthAttempt) {
      // A stored token being rejected means the session expired (24 h limit, or idle
      // logout for admins and business owners).
      const hadSession = !!sessionStorage.getItem('token')
      let role
      try {
        role = JSON.parse(sessionStorage.getItem('user'))?.role
      } catch {
        // No readable user: fall back to the main login page.
      }
      sessionStorage.removeItem('token')
      sessionStorage.removeItem('user')
      window.location.href = hadSession ? expiredLoginPathFor(role) : loginPathFor(role)
    }
    return Promise.reject(error)
  }
)

export default api