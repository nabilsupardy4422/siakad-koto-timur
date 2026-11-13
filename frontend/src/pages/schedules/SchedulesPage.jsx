
import { useAuth } from '../../context/useAuth'

import LoadingState from '../../components/ui/LoadingState'
import ErrorState from '../../components/ui/ErrorState'

import AdminSchedule from './roles/AdminSchedule'
import PrincipalSchedule from './roles/PrincipalSchedule'
import TeacherSchedule from './roles/TeacherSchedule'
import StudentSchedule from './roles/StudentSchedule'

export default function SchedulesPage() {
  const {
    status,
    user,
    permissions = [],
    assignments = [],
  } = useAuth()

  if (status === 'loading') {
    return (
      <LoadingState
        message="Memeriksa hak akses jadwal..."
 
      />
    )
  }

  if (status !== 'authenticated' || !user) {
    return (
      <ErrorState
        title="Sesi tidak tersedia"
        message="Silakan login kembali untuk mengakses halaman jadwal."
      />
    )
  }

  const roleCode = user.role?.code

  switch (roleCode) {
    case 'TU':
      return (
        <AdminSchedule
          permissions={permissions}
          assignments={assignments}
        />
      )

    case 'KEPALA_SEKOLAH':
      return (
        <PrincipalSchedule
          permissions={permissions}
          assignments={assignments}
        />
      )

    case 'GURU':
      return (
        <TeacherSchedule
          permissions={permissions}
          assignments={assignments}
        />
      )

    case 'SISWA':
      return (
        <StudentSchedule
          permissions={permissions}
          assignments={assignments}
        />
      )

    default:
      return (
        <ErrorState
          title="Akses tidak tersedia"
          message={`Role akun tidak dikenali: ${roleCode || 'tidak tersedia'}`}
        />
      )
  }
}
