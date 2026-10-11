import api from './axios'

export const getEvents = async (params = {}) => {
  const response = await api.get('/events', { params })
  return response.data
}

export const getEvent = async (id) => {
  const response = await api.get(`/events/${id}`)
  return response.data
}

export const getEventsCalendar = async ({ month, year }) => {
  const response = await api.get('/events/calendar', { params: { month, year } })
  return response.data
}

export const getAnnouncements = async (params = {}) => {
  const response = await api.get('/announcements', { params })
  return response.data
}