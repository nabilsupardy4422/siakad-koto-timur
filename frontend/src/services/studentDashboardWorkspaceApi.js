import { apiClient } from './apiClient'

export const studentDashboardWorkspaceApi = {
  get() {
    return apiClient.get('/dashboard/student-workspace')
  },
}
