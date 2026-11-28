import { useAuth } from '../../context/useAuth'
import GuruAttendancePage from './roles/GuruAttendancePage'
import TUAttendancePage from './roles/TUAttendancePage'
import KepalaSekolahAttendancePage from './roles/KepalaSekolahAttendancePage'
import SiswaAttendancePage from './roles/SiswaAttendancePage'

function roleCode(role) {
  const value = role?.code || role?.name || role || ''
  return String(value).toUpperCase().replaceAll(' ', '_')
}

export default function AttendancePage() {
  const auth = useAuth()
  const currentRole = roleCode(auth?.role)

  if (currentRole === 'TU') {
    return <TUAttendancePage />
  }

  if (currentRole === 'KEPALA_SEKOLAH') {
    return <KepalaSekolahAttendancePage />
  }

  if (currentRole === 'GURU') {
    return <GuruAttendancePage />
  }

  if (currentRole === 'SISWA') {
    return <SiswaAttendancePage />
  }

  return (
    <div className="attendance-page">
      Halaman presensi tidak tersedia untuk role ini.
    </div>
  )
}