import { apiClient } from './apiClient'

function buildQuery(params = {}) {
  const query = new URLSearchParams()

  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') {
      query.append(key, value)
    }
  })

  const queryString = query.toString()
  return queryString ? `?${queryString}` : ''
}

export const homeroomApi = {
  getAll(params = {}) {
    return apiClient.get(
      `/homeroom-assignments${buildQuery(params)}`
    )
  },

  getById(id) {
    return apiClient.get(`/homeroom-assignments/${id}`)
  },

  create(payload) {
    return apiClient.post('/homeroom-assignments', payload)
  },

  update(id, payload) {
    return apiClient.put(`/homeroom-assignments/${id}`, payload)
  },

  end(id) {
    return apiClient.delete(`/homeroom-assignments/${id}`)
  },
}
