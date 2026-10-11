import api from './axios'

export const getRetailProducts = async (params = {}) => {
  const response = await api.get('/retail-products', { params })
  return response.data
}

export const getRetailProduct = async (id) => {
  const response = await api.get(`/retail-products/${id}`)
  return response.data
}