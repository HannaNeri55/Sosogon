import api from './axios'

export const getFoodBeverages = async (params = {}) => {
  const response = await api.get('/food-beverages', { params })
  return response.data
}

export const getFoodBeverage = async (id) => {
  const response = await api.get(`/food-beverages/${id}`)
  return response.data
}