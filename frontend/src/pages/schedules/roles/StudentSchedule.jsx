
import { useCallback, useEffect, useState } from 'react'

import LoadingState from '../../../components/ui/LoadingState'
import ErrorState from '../../../components/ui/ErrorState'

import { scheduleApi } from '../../../services/scheduleApi'
import { academicYearApi } from '../../../services/academicYearApi'

import InteractiveTimetable from '../components/InteractiveTimetable'
import '../styles/student-schedule.css'

function getApiMessage(error) {
  return (
    error?.payload?.message ||
    error?.response?.data?.message ||
    error?.message ||
    'Terjadi kesalahan saat memuat data jadwal.'
  )
}

export default function StudentSchedule() {
  const [academicYear, setAcademicYear] = useState(null)
  const [schedules, setSchedules] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const loadData = useCallback(async () => {
    setLoading(true)
    setError(null)
    setAcademicYear(null)
    setSchedules([])

    try {
      const yearResponse = await academicYearApi.getAll({
        is_active: 1,
        per_page: 100,
      })

      const years = Array.isArray(yearResponse?.data)
        ? yearResponse.data
        : []

      const activeYear = years.find(
        (year) => year.is_active === true || year.is_active === 1
      )

      if (!activeYear) {
        throw new Error('Tahun akademik aktif belum tersedia.')
      }

      setAcademicYear(activeYear)

      const scheduleResponse = await scheduleApi.getAll({
        academic_year_id: activeYear.id,
        per_page: 100,
        page: 1,
      })

      setSchedules(
        Array.isArray(scheduleResponse?.data)
          ? scheduleResponse.data
          : []
      )
    } catch (requestError) {
      setError(requestError)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    loadData()
  }, [loadData])

  if (loading) {
    return <LoadingState message="Memuat timetable Siswa..." />
  }

  if (error) {
    return (
      <div className="student-schedule">
        <ErrorState
          title="Jadwal pelajaran gagal dimuat"
          message={getApiMessage(error)}
          onRetry={loadData}
        />
      </div>
    )
  }

  if (!academicYear) {
    return (
      <div className="student-schedule">
        <ErrorState
          title="Tahun akademik tidak tersedia"
          message="Belum ada tahun akademik aktif yang ditetapkan."
          onRetry={loadData}
        />
      </div>
    )
  }

  return (
    <div className="student-schedule">
      <InteractiveTimetable
        role="SISWA"
        schedules={schedules}
        academicYear={academicYear}
      />
    </div>
  )
}
