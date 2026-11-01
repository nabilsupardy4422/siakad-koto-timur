
import { useAuth } from '../../context/useAuth'

import LoadingState from '../../components/ui/LoadingState'
import ErrorState from '../../components/ui/ErrorState'

import GuruGrades from './roles/GuruGrades'
import TuGrades from './roles/TuGrades'
import KepalaSekolahGrades from './roles/KepalaSekolahGrades'
import SiswaGrades from './roles/SiswaGrades'

import './styles/GradesPage.css'

export default function GradesPage() {
  const { status, user } = useAuth()

  // Memeriksa status autentikasi.
  if (status === 'loading') {
    return (
      <LoadingState message="Memeriksa hak akses penilaian..." />
    )
  }

  // Memastikan pengguna sudah login.
  if (status !== 'authenticated' || !user) {
    return (
      <ErrorState
        title="Sesi tidak tersedia"
        message="Silakan login kembali untuk mengakses halaman penilaian."
      />
    )
  }

  // Mengambil kode role pengguna.
  const roleCode = user.role?.code

  // Memastikan informasi role tersedia.
  if (!roleCode) {
    return (
      <ErrorState
        title="Role tidak ditemukan"
        message="Informasi hak akses akun tidak tersedia. Silakan login kembali."
      />
    )
  }

  // Menampilkan halaman sesuai role pengguna.
  const renderRolePage = () => {
    switch (roleCode) {
      case 'GURU':
        return <GuruGrades />

      case 'TU':
        return <TuGrades />

      case 'KEPALA_SEKOLAH':
        return <KepalaSekolahGrades />

      case 'SISWA':
        return <SiswaGrades />

      default:
        return (
          <ErrorState
            title="Akses tidak tersedia"
            message="Role akun ini tidak memiliki akses ke halaman penilaian."
          />
        )
    }
  }

  return (
    <main className="grades-page">
      {renderRolePage()}
    </main>
  )
}
