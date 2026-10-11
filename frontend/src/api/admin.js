import api from './axios'

// Dashboard
export const getAdminDashboard = async () => {
  const response = await api.get('/admin/dashboard')
  return response.data
}

// Destinations
export const getAdminDestinations = async (params = {}) => {
  const response = await api.get('/admin/destinations', { params })
  return response.data
}

export const createDestination = async (formData) => {
  const response = await api.post('/admin/destinations', formData, {
    headers: { 'Content-Type': undefined },
  })
  return response.data
}

export const updateDestination = async (id, formData) => {
  formData.append('_method', 'PUT')
  const response = await api.post(`/admin/destinations/${id}`, formData, {
    headers: { 'Content-Type': undefined },
  })
  return response.data
}

export const deleteDestination = async (id) => {
  const response = await api.delete(`/admin/destinations/${id}`)
  return response.data
}

export const deleteDestinationImage = async (id, imageId) => {
  const response = await api.delete(`/admin/destinations/${id}/images/${imageId}`)
  return response.data
}

// Submissions
export const getAdminSubmissions = async (params = {}) => {
  const response = await api.get('/admin/submissions', { params })
  return response.data
}

export const approveSubmission = async (id, notes = '') => {
  const response = await api.put(`/admin/submissions/${id}/approve`, { notes })
  return response.data
}

export const rejectSubmission = async (id, notes) => {
  const response = await api.put(`/admin/submissions/${id}/reject`, { notes })
  return response.data
}

export const warnSubmission = async (id, notes = '') => {
  const response = await api.put(`/admin/submissions/${id}/warn`, { notes })
  return response.data
}

export const clearSubmissionWarning = async (id) => {
  const response = await api.put(`/admin/submissions/${id}/clear-warning`)
  return response.data
}

export const removeSubmission = async (id, notes) => {
  const response = await api.put(`/admin/submissions/${id}/remove`, { notes })
  return response.data
}

export const downloadSubmissionPermit = async (id) => {
  const response = await api.get(`/admin/submissions/${id}/permit`, { responseType: 'blob' })
  return response.data
}

// Accommodations (property re-review)
export const getAdminAccommodations = async (params = {}) => {
  const response = await api.get('/admin/accommodations', { params })
  return response.data
}

export const updateAdminAccommodation = async (id, data) => {
  const response = await api.put(`/admin/accommodations/${id}`, data)
  return response.data
}

// Accommodation rooms
export const getAdminAccommodationRooms = async (params = {}) => {
  const response = await api.get('/admin/accommodation-rooms', { params })
  return response.data
}

export const approveAccommodationRoom = async (id, notes = '') => {
  const response = await api.put(`/admin/accommodation-rooms/${id}/approve`, { notes })
  return response.data
}

export const rejectAccommodationRoom = async (id, notes) => {
  const response = await api.put(`/admin/accommodation-rooms/${id}/reject`, { notes })
  return response.data
}

// Food & beverage establishments (re-review)
export const getAdminFoodBeverages = async (params = {}) => {
  const response = await api.get('/admin/food-beverages', { params })
  return response.data
}

export const updateAdminFoodBeverage = async (id, data) => {
  const response = await api.put(`/admin/food-beverages/${id}`, data)
  return response.data
}

// Menu items
export const getAdminMenuItems = async (params = {}) => {
  const response = await api.get('/admin/menu-items', { params })
  return response.data
}

export const approveMenuItem = async (id, notes = '') => {
  const response = await api.put(`/admin/menu-items/${id}/approve`, { notes })
  return response.data
}

export const rejectMenuItem = async (id, notes) => {
  const response = await api.put(`/admin/menu-items/${id}/reject`, { notes })
  return response.data
}

// Users
export const getAdminUsers = async (params = {}) => {
  const response = await api.get('/admin/users', { params })
  return response.data
}

export const updateAdminUser = async (id, data) => {
  const response = await api.put(`/admin/users/${id}`, data)
  return response.data
}

export const deleteAdminUser = async (id) => {
  const response = await api.delete(`/admin/users/${id}`)
  return response.data
}

// Reports
export const getReportVisitors = async (days = 30) => {
  const response = await api.get('/admin/reports/visitors', { params: { days } })
  return response.data
}

export const getReportPopular = async (limit = 10) => {
  const response = await api.get('/admin/reports/popular', { params: { limit } })
  return response.data
}

export const getReportSearches = async () => {
  const response = await api.get('/admin/reports/searches')
  return response.data
}

// Locations
export const getMunicipalities = async () => {
  const response = await api.get('/municipalities')
  return response.data
}

export const getBarangays = async (municipalityId) => {
  const response = await api.get(`/municipalities/${municipalityId}/barangays`)
  return response.data
}

export const getCategories = async (type = '') => {
  const response = await api.get('/categories', { params: type ? { type } : {} })
  return response.data
}

export const exportReportPdf = async () => {
  const response = await api.get('/admin/reports/export', { responseType: 'blob' })
  return response.data
}

export const getReportOverview = async () => {
  const response = await api.get('/admin/reports/overview')
  return response.data
}

// Retail Products
export const getAdminRetailProducts = async (params = {}) => {
  const response = await api.get('/admin/retail-products', { params })
  return response.data
}

export const createRetailProduct = async (formData) => {
  const response = await api.post('/admin/retail-products', formData, {
    headers: { 'Content-Type': undefined },
  })
  return response.data
}

export const updateRetailProduct = async (id, data) => {
  const response = await api.put(`/admin/retail-products/${id}`, data)
  return response.data
}

export const deleteRetailProduct = async (id) => {
  const response = await api.delete(`/admin/retail-products/${id}`)
  return response.data
}

export const approveRetailProduct = async (id, notes = '') => {
  const response = await api.put(`/admin/retail-products/${id}/approve`, { notes })
  return response.data
}

export const rejectRetailProduct = async (id, notes) => {
  const response = await api.put(`/admin/retail-products/${id}/reject`, { notes })
  return response.data
}

// Retail shops (owner shop profile submissions)
export const getAdminRetailShops = async (params = {}) => {
  const response = await api.get('/admin/retail-shops', { params })
  return response.data
}

export const approveRetailShop = async (id, notes = '') => {
  const response = await api.put(`/admin/retail-shops/${id}/approve`, { notes })
  return response.data
}

export const rejectRetailShop = async (id, notes) => {
  const response = await api.put(`/admin/retail-shops/${id}/reject`, { notes })
  return response.data
}

// Events
export const getAdminEvents = async (params = {}) => {
  const response = await api.get('/admin/events', { params })
  return response.data
}

export const createEvent = async (data) => {
  const response = await api.post('/admin/events', data)
  return response.data
}

export const updateEvent = async (id, data) => {
  const response = await api.put(`/admin/events/${id}`, data)
  return response.data
}

export const deleteEvent = async (id) => {
  const response = await api.delete(`/admin/events/${id}`)
  return response.data
}

// Activities
export const getAdminActivities = async (params = {}) => {
  const response = await api.get('/admin/activities', { params })
  return response.data
}

export const createActivity = async (formData) => {
  const response = await api.post('/admin/activities', formData, {
    headers: { 'Content-Type': undefined },
  })
  return response.data
}

export const updateActivity = async (id, formData) => {
  formData.append('_method', 'PUT')
  const response = await api.post(`/admin/activities/${id}`, formData, {
    headers: { 'Content-Type': undefined },
  })
  return response.data
}

export const deleteActivity = async (id) => {
  const response = await api.delete(`/admin/activities/${id}`)
  return response.data
}

// Hero Slides
export const getAdminHeroSlides = async (params = {}) => {
  const response = await api.get('/admin/hero-slides', { params })
  return response.data
}

export const createHeroSlide = async (formData) => {
  const response = await api.post('/admin/hero-slides', formData, {
    headers: { 'Content-Type': undefined },
  })
  return response.data
}

export const updateHeroSlide = async (id, formData) => {
  formData.append('_method', 'PUT')
  const response = await api.post(`/admin/hero-slides/${id}`, formData, {
    headers: { 'Content-Type': undefined },
  })
  return response.data
}

export const deleteHeroSlide = async (id) => {
  const response = await api.delete(`/admin/hero-slides/${id}`)
  return response.data
}

// Announcements
export const getAdminAnnouncements = async (params = {}) => {
  const response = await api.get('/admin/announcements', { params })
  return response.data
}

export const createAnnouncement = async (data) => {
  const response = await api.post('/admin/announcements', data)
  return response.data
}

export const updateAnnouncement = async (id, data) => {
  const response = await api.put(`/admin/announcements/${id}`, data)
  return response.data
}

export const deleteAnnouncement = async (id) => {
  const response = await api.delete(`/admin/announcements/${id}`)
  return response.data
}