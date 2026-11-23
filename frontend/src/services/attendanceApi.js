
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

export const attendanceApi = {
  // Presensi Siswa V2: server-scoped, read-only.
  getStudentWorkspace(params = {}) {
    return apiClient.get(`/attendance/student-workspace${buildQuery(params)}`)
  },

  // Mengambil daftar presensi dengan filter dan pagination.
  getAll(params = {}) {
    return apiClient.get(`/attendance${buildQuery(params)}`)
  },

  // Daftar anggota kelas pada tanggal presensi,
  // khusus jadwal mengajar sendiri.
  getRoster(params = {}) {
    return apiClient.get(`/attendance/roster${buildQuery(params)}`)
  },

  // Mengambil rekap presensi.
  getRecap(params = {}) {
    return apiClient.get(`/attendance/recap${buildQuery(params)}`)
  },

  // Mengambil rekap presensi kelas untuk TU.
  getClassRecap(params = {}) {
    return apiClient.get(
      `/attendance/class-recap${buildQuery(params)}`,
    )
  },

  // Menambahkan satu data presensi.
  create(payload) {
    return apiClient.post('/attendance', payload)
  },

  // Menambahkan atau memperbarui presensi banyak siswa sekaligus.
  bulkSave(payload) {
    return apiClient.post('/attendance/bulk', payload)
  },

  // Memperbarui satu data presensi.
  update(id, payload) {
    return apiClient.put(`/attendance/${id}`, payload)
  },
}
