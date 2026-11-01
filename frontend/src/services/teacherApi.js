
import { apiClient } from './apiClient'

function buildQuery(params = {}) {
  const searchParams = new URLSearchParams()

  Object.entries(params).forEach(([key, value]) => {
    if (value === undefined || value === null || value === '') {
      return
    }

    searchParams.set(key, String(value))
  })

  const queryString = searchParams.toString()

  return queryString ? `?${queryString}` : ''
}

export const teacherApi = {
  getAll(params = {}) {
    return apiClient.get(`/teachers${buildQuery(params)}`)
  },

  getById(id) {
    return apiClient.get(`/teachers/${id}`)
  },

  create(payload) {
    return apiClient.post('/teachers', payload)
  },

  update(id, payload) {
    return apiClient.put(`/teachers/${id}`, payload)
  },

  delete(id) {
    return apiClient.delete(`/teachers/${id}`)
  },

  getAvailableAccounts() {
    return apiClient.get('/teacher-accounts/available')
  },
}
