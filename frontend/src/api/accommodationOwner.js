
import api from './axios'

// ==================== ACCOMMODATION DETAILS ====================

export const getMyAccommodation = async () => {
  const response = await api.get('/business/accommodation')
  return response.data
}

export const updateMyAccommodation = async (formData) => {
  formData.append('_method', 'PUT')

  const response = await api.post('/business/accommodation', formData, {
    headers: { 'Content-Type': undefined },
  })

  return response.data
}

export const updateMyAccommodationLocation = async (data) => {
  const response = await api.put('/business/accommodation/location', data)
  return response.data
}

// ==================== ACCOMMODATION PHOTO GALLERY ====================

export const deleteAccommodationImage = async (imageId) => {
  const response = await api.delete(
    `/business/accommodation/images/${imageId}`
  )

  return response.data
}

export const setAccommodationCover = async (imageId) => {
  const response = await api.patch(
    `/business/accommodation/images/${imageId}/cover`
  )

  return response.data
}

export const reorderAccommodationImages = async (imageIds) => {
  const response = await api.put(
    '/business/accommodation/images/reorder',
    {
      image_ids: imageIds,
    }
  )

  return response.data
}

// ==================== PUBLICATION REVIEW ====================

export const submitAccommodationForReview = async () => {
  const response = await api.post(
    '/business/accommodation/submit-for-review'
  )

  return response.data
}

// ==================== ROOM MANAGEMENT ====================

export const getMyRooms = async () => {
  const response = await api.get('/business/accommodation/rooms')
  return response.data
}

export const createRoom = async (formData) => {
  const response = await api.post(
    '/business/accommodation/rooms',
    formData,
    {
      headers: { 'Content-Type': undefined },
    }
  )

  return response.data
}

export const updateRoom = async (id, formData) => {
  formData.append('_method', 'PUT')

  const response = await api.post(
    `/business/accommodation/rooms/${id}`,
    formData,
    {
      headers: { 'Content-Type': undefined },
    }
  )

  return response.data
}

export const deleteRoom = async (id) => {
  const response = await api.delete(
    `/business/accommodation/rooms/${id}`
  )

  return response.data
}

export const deleteRoomImage = async (roomId, imageId) => {
  const response = await api.delete(
    `/business/accommodation/rooms/${roomId}/images/${imageId}`
  )

  return response.data
}

export const toggleRoomOccupancy = async (
  id,
  occupancyStatus
) => {
  const response = await api.patch(
    `/business/accommodation/rooms/${id}/occupancy`,
    {
      occupancy_status: occupancyStatus,
    }
  )

  return response.data
}
