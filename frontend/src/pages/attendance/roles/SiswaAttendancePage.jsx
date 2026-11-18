import { useCallback, useEffect, useState } from 'react'

import PageHeader from '../../../components/ui/PageHeader'
import Card from '../../../components/ui/Card'
import Table from '../../../components/ui/Table'
import Input from '../../../components/ui/Input'
import Button from '../../../components/ui/Button'
import LoadingState from '../../../components/ui/LoadingState'
import ErrorState from '../../../components/ui/ErrorState'
import EmptyState from '../../../components/ui/EmptyState'

import { attendanceApi } from '../../../services/attendanceApi'

import './SiswaAttendancePage.css'

function getCollection(response) {
  if (Array.isArray(response?.data)) {
    return response.data
  }

  if (Array.isArray(response?.data?.data)) {
    return response.data.data
  }

  return []
}

function getErrorMessage(error) {
  return (
    error?.payload?.message ||
    error?.message ||
    'Terjadi kesalahan saat mengambil riwayat presensi.'
  )
}

function formatDate(date) {
  if (!date) return '-'

  return new Date(`${date}T00:00:00`).toLocaleDateString('id-ID', {
    day: '2-digit',
    month: 'long',
    year: 'numeric',
  })
}

function formatTime(time) {
  return time ? String(time).slice(0, 5) : '-'
}

function getStatusLabel(status) {
  const labels = {
    HADIR: 'Hadir',
    IZIN: 'Izin',
    SAKIT: 'Sakit',
    ALPA: 'Alpa',
  }

  return labels[status] || status || '-'
}

export default function SiswaAttendancePage() {
  const [attendanceRows, setAttendanceRows] = useState([])
  const [summary, setSummary] = useState({
    total: 0,
    hadir: 0,
    izin: 0,
    sakit: 0,
    alpa: 0,
  })

  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')

  const [page, setPage] = useState(1)
  const [meta, setMeta] = useState(null)

  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const loadAttendance = useCallback(async () => {
    setLoading(true)
    setError('')

    try {
      const params = {
        page,
        per_page: 20,
        date_from: dateFrom || undefined,
        date_to: dateTo || undefined,
      }

      const [attendanceResponse, recapResponse] = await Promise.all([
        attendanceApi.getAll(params),
        attendanceApi.getRecap({
          date_from: dateFrom || undefined,
          date_to: dateTo || undefined,
        }),
      ])

      setAttendanceRows(getCollection(attendanceResponse))
      setMeta(attendanceResponse?.meta || null)

      const recap = recapResponse?.data || {}

      setSummary({
        total: Number(recap.total || 0),
        hadir: Number(recap.hadir || 0),
        izin: Number(recap.izin || 0),
        sakit: Number(recap.sakit || 0),
        alpa: Number(recap.alpa || 0),
      })
    } catch (err) {
      setAttendanceRows([])
      setMeta(null)
      setError(getErrorMessage(err))
    } finally {
      setLoading(false)
    }
  }, [page, dateFrom, dateTo])

  useEffect(() => {
    loadAttendance()
  }, [loadAttendance])

  function handleFilter() {
    setPage(1)
  }

  function handleReset() {
    setDateFrom('')
    setDateTo('')
    setPage(1)
  }

  const columns = [
    { key: 'tanggal', label: 'Tanggal' },
    { key: 'mapel', label: 'Mata Pelajaran' },
    { key: 'guru', label: 'Guru Pengampu' },
    { key: 'jam', label: 'Jam Pelajaran' },
    { key: 'status', label: 'Status' },
    { key: 'catatan', label: 'Catatan' },
  ]

  return (
    <div className="attendance-page attendance-student-page">
      <PageHeader
        title="Riwayat Presensi Saya"
        description="Lihat riwayat dan rekap kehadiran Anda selama kegiatan pembelajaran."
      />

      {error && (
        <ErrorState message={error} />
      )}

      <Card>
        <div className="student-attendance-filter-header">
          <div>
            <h2>Filter Riwayat</h2>
            <p>Pilih rentang tanggal untuk melihat riwayat presensi.</p>
          </div>

          <div className="student-attendance-filter-actions">
            <Button
              variant="secondary"
              onClick={handleReset}
              disabled={loading}
            >
              Reset
            </Button>

            <Button
              onClick={handleFilter}
              disabled={loading}
            >
              Terapkan Filter
            </Button>
          </div>
        </div>

        <div className="student-attendance-filters">
          <Input
            id="attendance-date-from"
            label="Dari Tanggal"
            type="date"
            value={dateFrom}
            onChange={(event) => setDateFrom(event.target.value)}
          />

          <Input
            id="attendance-date-to"
            label="Sampai Tanggal"
            type="date"
            value={dateTo}
            onChange={(event) => setDateTo(event.target.value)}
          />
        </div>
      </Card>

      <section className="student-attendance-summary">
        {[
          { label: 'Total Presensi', value: summary.total },
          { label: 'Hadir', value: summary.hadir },
          { label: 'Izin', value: summary.izin },
          { label: 'Sakit', value: summary.sakit },
          { label: 'Alpa', value: summary.alpa },
        ].map((item) => (
          <Card key={item.label}>
            <div className="student-attendance-summary-card">
              <span>{item.label}</span>
              <strong>{item.value}</strong>
            </div>
          </Card>
        ))}
      </section>

      <Card>
        <div className="student-attendance-table-heading">
          <div>
            <h2>Riwayat Kehadiran</h2>
            <p>Daftar presensi berdasarkan jadwal pembelajaran.</p>
          </div>

          <Button
            variant="secondary"
            onClick={loadAttendance}
            disabled={loading}
          >
            Muat Ulang
          </Button>
        </div>

        {loading ? (
          <LoadingState message="Memuat riwayat presensi..." />
        ) : attendanceRows.length === 0 ? (
          <EmptyState
            title="Belum ada riwayat presensi"
            description="Belum ditemukan data presensi pada rentang tanggal yang dipilih."
          />
        ) : (
          <>
            <Table
              columns={columns}
              data={attendanceRows}
              renderCell={(row, column) => {
                const schedule = row.jadwal_pelajaran

                switch (column.key) {
                  case 'tanggal':
                    return formatDate(row.tanggal)

                  case 'mapel':
                    return schedule?.mapel?.nama || '-'

                  case 'guru':
                    return schedule?.guru?.nama_lengkap || '-'

                  case 'jam':
                    return `${formatTime(schedule?.jam_mulai)} - ${formatTime(schedule?.jam_selesai)}`

                  case 'status':
                    return (
                      <span
                        className={`student-attendance-status status-${String(row.status || '').toLowerCase()}`}
                      >
                        {getStatusLabel(row.status)}
                      </span>
                    )

                  case 'catatan':
                    return row.catatan || '-'

                  default:
                    return row[column.key] ?? '-'
                }
              }}
              emptyMessage="Belum ada data presensi."
            />

            {meta && meta.last_page > 1 && (
              <div className="student-attendance-pagination">
                <span>
                  Halaman {meta.current_page} dari {meta.last_page}
                  {' '}• Total {meta.total} data
                </span>

                <div>
                  <Button
                    variant="secondary"
                    disabled={page <= 1 || loading}
                    onClick={() => setPage((current) => current - 1)}
                  >
                    Sebelumnya
                  </Button>

                  <Button
                    variant="secondary"
                    disabled={page >= meta.last_page || loading}
                    onClick={() => setPage((current) => current + 1)}
                  >
                    Berikutnya
                  </Button>
                </div>
              </div>
            )}
          </>
        )}
      </Card>
    </div>
  )
}