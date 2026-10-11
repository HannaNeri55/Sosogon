import api from './axios'

export const submitRating = async (data) => {
  const response = await api.post('/ratings', data)
  return response.data
}

export const updateRating = async (id, data) => {
  const response = await api.put(`/ratings/${id}`, data)
  return response.data
}

export const deleteRating = async (id) => {
  const response = await api.delete(`/ratings/${id}`)
  return response.data
}

export const getMyReviews = async () => {
  const response = await api.get('/my-reviews')
  return response.data
}