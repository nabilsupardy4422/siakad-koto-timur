import { apiClient } from './apiClient'

export const dashboardApi = {
  getPrincipalDashboard() {
    return apiClient.get('/principal/dashboard-workspace')
  },
  getDashboard() {
    return apiClient.get('/dashboard')
  },
}