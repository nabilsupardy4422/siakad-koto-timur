
import { useState } from 'react'
import { useAuth } from '../../context/useAuth'

import { reportApi } from '../../services/reportApi'

import LoadingState from '../../components/ui/LoadingState'
import ErrorState from '../../components/ui/ErrorState'

import ReportTypeSelect from './components/ReportTypeSelect'
import ReportFilters from './components/ReportFilters'

import './styles/ReportsPage.css'

export default function ReportsPage() {
  const { status, user } = useAuth()

  const [reportType, setReportType] = useState('students')
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')

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

  async function handleGenerate(params) {
    setError('')
    setSuccess('')

    try {
      setLoading(true)

      const blob = await reportApi.downloadReport(
        reportType,
        params
      )

      const extension =
        params.format === 'excel' ? 'xlsx' : 'pdf'

      const filename =
        `laporan-${reportType}.${extension}`

      const url = URL.createObjectURL(blob)

      const link = document.createElement('a')

      link.href = url
      link.download = filename

      document.body.appendChild(link)
      link.click()
      link.remove()

      URL.revokeObjectURL(url)

      setSuccess('Laporan berhasil dibuat.')
    } catch (err) {
      setError(
        err.message || 'Gagal membuat laporan.'
      )
    } finally {
      setLoading(false)
    }
  }

  return (
    <main className="reports-page">
      <header className="reports-header">
        <div>
          <h1>Laporan Akademik</h1>

          <p>
            Buat dan unduh laporan akademik.
          </p>
        </div>
      </header>

      {error && (
        <div className="report-message report-message-error">
          {error}
        </div>
      )}

      {success && (
        <div className="report-message report-message-success">
          {success}
        </div>
      )}

      <ReportTypeSelect
        value={reportType}
        onChange={setReportType}
      />

      <ReportFilters
        reportType={reportType}
        onGenerate={handleGenerate}
        loading={loading}
      />
    </main>
  )
}
