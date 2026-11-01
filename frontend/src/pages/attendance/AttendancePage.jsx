import { useAuth } from '../../context/useAuth'
import GuruAttendancePage from './roles/GuruAttendancePage'
import TUAttendancePage from './roles/TUAttendancePage'

function roleCode(role) {
  const value = role?.code || role?.name || role || ''
  return String(value).toUpperCase().replaceAll(' ', '_')
}

export default function AttendancePage() {
  const auth = useAuth()
  const currentRole = roleCode(auth?.role)

  if (currentRole === 'TU') return <TUAttendancePage />
  if (currentRole === 'GURU') return <GuruAttendancePage />

  return <div className="attendance-page">Halaman presensi tidak tersedia untuk role ini.</div>
}
