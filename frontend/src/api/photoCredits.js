import api from './axios'

export const getPhotoCredits = async () => {
  const response = await api.get('/photo-credits')
  return response.data
}
