
import api from './axios'

// ==================== FOOD & BEVERAGE DETAILS ====================

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

// ==================== FOOD & BEVERAGE PHOTO GALLERY ====================

export const deleteFoodBeverageImage = async (imageId) => {
  const response = await api.delete(
    `/business/food-beverage/images/${imageId}`
  )

  return response.data
}

export const setFoodBeverageCover = async (imageId) => {
  const response = await api.patch(
    `/business/food-beverage/images/${imageId}/cover`
  )

  return response.data
}

export const reorderFoodBeverageImages = async (imageIds) => {
  const response = await api.put(
    '/business/food-beverage/images/reorder',
    {
      image_ids: imageIds,
    }
  )

  return response.data
}

// ==================== PUBLICATION REVIEW ====================

export const submitFoodBeverageForReview = async () => {
  const response = await api.post(
    '/business/food-beverage/submit-for-review'
  )

  return response.data
}

// ==================== MENU MANAGEMENT ====================

export const getMyMenuItems = async () => {
  const response = await api.get('/business/food-beverage/menu-items')
  return response.data
}

export const createMenuItem = async (formData) => {
  const response = await api.post(
    '/business/food-beverage/menu-items',
    formData,
    {
      headers: { 'Content-Type': undefined },
    }
  )

  return response.data
}

export const updateMenuItem = async (id, formData) => {
  formData.append('_method', 'PUT')

  const response = await api.post(
    `/business/food-beverage/menu-items/${id}`,
    formData,
    {
      headers: { 'Content-Type': undefined },
    }
  )

  return response.data
}

export const deleteMenuItem = async (id) => {
  const response = await api.delete(
    `/business/food-beverage/menu-items/${id}`
  )

  return response.data
}
