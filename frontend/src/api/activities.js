import api from './axios'

export const getActivity = async (id) => {
  const response = await api.get(`/activities/${id}`)
  return response.data
}
