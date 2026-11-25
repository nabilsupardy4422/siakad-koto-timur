import { apiClient } from './apiClient'

/** Separate read-only access. Never request another user's student identity. */
export const principalAttendanceApi = {
  async getMonthlyRecap({ academicYearId, from, to }) {
    if (!Number.isSafeInteger(academicYearId) || academicYearId <= 0) {
      throw new TypeError('Tahun akademik tidak valid.')
    }
    const datePattern = /^\d{4}-\d{2}-\d{2}$/
    if (!datePattern.test(from) || !datePattern.test(to) || from > to) {
      throw new TypeError('Rentang tanggal rekap tidak valid.')
    }
    const query = new URLSearchParams({
      academic_year_id: String(academicYearId),
      date_from: from,
      date_to: to,
    })
    return apiClient.get(`/attendance/recap?${query.toString()}`)
  },
}
