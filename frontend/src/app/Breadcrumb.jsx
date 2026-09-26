import { Link, useLocation } from 'react-router-dom'

const routeLabels = {
  '/app': 'Dashboard',
  '/app/dashboard': 'Dashboard',
  '/app/academic-years': 'Tahun Akademik',
  '/app/teachers': 'Guru',
  '/app/students': 'Siswa',
  '/app/subjects': 'Mata Pelajaran',
  '/app/classes': 'Kelas',
  '/app/schedules': 'Jadwal',
  '/app/attendance': 'Presensi',
  '/app/grades': 'Penilaian',
  '/app/materials': 'Bahan Ajar',
  '/app/assignments': 'Tugas',
  '/app/report-cards': 'Rapor',
  '/app/reports': 'Laporan',
  '/app/users': 'Pengguna',
  '/app/homeroom': 'Kelas Saya',
}

export default function Breadcrumb() {
  const location = useLocation()
  const currentLabel =
    routeLabels[location.pathname] || 'Halaman'

  return (
    <nav className="app-breadcrumb" aria-label="Breadcrumb">
      <Link to="/app/dashboard">
        Dashboard
      </Link>

      {location.pathname !== '/app/dashboard' && (
        <>
          <span aria-hidden="true">/</span>
          <span aria-current="page">{currentLabel}</span>
        </>
      )}
    </nav>
  )
}