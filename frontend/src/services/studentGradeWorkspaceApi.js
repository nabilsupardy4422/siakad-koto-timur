import { apiClient } from './apiClient'

export const studentGradeWorkspaceApi = {
  get(academicYearId) {
    const query = academicYearId ? `?academic_year_id=${encodeURIComponent(academicYearId)}` : ''
    return apiClient.get(`/grades/student-workspace${query}`)
  },
}
