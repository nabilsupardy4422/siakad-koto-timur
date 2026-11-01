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

export const gradeApi = {
  getComponents(params = {}) {
    return apiClient.get(`/grade-components${buildQuery(params)}`)
  },
  getComponent(id) {
    return apiClient.get(`/grade-components/${id}`)
  },
  createComponent(payload) {
    return apiClient.post('/grade-components', payload)
  },
  updateComponent(id, payload) {
    return apiClient.put(`/grade-components/${id}`, payload)
  },
  deleteComponent(id) {
    return apiClient.delete(`/grade-components/${id}`)
  },
  getGrades(params = {}) {
    return apiClient.get(`/grades${buildQuery(params)}`)
  },
  createGrade(payload) {
    return apiClient.post('/grades', payload)
  },
  updateGrade(id, payload) {
    return apiClient.put(`/grades/${id}`, payload)
  },
  getRecap(params = {}) {
    return apiClient.get(`/grades/recap${buildQuery(params)}`)
  },
}
