import { apiClient } from './apiClient'

export const authApi = {
  login(credentials) {
    return apiClient.post('/login', credentials)
  },

  me() {
    return apiClient.get('/me')
  },

  logout() {
    return apiClient.post('/logout')
  },
}