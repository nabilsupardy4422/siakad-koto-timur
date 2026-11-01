import { apiClient } from './apiClient'

export const dashboardApi = {
  getDashboard() {
    return apiClient.get('/dashboard')
  },
}