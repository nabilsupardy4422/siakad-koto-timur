
import { apiClient } from './apiClient'

export const classMemberApi = {
  getAll(classId, params = {}) {
    const query = new URLSearchParams()

    Object.entries(params).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== '') {
        query.set(key, String(value))
      }
    })

    const suffix = query.toString() ? `?${query.toString()}` : ''

    return apiClient.get(`/classes/${classId}/students${suffix}`)
  },

  create(classId, payload) {
    return apiClient.post(`/classes/${classId}/students`, payload)
  },

  endMembership(classId, studentId) {
    return apiClient.delete(
      `/classes/${classId}/students/${studentId}`,
    )
  },
}
