import { apiClient } from './apiClient'

/** Read-only summary of recorded component scores for one academic period. */
export const principalGradeApi = {
  getRecap({ academicYearId }) {
    if (!Number.isSafeInteger(academicYearId) || academicYearId <= 0) {
      throw new TypeError('Tahun akademik tidak valid.')
    }

    const query = new URLSearchParams({
      academic_year_id: String(academicYearId),
    })

    return apiClient.get(`/principal/grade-summary?${query.toString()}`)
  },
}
