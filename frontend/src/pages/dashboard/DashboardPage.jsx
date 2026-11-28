import { useAuth } from '../../context/useAuth'
import ErrorState from '../../components/ui/ErrorState'
import TUDashboard from './roles/TUDashboard'
import GuruDashboard from './roles/GuruDashboard'
import KepalaSekolahDashboard from './roles/KepalaSekolahDashboard'
import SiswaDashboard from './roles/SiswaDashboard'

export default function DashboardPage() {
  const { user, role, permissions = [] } = useAuth()
  const roleCode = role?.code || user?.role?.code || null
  if (roleCode === 'GURU') return <GuruDashboard />
  if (roleCode === 'KEPALA_SEKOLAH') return <KepalaSekolahDashboard />
  if (roleCode === 'SISWA') return <SiswaDashboard />
  if (roleCode === 'TU' && user?.id) return <TUDashboard key={user.id} user={user} permissions={permissions}/>
  return <ErrorState message="Role pengguna tidak dikenali untuk dashboard." />
}
