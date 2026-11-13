
import { useAuth } from '../../context/useAuth'

import LoadingState from '../../components/ui/LoadingState'
import ErrorState from '../../components/ui/ErrorState'

import TuReportCards from './roles/TuReportCards'
import SiswaReportCards from './roles/SiswaReportCards'

import './styles/ReportCardsPage.css'

export default function ReportCardsPage() {
  const { status, user } = useAuth()

  // Memeriksa status autentikasi.
  if (status === 'loading') {
    return (
      <LoadingState message="Memeriksa hak akses rapor..." />
    )
  }

  // Memastikan pengguna sudah login.
  if (status !== 'authenticated' || !user) {
    return (
      <ErrorState
        title="Sesi tidak tersedia"
        message="Silakan login kembali untuk mengakses halaman rapor."
      />
    )
  }

  // Mengambil kode role pengguna.
  const roleCode = (
    user.role?.code ||
    user.role?.name ||
    user.role?.nama
  )?.toUpperCase()

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
      case 'TU':
        return <TuReportCards />

      case 'SISWA':
        return <SiswaReportCards />

      default:
        return (
          <ErrorState
            title="Akses tidak tersedia"
            message="Role akun ini tidak memiliki akses ke halaman rapor."
          />
        )
    }
  }

  return (
    <main className="report-cards-page">
      {renderRolePage()}
    </main>
  )
}
