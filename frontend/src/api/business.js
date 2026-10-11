import api from './axios'

export const getBusinessDashboard = async () => {
  const response = await api.get('/business/dashboard')
  return response.data
}

export const getBusinessSubmissions = async () => {
  const response = await api.get('/business/submissions')
  return response.data
}

export const createSubmission = async (formData) => {
  const response = await api.post('/business/submissions', formData, {
    headers: { 'Content-Type': undefined },
  })
  return response.data
}

export const updateSubmission = async (id, data) => {
  const response = await api.put(`/business/submissions/${id}`, data)
  return response.data
}

export const getBusinessProfile = async () => {
  const response = await api.get('/business/profile')
  return response.data
}

export const updateBusinessProfile = async (data) => {
  const response = await api.put('/business/profile', data)
  return response.data
}

export const deleteSubmission = async (id) => {
  const response = await api.delete(`/business/submissions/${id}`)
  return response.data
}