
import { useAuth } from '../../context/useAuth'


import LoadingState from '../../components/ui/LoadingState'
import ErrorState from '../../components/ui/ErrorState'


import './styles/ReportsPage.css'
import TuReports from './roles/TuReports'
import GuruReports from './roles/GuruReports'
import KepalaSekolahReports from './roles/KepalaSekolahReports'
import SiswaReports from './roles/SiswaReports'

export default function ReportsPage() {
  const { status, user } = useAuth()

  if (status === 'loading') {
    return (
      <LoadingState message="Memeriksa hak akses laporan..." />
    )
  }

  if (status !== 'authenticated' || !user) {
    return (
      <ErrorState
        title="Sesi tidak tersedia"
        message="Silakan login kembali untuk mengakses laporan."
      />
    )
  }

  const roleCode = user.role?.code

  const allowedRoles = [
    'TU',
    'KEPALA_SEKOLAH',
    'GURU',
    'SISWA',
  ]

  if (!allowedRoles.includes(roleCode)) {
    return (
      <ErrorState
        title="Akses tidak tersedia"
        message="Role akun ini tidak memiliki akses laporan."
      />
    )
  }

  if (roleCode === 'TU') return <TuReports key={user.id} />
  if (roleCode === 'GURU') return <GuruReports key={user.id} />
  if (roleCode === 'KEPALA_SEKOLAH') return <KepalaSekolahReports key={user.id} />

  if (roleCode === 'SISWA') return <SiswaReports key={user.id} />

  return null
}
