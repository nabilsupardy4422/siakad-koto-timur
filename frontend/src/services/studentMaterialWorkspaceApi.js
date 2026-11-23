import { apiClient } from './apiClient'

export const studentMaterialWorkspaceApi = {
  get(params = {}) {
    const search = new URLSearchParams()
    for (const [key, value] of Object.entries(params)) {
      if (value !== undefined && value !== null && value !== '') search.set(key, String(value))
    }
    return apiClient.get(`/materials/student-workspace${search.size ? `?${search}` : ''}`)
  },
}
