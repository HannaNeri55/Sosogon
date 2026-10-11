import api from './axios'

export const getMyFoodBeverage = async () => {
  const response = await api.get('/business/food-beverage')
  return response.data
}

export const updateMyFoodBeverage = async (formData) => {
  formData.append('_method', 'PUT')
  const response = await api.post('/business/food-beverage', formData, {
    headers: { 'Content-Type': undefined },
  })
  return response.data
}

export const updateMyFoodBeverageLocation = async (data) => {
  const response = await api.put('/business/food-beverage/location', data)
  return response.data
}

export const deleteFoodBeverageImage = async (imageId) => {
  const response = await api.delete(`/business/food-beverage/images/${imageId}`)
  return response.data
}

export const getMyMenuItems = async () => {
  const response = await api.get('/business/food-beverage/menu-items')
  return response.data
}

export const createMenuItem = async (formData) => {
  const response = await api.post('/business/food-beverage/menu-items', formData, {
    headers: { 'Content-Type': undefined },
  })
  return response.data
}

export const updateMenuItem = async (id, formData) => {
  formData.append('_method', 'PUT')
  const response = await api.post(`/business/food-beverage/menu-items/${id}`, formData, {
    headers: { 'Content-Type': undefined },
  })
  return response.data
}

export const deleteMenuItem = async (id) => {
  const response = await api.delete(`/business/food-beverage/menu-items/${id}`)
  return response.data
}
