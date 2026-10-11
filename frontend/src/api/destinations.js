import api from './axios'

export const getDestinations = async (params = {}) => {
  const response = await api.get('/destinations', { params })
  return response.data
}

export const getDestination = async (id) => {
  const response = await api.get(`/destinations/${id}`)
  return response.data
}

export const searchAll = async (query, type = 'all') => {
  const response = await api.get('/search', { params: { q: query, type } })
  return response.data
}