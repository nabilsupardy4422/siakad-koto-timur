import { apiClient } from './apiClient'

export const classMemberApi = {
  candidates(classId, { tanggal_mulai, search = '', page = 1, per_page = 15 }) {
    const query = new URLSearchParams({ tanggal_mulai, search, page: String(page), per_page: String(per_page) })
    return apiClient.get(`/classes/${classId}/students/candidates?${query}`)
  },
  directory(period) { return apiClient.get(`/membership-directory?academic_year_id=${encodeURIComponent(period)}`) },
  history(studentId) { return apiClient.get(`/membership-history/${studentId}`) },
  previewTransition(payload) { return apiClient.post('/academic-transitions/preview', payload) },
  commitTransition(payload) { return apiClient.post('/academic-transitions', payload) },
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

  endMembership(classId, studentId, membershipId) {
    return apiClient.delete(`/classes/${classId}/students/${studentId}`, { membership_id: membershipId })
  },

  downloadTemplate(classId) {
    return apiClient.download(
      `/classes/${classId}/students/template`,
    )
  },

  previewBulk(classId, payload) {
    return apiClient.post(`/classes/${classId}/students/bulk/preview`, payload)
  },

  commitBulk(classId, payload) {
    return apiClient.post(`/classes/${classId}/students/bulk`, payload)
  },

  previewImport(classId, file, startDate) {
    const formData = new FormData()
    formData.append('file', file)
    formData.append('tanggal_mulai', startDate)

    return apiClient.postForm(
      `/classes/${classId}/students/import/preview`,
      formData,
    )
  },

  importExcel(classId, file, startDate, token) {
    const formData = new FormData()
    formData.append('file', file)
    formData.append('tanggal_mulai', startDate)

    formData.append('preview_token', token)
    return apiClient.postForm(
      `/classes/${classId}/students/import`,
      formData,
    )
  },
}