
import { apiClient } from './apiClient'

function buildQuery(params = {}) {
  const searchParams = new URLSearchParams()

  Object.entries(params).forEach(([key, value]) => {
    if (
      value === undefined ||
      value === null ||
      value === ''
    ) {
      return
    }

    searchParams.set(key, String(value))
  })

  const queryString = searchParams.toString()

  return queryString ? `?${queryString}` : ''
}

export const userApi = {
  getActivationSummary() { return apiClient.get('/users/activation-summary') },
  previewBulk(payload) { return apiClient.post('/users/bulk/preview', payload) },
  issueBulk(payload) { return apiClient.postBlob('/users/bulk/activation-codes', payload) },
  deactivateBulk(payload) { return apiClient.post('/users/bulk/deactivate', payload) },
  getRoles() { return apiClient.get('/users/roles') },
  getAll(params = {}) {
    return apiClient.get(`/users${buildQuery(params)}`)
  },

  getById(id) {
    return apiClient.get(`/users/${id}`)
  },

  create(payload) {
    return apiClient.post('/users', payload)
  },

  update(id, payload) {
    return apiClient.put(`/users/${id}`, payload)
  },

  activate(id) {
    return apiClient.post(`/users/${id}/activate`)
  },

  deactivate(id) {
    return apiClient.post(`/users/${id}/deactivate`)
  },

  // Reset password oleh TU.
  // Payload: password dan password_confirmation.
  resetPassword(id, payload) {
    return apiClient.post(
      `/users/${id}/reset-password`,
      payload
    )
  },
}
