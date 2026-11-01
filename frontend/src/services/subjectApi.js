
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

export const subjectApi = {
  getAll(params = {}) {
    return apiClient.get(`/subjects${buildQuery(params)}`)
  },

  getById(id) {
    return apiClient.get(`/subjects/${id}`)
  },

  create(payload) {
    return apiClient.post('/subjects', payload)
  },

  update(id, payload) {
    return apiClient.put(`/subjects/${id}`, payload)
  },

  delete(id) {
    return apiClient.delete(`/subjects/${id}`)
  },
}
