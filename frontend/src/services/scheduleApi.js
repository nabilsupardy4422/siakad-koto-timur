
import { apiClient } from './apiClient'

function buildQuery(params = {}) {
  const searchParams = new URLSearchParams()

  Object.entries(params).forEach(([key, value]) => {
    if (value === undefined || value === null || value === '') return

    searchParams.set(key, String(value))
  })

  const queryString = searchParams.toString()

  return queryString ? `?${queryString}` : ''
}

export const scheduleApi = {
  getAll(params = {}) {
    return apiClient.get(`/schedules${buildQuery(params)}`)
  },

  getById(id) {
    return apiClient.get(`/schedules/${id}`)
  },

  create(payload) {
    return apiClient.post('/schedules', payload)
  },

  update(id, payload) {
    return apiClient.put(`/schedules/${id}`, payload)
  },

  delete(id) {
    return apiClient.delete(`/schedules/${id}`)
  },
}
