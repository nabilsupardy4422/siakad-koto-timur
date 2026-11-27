import { apiClient } from './apiClient'
import { loadClassCatalog } from '../pages/classes/classDirectoryLogic.js'

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

  getCatalog(params = {}) {
    return loadClassCatalog(page => this.getAll({ ...params, per_page: 100, page }))
  },

  getById(id, params = {}) {
    return apiClient.get(`/classes/${id}${buildQuery(params)}`)
  },

  getDirectoryDetail(id, academicYearId) {
    return apiClient.get(`/classes/${id}${buildQuery({directory: 1, academic_year_id: academicYearId})}`)
  },

  create(payload) {
    return apiClient.post('/classes', payload)
  },

  update(id, payload) {
    return apiClient.put(`/classes/${id}`, payload)
  },

  delete(id, version) {
    return apiClient.delete(`/classes/${id}`, version ? {version} : undefined)
  },
}
