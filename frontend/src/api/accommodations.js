import api from './axios'

export const getAccommodations = async (params = {}) => {
  const response = await api.get('/accommodations', { params })
  return response.data
}

/** Amenity names offered on the owner forms; scope: 'property' or 'unit'. */
export const getAmenitySuggestions = async (scope) => {
  const response = await api.get('/amenity-suggestions', { params: { scope } })
  return response.data
}

export const getAccommodation = async (id) => {
  const response = await api.get(`/accommodations/${id}`)
  return response.data
}