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

export const academicYearApi = {
  getAll(params = {}) {
    return apiClient.get(
      `/academic-years${buildQuery(params)}`,
    )
  },

  getById(id) {
    return apiClient.get(
      `/academic-years/${id}`,
    )
  },

  create(payload) {
    return apiClient.post(
      '/academic-years',
      payload,
    )
  },

  update(id, payload) {
    return apiClient.put(
      `/academic-years/${id}`,
      payload,
    )
  },

  delete(id) {
    return apiClient.delete(
      `/academic-years/${id}`,
    )
  },

    // Download template Excel
  downloadTemplate() {
    return apiClient.getBlob(
      '/academic-years/template',
    )
  },

  // Preview file Excel
  previewImport(formData) {
    return apiClient.postForm(
      '/academic-years/import/preview',
      formData,
    )
  },

  // Konfirmasi import Excel
  importExcel(formData) {
    return apiClient.postForm(
      '/academic-years/import',
      formData,
    )
  },
}