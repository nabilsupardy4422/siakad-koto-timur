
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

export const studentApi = {
  getAll(params = {}) {
    return apiClient.get(`/students${buildQuery(params)}`)
  },

  getById(id) {
    return apiClient.get(`/students/${id}`)
  },

  create(payload) {
    return apiClient.post('/students', payload)
  },

  update(id, payload) {
    return apiClient.put(`/students/${id}`, payload)
  },

  delete(id) {
    return apiClient.delete(`/students/${id}`)
  },

  getAvailableAccounts() {
    return apiClient.get('/student-accounts/available')
  },
}
