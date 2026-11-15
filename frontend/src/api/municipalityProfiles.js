import api from './axios'

// The About page's municipality cards: every town with its photo.
export const getMunicipalityProfiles = async () => {
  const response = await api.get('/municipality-profiles')
  return response.data
}

// One town's contact details, geography and history.
export const getMunicipalityProfile = async (id) => {
  const response = await api.get(`/municipality-profiles/${id}`)
  return response.data
}
