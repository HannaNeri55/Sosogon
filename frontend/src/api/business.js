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

// The signed-in owner's own registration application (status, details, rejection reason).
export const getOwnerApplication = async () => {
  const response = await api.get('/business/application')
  return response.data
}

// The owner's own business permit, as a Blob (private file, never a public URL).
export const downloadOwnerPermit = async () => {
  const response = await api.get('/business/application/permit', { responseType: 'blob' })
  return response.data
}

export const resubmitOwnerApplication = async (formData) => {
  const response = await api.post('/business/application/resubmit', formData, {
    headers: { 'Content-Type': undefined },
  })
  return response.data
}
// How the owner's listing is doing: page views, reviews and itineraries (type: accommodation | food_beverage | retail_shop).
export const getBusinessInsights = async (type) => {
  const response = await api.get(`/business/insights/${type}`)
  return response.data
}
