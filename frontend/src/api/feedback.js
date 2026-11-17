import api from './axios'

// Feedback & Concerns form: categories, modules and the listing types a message can be about.
export const getFeedbackOptions = async () => {
  const response = await api.get('/feedback/options')
  return response.data
}

// payload: { name, email, wants_reply, category, subject, message, module, listing_type, listing_id }
export const submitFeedback = async (payload) => {
  const response = await api.post('/feedback', payload)
  return response.data
}
