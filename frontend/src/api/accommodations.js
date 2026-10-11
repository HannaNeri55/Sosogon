import api from './axios'

export const getAccommodations = async (params = {}) => {
  const response = await api.get('/accommodations', { params })
  return response.data
}

export const getAccommodation = async (id) => {
  const response = await api.get(`/accommodations/${id}`)
  return response.data
}