import api from './axios'

export const generateItinerary = async (preferences) => {
  const response = await api.post('/itineraries/generate', preferences)
  return response.data
}

export const getItineraries = async () => {
  const response = await api.get('/itineraries')
  return response.data
}

export const getItinerary = async (id) => {
  const response = await api.get(`/itineraries/${id}`)
  return response.data
}

export const deleteItinerary = async (id) => {
  const response = await api.delete(`/itineraries/${id}`)
  return response.data
}

export const updateItinerary = async (id, payload) => {
  const response = await api.put(`/itineraries/${id}`, payload)
  return response.data
}

export const saveItinerary = async (id) => {
  const response = await api.post(`/itineraries/${id}/save`)
  return response.data
}

// The printable document as one self-contained HTML page: { html, dates }.
export const getItineraryDocument = async (id) => {
  const response = await api.get(`/itineraries/${id}/document`)
  return response.data
}

export const downloadItineraryPdf = async (id) => {
  const response = await api.get(`/itineraries/${id}/pdf`, { responseType: 'blob' })
  return response.data
}

export const getShareLink = async (id) => {
  const response = await api.get(`/itineraries/${id}/share`)
  return response.data
}

export const createShareLink = async (id) => {
  const response = await api.post(`/itineraries/${id}/share`)
  return response.data
}

export const revokeShareLink = async (id) => {
  const response = await api.delete(`/itineraries/${id}/share`)
  return response.data
}

export const getSharedItinerary = async (token) => {
  const response = await api.get(`/shared-itineraries/${token}`)
  return response.data
}

export const downloadSharedItineraryPdf = async (token) => {
  const response = await api.get(`/shared-itineraries/${token}/pdf`, { responseType: 'blob' })
  return response.data
}

export const getEligibleListingsForItinerary = async (id) => {
  const response = await api.get(`/itineraries/${id}/eligible-listings`)
  return response.data
}

export const getGenerationQuota = async () => {
  const response = await api.get('/itineraries/generation-quota')
  return response.data
}

// Guests: generate returns only a preview plus a claim token; the full itinerary is
// attached to their account by claimItinerary() once they log in as a tourist.
export const generateGuestItinerary = async (preferences) => {
  const response = await api.post('/itineraries/guest-generate', preferences)
  return response.data
}

export const getGuestGenerationQuota = async () => {
  const response = await api.get('/itineraries/guest-quota')
  return response.data
}

export const claimItinerary = async (id, claimToken) => {
  const response = await api.post(`/itineraries/${id}/claim`, { claim_token: claimToken })
  return response.data
}
