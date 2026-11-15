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

// Download PDF (backend ItineraryPdfDownloads): the server makes the PDF once and returns
// { ticket, filename, expires_at }; the browser itself then opens the file's link, so its own
// download manager saves it to the device's Downloads.
export const prepareItineraryPdf = async (id) => {
  const response = await api.post(`/itineraries/${id}/pdf-download`)
  return response.data
}

// { started }: whether the browser has fetched the file yet, the only sign a page gets that a
// download began. Polled, so it fails quietly.
export const getItineraryPdfStatus = async (id, ticket) => {
  const response = await api.get(`/itineraries/${id}/pdf-download/${ticket}`, { quiet: true })
  return response.data
}

// The prepared file's full address: `download` saves it to the device, `view` shows it (Open PDF).
// Absolute, so it can also be handed to another browser app.
export const preparedPdfUrl = (ticket, mode) =>
  new URL(`${api.defaults.baseURL}/itinerary-files/${ticket}/${mode}`, window.location.href).href

// The prepared file's bytes for the copy kept in My Downloads, marked so the server doesn't count
// this read as the download to the device.
export const fetchPreparedPdf = async (ticket) => {
  const response = await fetch(preparedPdfUrl(ticket, 'download'), { headers: { 'X-Sosogon-Copy': '1' } })
  if (!response.ok) throw new Error(`The PDF could not be read (${response.status}).`)
  return response.blob()
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
