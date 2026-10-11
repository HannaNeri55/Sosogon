import api from './axios'

export const getRetailShops = async (params = {}) => {
  const response = await api.get('/retail-shops', { params })
  return response.data
}

export const getRetailShop = async (id) => {
  const response = await api.get(`/retail-shops/${id}`)
  return response.data
}
