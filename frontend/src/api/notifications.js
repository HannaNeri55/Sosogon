// frontend/src/api/notifications.js
// The signed-in user's notification bell and this browser's push sign-up.
import api from './axios'

export const getNotifications = async () => {
  const response = await api.get('/notifications')
  return response.data
}

export const markNotificationRead = async (id) => {
  const response = await api.post(`/notifications/${id}/read`)
  return response.data
}

export const markAllNotificationsRead = async () => {
  const response = await api.post('/notifications/read-all')
  return response.data
}

export const getPushKey = async () => {
  const response = await api.get('/notifications/push-key')
  return response.data.public_key
}

// subscription: PushSubscription.toJSON() — { endpoint, keys: { p256dh, auth } }
export const savePushSubscription = async (subscription) => {
  const response = await api.post('/notifications/push-subscription', subscription)
  return response.data
}

export const removePushSubscription = async (endpoint) => {
  const response = await api.delete('/notifications/push-subscription', { data: { endpoint } })
  return response.data
}
