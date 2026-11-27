
import { useEffect, useState } from 'react'
import { useAuth } from '../../../context/useAuth'
import { collectPages, loadOwnTeachingSchedules, teacherId } from '../../../utils/teachingScope'

import LoadingState from '../../../components/ui/LoadingState'
import ErrorState from '../../../components/ui/ErrorState'

import { scheduleApi } from '../../../services/scheduleApi'
import { academicYearApi } from '../../../services/academicYearApi'

import InteractiveTimetable from '../components/InteractiveTimetable'
import {PrincipalScheduleList} from './PrincipalScheduleViews'
import '../styles/principal-schedule-scale.css'
import '../styles/teacher-schedule.css'

function getApiMessage(error) {
  return (
    error?.payload?.message ||
    error?.response?.data?.message ||
    error?.message ||
    'Terjadi kesalahan saat memuat data jadwal.'
  )
}

export default function TeacherSchedule() {
  const { user } = useAuth()
  const guruId = teacherId(user)

  // A different account must never retain schedule data from the previous session.
  return <TeachingScheduleWorkspace key={`${user?.id ?? ''}:${guruId ?? ''}`} guruId={guruId} />
}

function TeachingScheduleWorkspace({ guruId }) {
  const [academicYear, setAcademicYear] = useState(null)
  const [schedules, setSchedules] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [revision, setRevision] = useState(0)
  const [mode,setMode]=useState('calendar')
  const loadData = () => setRevision(value => value + 1)

  useEffect(() => {
    let alive = true
    setLoading(true)
    setError(null)
    setAcademicYear(null)
    setSchedules([])

    async function load() {
      try {
        if (guruId == null) throw new Error('Identitas Guru belum tersedia. Masuk kembali atau hubungi Tata Usaha.')
        const years = await collectPages(page => academicYearApi.getAll({
          is_active: 1, per_page: 100, page,
        }))
        const active = years.filter(year => [true, 1, '1'].includes(year.is_active))
        if (active.length !== 1) {
          throw new Error(active.length ? 'Lebih dari satu tahun akademik aktif. Hubungi Tata Usaha.' : 'Tahun akademik aktif belum tersedia.')
        }
        const year = active[0]
        const own = await loadOwnTeachingSchedules(scheduleApi, guruId, year.id)
        if (!alive) return
        setAcademicYear(year)
        setSchedules(own.map(row=>({...row,hari:String(row.hari||'').toUpperCase()})))
      } catch (requestError) {
        if (alive) setError(requestError)
      } finally {
        if (alive) setLoading(false)
      }
    }

    load()
    return () => { alive = false }
  }, [guruId, revision])

  if (loading) {
    return <LoadingState message="Memuat jadwal mengajar..." />
  }

  if (error) {
    return (
      <div className="teacher-schedule">
        <ErrorState
          title="Jadwal mengajar gagal dimuat"
          message={getApiMessage(error)}
          onRetry={loadData}
        />
      </div>
    )
  }

  if (!academicYear) {
    return (
      <div className="teacher-schedule">
        <ErrorState
          title="Tahun akademik tidak tersedia"
          message="Belum ada tahun akademik aktif yang ditetapkan."
          onRetry={loadData}
        />
      </div>
    )
  }

  return (
    <div className="teacher-schedule">
      <div className="teacher-schedule-modes"><strong>Jadwal mengajar</strong><div role="group" aria-label="Mode jadwal Guru"><button type="button" aria-pressed={mode==='calendar'} onClick={()=>setMode('calendar')}>Kalender</button><button type="button" aria-pressed={mode==='list'} onClick={()=>setMode('list')}>Daftar</button></div></div>
      {mode==='calendar'?<InteractiveTimetable role="GURU" schedules={schedules} academicYear={academicYear}/>:<div className="principal-schedule teacher-schedule-list"><PrincipalScheduleList schedules={schedules}/></div>}
    </div>
  )
}
