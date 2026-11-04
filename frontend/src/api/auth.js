import api from './axios'

export const loginUser = async (credentials) => {
  const response = await api.post('/login', credentials)
  return response.data
}

export const registerUser = async (data) => {
  // Business owner registration sends FormData (permit upload). The client's default
  // JSON Content-Type would make axios serialize it to JSON and drop the file, so let
  // the browser set multipart/form-data with its boundary instead.
  const config = data instanceof FormData ? { headers: { 'Content-Type': undefined } } : undefined
  const response = await api.post('/register', data, config)
  return response.data
}

export const logoutUser = async () => {
  const response = await api.post('/logout')
  return response.data
}

export const getUser = async () => {
  const response = await api.get('/user')
  return response.data
}

export const forgotPassword = async (email) => {
  const response = await api.post('/forgot-password', { email })
  return response.data
}

export const resetPassword = async (payload) => {
  const response = await api.post('/reset-password', payload)
  return response.data
}