
import api from './axios'

// ==================== DASHBOARD ====================

export const getAdminDashboard = async () => {
  const response = await api.get('/admin/dashboard')
  return response.data
}

// ==================== DESTINATIONS ====================

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
  const response = await api.delete(
    `/admin/destinations/${id}/images/${imageId}`
  )
  return response.data
}

export const setDestinationCoverImage = async (id, imageId) => {
  const response = await api.patch(
    `/admin/destinations/${id}/images/${imageId}/cover`
  )
  return response.data
}

// ==================== BUSINESS REGISTRATION SUBMISSIONS ====================

export const getAdminSubmissions = async (params = {}) => {
  const response = await api.get('/admin/submissions', { params })
  return response.data
}

export const approveSubmission = async (id, notes = '') => {
  const response = await api.put(
    `/admin/submissions/${id}/approve`,
    { notes }
  )
  return response.data
}

export const rejectSubmission = async (id, notes) => {
  const response = await api.put(
    `/admin/submissions/${id}/reject`,
    { notes }
  )
  return response.data
}

export const warnSubmission = async (id, notes = '') => {
  const response = await api.put(
    `/admin/submissions/${id}/warn`,
    { notes }
  )
  return response.data
}

export const clearSubmissionWarning = async (id) => {
  const response = await api.put(
    `/admin/submissions/${id}/clear-warning`
  )
  return response.data
}

export const removeSubmission = async (id, notes) => {
  const response = await api.put(
    `/admin/submissions/${id}/remove`,
    { notes }
  )
  return response.data
}

export const downloadSubmissionPermit = async (id) => {
  const response = await api.get(
    `/admin/submissions/${id}/permit`,
    { responseType: 'blob' }
  )
  return response.data
}

// ==================== OWNER REGISTRATIONS ====================

export const getOwnerApplications = async (params = {}) => {
  const response = await api.get('/admin/owner-applications', { params })
  return response.data
}

export const downloadOwnerApplicationPermit = async (id) => {
  const response = await api.get(`/admin/owner-applications/${id}/permit`, { responseType: 'blob' })
  return response.data
}

export const approveOwnerApplication = async (id) => {
  const response = await api.put(`/admin/owner-applications/${id}/approve`)
  return response.data
}

export const rejectOwnerApplication = async (id, reason) => {
  const response = await api.put(`/admin/owner-applications/${id}/reject`, { reason })
  return response.data
}

// ==================== PROPOSED CHANGES TO APPROVED ITEMS ====================
// kind: 'accommodation-rooms' | 'menu-items' | 'retail-products'

export const getItemsWithPendingChanges = async (kind) => {
  const response = await api.get(`/admin/${kind}`, { params: { changes: 'pending', per_page: 200 } })
  return response.data
}

// changesHash must be the pending_changes_hash the admin reviewed.
export const getItemReviews = async (params = {}) => {
  const response = await api.get('/admin/item-reviews', { params })
  return response.data
}

export const bulkReviewItems = async (payload) => {
  const response = await api.post('/admin/item-reviews/bulk', payload)
  return response.data
}

export const approveItemChanges = async (kind, id, changesHash, notes = '') => {
  const response = await api.put(`/admin/${kind}/${id}/approve-changes`, { changes_hash: changesHash, notes })
  return response.data
}

export const rejectItemChanges = async (kind, id, notes) => {
  const response = await api.put(`/admin/${kind}/${id}/reject-changes`, { notes })
  return response.data
}

// ==================== ACCOMMODATIONS ====================

export const getAdminAccommodations = async (params = {}) => {
  const response = await api.get('/admin/accommodations', { params })
  return response.data
}

export const getAdminAccommodation = async (id) => {
  const response = await api.get(`/admin/accommodations/${id}`)
  return response.data
}

export const updateAdminAccommodation = async (id, data) => {
  const response = await api.put(
    `/admin/accommodations/${id}`,
    data
  )
  return response.data
}

// ==================== ACCOMMODATION PUBLICATION REVIEW ====================

// Initial publication of accommodations that are not yet approved.

export const getAccommodationPublicationReviews = async (
  params = {}
) => {
  const response = await api.get('/admin/accommodations', {
    params: {
      ...params,
      status: 'pending',
    },
  })
  return response.data
}

export const approveAccommodationPublication = async (
  id,
  notes = ''
) => {
  const response = await api.put(
    `/admin/accommodations/${id}`,
    {
      status: 'approved',
      admin_notes: notes,
    }
  )
  return response.data
}

export const rejectAccommodationPublication = async (
  id,
  notes
) => {
  const response = await api.put(
    `/admin/accommodations/${id}`,
    {
      status: 'rejected',
      admin_notes: notes,
    }
  )
  return response.data
}

// ==================== ACCOMMODATION REVISION REVIEW ====================

// These functions are for updates to already-published accommodations.
// They do not use the initial publication approval workflow.

export const approveAccommodationRevision = async (
  id,
  notes = ''
) => {
  const response = await api.put(
    `/admin/accommodations/${id}`,
    {
      revision_action: 'approve',
      admin_notes: notes,
    }
  )
  return response.data
}

export const requestAccommodationRevisionChanges = async (
  id,
  notes
) => {
  const response = await api.put(
    `/admin/accommodations/${id}`,
    {
      revision_action: 'needs_revision',
      admin_notes: notes,
    }
  )
  return response.data
}

// ==================== ACCOMMODATION ROOMS ====================

export const getAdminAccommodationRooms = async (params = {}) => {
  const response = await api.get(
    '/admin/accommodation-rooms',
    { params }
  )
  return response.data
}

export const approveAccommodationRoom = async (
  id,
  notes = ''
) => {
  const response = await api.put(
    `/admin/accommodation-rooms/${id}/approve`,
    { notes }
  )
  return response.data
}

/** A direct correction of an accommodation option by the Tourism Office. */
export const updateAccommodationRoom = async (id, formData) => {
  formData.append('_method', 'PUT')
  const response = await api.post(`/admin/accommodation-rooms/${id}`, formData, { headers: { 'Content-Type': undefined } })
  return response.data
}

export const rejectAccommodationRoom = async (id, notes) => {
  const response = await api.put(
    `/admin/accommodation-rooms/${id}/reject`,
    { notes }
  )
  return response.data
}

// ==================== FOOD & BEVERAGE ====================

export const getAdminFoodBeverages = async (params = {}) => {
  const response = await api.get(
    '/admin/food-beverages',
    { params }
  )
  return response.data
}

export const getAdminFoodBeverage = async (id) => {
  const response = await api.get(
    `/admin/food-beverages/${id}`
  )
  return response.data
}

export const updateAdminFoodBeverage = async (id, data) => {
  const response = await api.put(
    `/admin/food-beverages/${id}`,
    data
  )
  return response.data
}

// ==================== FOOD & BEVERAGE PUBLICATION REVIEW ====================

export const getFoodBeveragePublicationReviews = async (
  params = {}
) => {
  const response = await api.get(
    '/admin/food-beverages',
    {
      params: {
        ...params,
        status: 'pending',
      },
    }
  )
  return response.data
}

export const approveFoodBeveragePublication = async (
  id,
  notes = ''
) => {
  const response = await api.put(
    `/admin/food-beverages/${id}`,
    {
      status: 'approved',
      admin_notes: notes,
    }
  )
  return response.data
}

export const rejectFoodBeveragePublication = async (
  id,
  notes
) => {
  const response = await api.put(
    `/admin/food-beverages/${id}`,
    {
      status: 'rejected',
      admin_notes: notes,
    }
  )
  return response.data
}

// ==================== MENU ITEMS ====================

export const getAdminMenuItems = async (params = {}) => {
  const response = await api.get('/admin/menu-items', { params })
  return response.data
}

export const approveMenuItem = async (id, notes = '') => {
  const response = await api.put(
    `/admin/menu-items/${id}/approve`,
    { notes }
  )
  return response.data
}

export const rejectMenuItem = async (id, notes) => {
  const response = await api.put(
    `/admin/menu-items/${id}/reject`,
    { notes }
  )
  return response.data
}

// ==================== USERS ====================

export const getAdminUsers = async (params = {}) => {
  const response = await api.get('/admin/users', { params })
  return response.data
}

export const updateAdminUser = async (id, data) => {
  const response = await api.put(`/admin/users/${id}`, data)
  return response.data
}

// ==================== ACCOUNT ENFORCEMENT ====================
// Warnings, suspensions, deactivation, reactivation and account closure. There is no
// instant delete: closing an account is a separate, confirmed step that keeps all data.

export const getEnforcementMeta = async () => {
  const response = await api.get('/admin/account-enforcement/meta')
  return response.data
}

export const getUserAccount = async (id) => {
  const response = await api.get(`/admin/users/${id}/account`)
  return response.data
}

// formData: action, category, reason, details, evidence_notes, evidence_files[], suspension_days, review_date, reported_at, confirmed
export const enforceUser = async (id, formData) => {
  const response = await api.post(`/admin/users/${id}/enforcement`, formData, {
    headers: { 'Content-Type': undefined },
  })
  return response.data
}

export const reactivateUser = async (id, reason) => {
  const response = await api.post(`/admin/users/${id}/reactivate`, { reason })
  return response.data
}

export const closeUserAccount = async (id, data) => {
  const response = await api.post(`/admin/users/${id}/close`, data)
  return response.data
}

export const getViolationRecords = async (params = {}) => {
  const response = await api.get('/admin/account-violations', { params })
  return response.data
}

export const downloadViolationEvidence = async (violationId, index) => {
  const response = await api.get(`/admin/account-violations/${violationId}/evidence/${index}`, { responseType: 'blob' })
  return response.data
}

export const getAccountAppeals = async (params = {}) => {
  const response = await api.get('/admin/account-appeals', { params })
  return response.data
}

export const decideAccountAppeal = async (id, data) => {
  const response = await api.post(`/admin/account-appeals/${id}/decision`, data)
  return response.data
}

export const downloadAppealAttachment = async (appealId, index) => {
  const response = await api.get(`/admin/account-appeals/${appealId}/attachments/${index}`, { responseType: 'blob' })
  return response.data
}

export const getAccountLog = async (params = {}) => {
  const response = await api.get('/admin/account-log', { params })
  return response.data
}

// ==================== REPORTS ====================

export const getReportVisitors = async (days = 30) => {
  const response = await api.get('/admin/reports/visitors', {
    params: { days },
  })
  return response.data
}

export const getReportPopular = async (limit = 10) => {
  const response = await api.get('/admin/reports/popular', {
    params: { limit },
  })
  return response.data
}

export const getReportSearches = async () => {
  const response = await api.get('/admin/reports/searches')
  return response.data
}

// ==================== LOCATIONS ====================

export const getMunicipalities = async () => {
  const response = await api.get('/municipalities')
  return response.data
}

export const getBarangays = async (municipalityId) => {
  const response = await api.get(
    `/municipalities/${municipalityId}/barangays`
  )
  return response.data
}

export const getCategories = async (type = '') => {
  const response = await api.get('/categories', {
    params: type ? { type } : {},
  })
  return response.data
}

export const exportReportPdf = async () => {
  const response = await api.get('/admin/reports/export', {
    responseType: 'blob',
  })
  return response.data
}

export const getReportOverview = async () => {
  const response = await api.get('/admin/reports/overview')
  return response.data
}

// ==================== RETAIL PRODUCTS ====================

export const getAdminRetailProducts = async (params = {}) => {
  const response = await api.get('/admin/retail-products', {
    params,
  })
  return response.data
}

export const createRetailProduct = async (formData) => {
  const response = await api.post(
    '/admin/retail-products',
    formData,
    {
      headers: { 'Content-Type': undefined },
    }
  )
  return response.data
}

export const updateRetailProduct = async (id, data) => {
  const response = await api.put(
    `/admin/retail-products/${id}`,
    data
  )
  return response.data
}

export const deleteRetailProduct = async (id) => {
  const response = await api.delete(
    `/admin/retail-products/${id}`
  )
  return response.data
}

export const approveRetailProduct = async (
  id,
  notes = ''
) => {
  const response = await api.put(
    `/admin/retail-products/${id}/approve`,
    { notes }
  )
  return response.data
}

export const rejectRetailProduct = async (id, notes) => {
  const response = await api.put(
    `/admin/retail-products/${id}/reject`,
    { notes }
  )
  return response.data
}

// ==================== RETAIL SHOPS ====================

export const getAdminRetailShops = async (params = {}) => {
  const response = await api.get('/admin/retail-shops', {
    params,
  })
  return response.data
}

export const getAdminRetailShop = async (id) => {
  const response = await api.get(
    `/admin/retail-shops/${id}`
  )
  return response.data
}

export const approveRetailShop = async (
  id,
  notes = ''
) => {
  const response = await api.put(
    `/admin/retail-shops/${id}/approve`,
    { notes }
  )
  return response.data
}

export const rejectRetailShop = async (id, notes) => {
  const response = await api.put(
    `/admin/retail-shops/${id}/reject`,
    { notes }
  )
  return response.data
}

// ==================== RETAIL SHOP PUBLICATION REVIEW ====================

export const getRetailShopPublicationReviews = async (
  params = {}
) => {
  const response = await api.get(
    '/admin/retail-shops',
    {
      params: {
        ...params,
        status: 'pending',
      },
    }
  )
  return response.data
}

export const approveRetailShopPublication = async (
  id,
  notes = ''
) => {
  return approveRetailShop(id, notes)
}

export const rejectRetailShopPublication = async (
  id,
  notes
) => {
  return rejectRetailShop(id, notes)
}

// ==================== EVENTS ====================

export const getAdminEvents = async (params = {}) => {
  const response = await api.get('/admin/events', {
    params,
  })
  return response.data
}

// Events are sent as FormData so an optional cover image can be included.
export const createEvent = async (formData) => {
  const response = await api.post('/admin/events', formData, {
    headers: { 'Content-Type': undefined },
  })
  return response.data
}

export const updateEvent = async (id, formData) => {
  formData.append('_method', 'PUT')

  const response = await api.post(`/admin/events/${id}`, formData, {
    headers: { 'Content-Type': undefined },
  })
  return response.data
}

export const deleteEvent = async (id) => {
  const response = await api.delete(`/admin/events/${id}`)
  return response.data
}

// ==================== HERO SLIDES ====================

export const getAdminHeroSlides = async (params = {}) => {
  const response = await api.get('/admin/hero-slides', {
    params,
  })
  return response.data
}

export const createHeroSlide = async (formData) => {
  const response = await api.post(
    '/admin/hero-slides',
    formData,
    {
      headers: { 'Content-Type': undefined },
    }
  )
  return response.data
}

export const updateHeroSlide = async (id, formData) => {
  formData.append('_method', 'PUT')

  const response = await api.post(
    `/admin/hero-slides/${id}`,
    formData,
    {
      headers: { 'Content-Type': undefined },
    }
  )
  return response.data
}

export const deleteHeroSlide = async (id) => {
  const response = await api.delete(
    `/admin/hero-slides/${id}`
  )
  return response.data
}

// ==================== ANNOUNCEMENTS ====================

export const getAdminAnnouncements = async (params = {}) => {
  const response = await api.get('/admin/announcements', {
    params,
  })
  return response.data
}

export const createAnnouncement = async (data) => {
  const response = await api.post(
    '/admin/announcements',
    data
  )
  return response.data
}

export const updateAnnouncement = async (id, data) => {
  const response = await api.put(
    `/admin/announcements/${id}`,
    data
  )
  return response.data
}

export const deleteAnnouncement = async (id) => {
  const response = await api.delete(
    `/admin/announcements/${id}`
  )
  return response.data
}


 
// ==================== FOOD & BEVERAGE REVISION REVIEW ====================
// Reviews changes to an already-published restaurant.
// The original listing stays published until approval.

export const approveFoodBeverageRevision = async (id, notes = '') => {
  const response = await api.put(`/admin/food-beverages/${id}`, {
    revision_action: 'approve',
    admin_notes: notes,
  })

  return response.data
}

export const requestFoodBeverageRevisionChanges = async (id, notes) => {
  const response = await api.put(`/admin/food-beverages/${id}`, {
    revision_action: 'needs_revision',
    admin_notes: notes,
  })

  return response.data
}


 // ==================== RETAIL SHOP REVISION REVIEW ====================
 // Reviews changes to an already-published retail shop.
 // The original listing stays published until approval.

 export const approveRetailShopRevision = async (id, notes = '') => {
   const response = await api.put(
     `/admin/retail-shops/${id}/approve-revision`,
     { notes }
   )

   return response.data
 }

 export const requestRetailShopRevisionChanges = async (id, notes) => {
   const response = await api.put(
     `/admin/retail-shops/${id}/request-revision-changes`,
     { notes }
   )

   return response.data
 }

// ==================== GUEST REVIEWS ====================

// params: type, rating, filter (replied/unreplied), search, from, to, page
export const getAdminGuestReviews = async (params = {}) => {
  const response = await api.get('/admin/guest-reviews', { params })
  return response.data
}

// Reviews reported by listing owners. params: status (pending, reviewed, action_taken, dismissed, all), page
export const getReviewReports = async (params = {}) => {
  const response = await api.get('/admin/review-reports', { params })
  return response.data
}

// payload: { status: reviewed | action_taken | dismissed, decision_note }
export const decideReviewReport = async (id, payload) => {
  const response = await api.put(`/admin/review-reports/${id}`, payload)
  return response.data
}

export const downloadReviewReportEvidence = async (id) => {
  const response = await api.get(`/admin/review-reports/${id}/evidence`, { responseType: 'blob' })
  return response.data
}
