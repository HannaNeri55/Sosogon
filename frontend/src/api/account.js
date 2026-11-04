// frontend/src/api/account.js
// The signed-in user's own Account Health (status, enforcement history, appeals).
import api from './axios'

export const getAccountStatus = async () => {
  const response = await api.get('/account/status')
  return response.data
}

export const markAccountNotificationsRead = async () => {
  const response = await api.post('/account/notifications/read')
  return response.data
}

// formData: explanation, attachments[]
export const submitAccountAppeal = async (violationId, formData) => {
  const response = await api.post(`/account/violations/${violationId}/appeal`, formData, {
    headers: { 'Content-Type': undefined },
  })
  return response.data
}

export const downloadMyAppealAttachment = async (appealId, index) => {
  const response = await api.get(`/account/appeals/${appealId}/attachments/${index}`, { responseType: 'blob' })
  return response.data
}

/** Suspended or deactivated: the app only shows the Account Health page. */
export const isRestrictedAccount = (user) => ['suspended', 'deactivated'].includes(user?.account_state)
