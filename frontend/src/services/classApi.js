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

export const classApi = {
  getAll(params = {}) {
    return apiClient.get(`/classes${buildQuery(params)}`)
  },

  getById(id, params = {}) {
    return apiClient.get(`/classes/${id}`)
  },

  create(payload) {
    return apiClient.post('/classes', payload)
  },

  update(id, payload) {
    return apiClient.put(`/classes/${id}`, payload)
  },

  delete(id) {
    return apiClient.delete(`/classes/${id}`)
  },
}
