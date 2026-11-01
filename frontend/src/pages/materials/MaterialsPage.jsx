import { useAuth } from '../../context/useAuth'
import GuruMaterialsPage from './roles/GuruMaterialsPage'
import SiswaMaterialsPage from './roles/SiswaMaterialsPage'

function getRoleCode(role) {
  const value =
    typeof role === 'string'
      ? role
      : role?.code || role?.name || role?.nama || ''

  const normalized = String(value).trim().toUpperCase()

  if (normalized.includes('GURU')) return 'GURU'
  if (normalized.includes('SISWA')) return 'SISWA'

  return normalized
}

export default function MaterialsPage() {
  const { role, status } = useAuth()
  const code = getRoleCode(role)

  if (status === 'loading') {
    return (
      <main className="student-materials-page">
        <section className="student-materials-panel">
          <p>Memeriksa akses pengguna...</p>
        </section>
      </main>
    )
  }

  if (code === 'GURU') return <GuruMaterialsPage />
  if (code === 'SISWA') return <SiswaMaterialsPage />

  return (
    <main className="student-materials-page">
      <section className="student-materials-panel">
        <h1>Akses Tidak Tersedia</h1>
        <p>Modul Bahan Ajar hanya tersedia untuk Guru dan Siswa.</p>
      </section>
    </main>
  )
}
