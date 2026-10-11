import api from './axios'

export const getMyRetailShop = async () => {
  const response = await api.get('/business/retail-shop')
  return response.data
}

export const updateMyRetailShop = async (formData) => {
  formData.append('_method', 'PUT')
  const response = await api.post('/business/retail-shop', formData, {
    headers: { 'Content-Type': undefined },
  })
  return response.data
}

export const updateMyRetailShopLocation = async (data) => {
  const response = await api.put('/business/retail-shop/location', data)
  return response.data
}

export const deleteRetailShopImage = async (imageId) => {
  const response = await api.delete(`/business/retail-shop/images/${imageId}`)
  return response.data
}

export const getMyRetailProducts = async () => {
  const response = await api.get('/business/retail-shop/products')
  return response.data
}

export const createRetailShopProduct = async (formData) => {
  const response = await api.post('/business/retail-shop/products', formData, {
    headers: { 'Content-Type': undefined },
  })
  return response.data
}

export const updateRetailShopProduct = async (id, formData) => {
  formData.append('_method', 'PUT')
  const response = await api.post(`/business/retail-shop/products/${id}`, formData, {
    headers: { 'Content-Type': undefined },
  })
  return response.data
}

export const deleteRetailShopProduct = async (id) => {
  const response = await api.delete(`/business/retail-shop/products/${id}`)
  return response.data
}
