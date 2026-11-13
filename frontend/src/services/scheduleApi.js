
import { apiClient } from './apiClient'

/**
 * Membuat query string dari parameter filter.
 */
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

  return queryString
    ? `?${queryString}`
    : ''
}

/**
 * API Jadwal Pelajaran.
 *
 * CRUD tetap digunakan untuk pengelolaan manual.
 * Import dan export digunakan untuk pengelolaan Excel.
 */
export const scheduleApi = {
  /**
   * Mengambil seluruh jadwal
   * beserta filter yang dikirim.
   */
  getAll(params = {}) {
    return apiClient.get(
      `/schedules${buildQuery(params)}`
    )
  },

  /**
   * Mengambil detail jadwal.
   */
  getById(id) {
    return apiClient.get(
      `/schedules/${id}`
    )
  },

  /**
   * Menambahkan jadwal.
   */
  create(payload) {
    return apiClient.post(
      '/schedules',
      payload
    )
  },

  /**
   * Mengubah jadwal.
   */
  update(id, payload) {
    return apiClient.put(
      `/schedules/${id}`,
      payload
    )
  },

  /**
   * Menghapus jadwal.
   */
  delete(id) {
    return apiClient.delete(
      `/schedules/${id}`
    )
  },

  /**
   * Download template Excel.
   *
   * Endpoint backend:
   * GET /schedules/template
   */
  downloadTemplate() {
    return apiClient.getBlob(
      '/schedules/template'
    )
  },

  /**
   * Import jadwal dari Excel.
   *
   * Endpoint backend:
   * POST /schedules/import
   *
   * Parameter:
   * file = File Excel dari input file.
   */
  importExcel(file) {
    if (!(file instanceof File)) {
      throw new TypeError(
        'File Excel harus berupa objek File.'
      )
    }

    const formData = new FormData()

    formData.append('file', file)

    return apiClient.postForm(
      '/schedules/import',
      formData
    )
  },

  /**
   * Export jadwal ke Excel.
   *
   * Endpoint backend:
   * GET /schedules/export
   *
   * params mengikuti filter jadwal
   * yang digunakan oleh halaman AdminSchedule.
   */
  exportExcel(params = {}) {
    return apiClient.getBlob(
      `/schedules/export${buildQuery(params)}`
    )
  },
}
