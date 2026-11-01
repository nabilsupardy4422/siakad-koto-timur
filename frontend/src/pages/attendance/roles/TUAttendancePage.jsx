
import { useCallback, useEffect, useMemo, useState } from 'react'
import PageHeader from '../../../components/ui/PageHeader'
import Card from '../../../components/ui/Card'
import Table from '../../../components/ui/Table'
import Select from '../../../components/ui/Select'
import Input from '../../../components/ui/Input'
import Button from '../../../components/ui/Button'
import LoadingState from '../../../components/ui/LoadingState'
import ErrorState from '../../../components/ui/ErrorState'
import EmptyState from '../../../components/ui/EmptyState'
import { attendanceApi } from '../../../services/attendanceApi'
import { academicYearApi } from '../../../services/academicYearApi'
import { classApi } from '../../../services/classApi'
import './TUAttendancePage.css'



function today() {
  const date = new Date()
  const localDate = new Date(
    date.getTime() - date.getTimezoneOffset() * 60_000,
  )

  return localDate.toISOString().slice(0, 10)
}

function getCollection(response) {
  if (Array.isArray(response?.data)) {
    return response.data
  }

  if (Array.isArray(response?.data?.data)) {
    return response.data.data
  }

  return []
}

function getMeta(response) {
  return (
    response?.meta ||
    response?.data?.meta ||
    null
  )
}

function getErrorMessage(error) {
  return (
    error?.payload?.message ||
    error?.message ||
    'Terjadi kesalahan saat memproses presensi.'
  )
}

function getYearLabel(year) {
  return `${year.tahun_mulai}/${year.tahun_selesai} • ${year.semester}`
}

function AttendanceClassRecapPage() {
  const [academicYears, setAcademicYears] = useState([])
  const [classes, setClasses] = useState([])
  const [selectedYearId, setSelectedYearId] = useState('')
  const [selectedDate, setSelectedDate] = useState(today())
  const [selectedClassId, setSelectedClassId] = useState('')
  const [recapRows, setRecapRows] = useState([])
  const [recapMeta, setRecapMeta] = useState(null)
  const [loadingOptions, setLoadingOptions] = useState(true)
  const [loadingRecap, setLoadingRecap] = useState(false)
  const [optionsError, setOptionsError] = useState('')
  const [recapError, setRecapError] = useState('')

  const selectedYear = useMemo(
    () => academicYears.find((year) => String(year.id) === String(selectedYearId)) || null,
    [academicYears, selectedYearId],
  )

  const loadAcademicYears = useCallback(async () => {
    setLoadingOptions(true)
    setOptionsError('')

    try {
      const response = await academicYearApi.getAll()
      const years = getCollection(response)
      setAcademicYears(years)
      setSelectedYearId((current) => {
        if (current && years.some((year) => String(year.id) === String(current))) {
          return current
        }
        const activeYear = years.find((year) => year.is_active)
        return String((activeYear || years[0])?.id || '')
      })
    } catch (err) {
      setAcademicYears([])
      setOptionsError(getErrorMessage(err))
    } finally {
      setLoadingOptions(false)
    }
  }, [])

  useEffect(() => {
    loadAcademicYears()
  }, [loadAcademicYears])

  useEffect(() => {
    let cancelled = false

    async function loadClasses() {
      if (!selectedYearId) {
        setClasses([])
        setSelectedClassId('')
        return
      }

      try {
        const response = await classApi.getAll({
          academic_year_id: selectedYearId,
          per_page: 100,
        })
        if (cancelled) return
        const classRows = getCollection(response)
        setClasses(classRows)
        setSelectedClassId((current) =>
          current && classRows.some((item) => String(item.id) === String(current))
            ? current
            : '',
        )
      } catch (err) {
        if (cancelled) return
        setClasses([])
        setSelectedClassId('')
        setOptionsError(getErrorMessage(err))
      }
    }

    loadClasses()
    return () => { cancelled = true }
  }, [selectedYearId])

  const loadClassRecap = useCallback(async () => {
    if (!selectedYearId || !selectedDate || !selectedYear) {
      setRecapRows([])
      setRecapMeta(null)
      return
    }

    setLoadingRecap(true)
    setRecapError('')

    try {
      const response = await attendanceApi.getClassRecap({
        academic_year_id: selectedYearId,
        date: selectedDate,
        semester: selectedYear.semester,
        class_id: selectedClassId || undefined,
      })
      const data = getCollection(response)
      setRecapRows(data)
      setRecapMeta(getMeta(response))
    } catch (err) {
      setRecapRows([])
      setRecapMeta(null)
      setRecapError(getErrorMessage(err))
    } finally {
      setLoadingRecap(false)
    }
  }, [selectedYearId, selectedDate, selectedYear, selectedClassId])

  useEffect(() => {
    loadClassRecap()
  }, [loadClassRecap])

  const totals = useMemo(() => recapRows.reduce((sum, row) => ({
    total_siswa: sum.total_siswa + Number(row.total_siswa || 0),
    hadir: sum.hadir + Number(row.hadir || 0),
    izin: sum.izin + Number(row.izin || 0),
    sakit: sum.sakit + Number(row.sakit || 0),
    alpa: sum.alpa + Number(row.alpa || 0),
    belum_ada_data: sum.belum_ada_data + Number(row.belum_ada_data || 0),
  }), {
    total_siswa: 0, hadir: 0, izin: 0, sakit: 0, alpa: 0, belum_ada_data: 0,
  }), [recapRows])

  const columns = [
    { key: 'nama_kelas', label: 'Kelas' },
    { key: 'tingkat', label: 'Tingkat' },
    { key: 'total_siswa', label: 'Total Siswa' },
    { key: 'hadir', label: 'Hadir' },
    { key: 'izin', label: 'Izin' },
    { key: 'sakit', label: 'Sakit' },
    { key: 'alpa', label: 'Alpa' },
    { key: 'belum_ada_data', label: 'Belum Ada Data' },
  ]

  return (
    <div className="attendance-page attendance-tu-page">
      <PageHeader
        title="Rekap Presensi Kelas"
        description="Pantau rekap kehadiran siswa per kelas berdasarkan tanggal dan tahun akademik."
      />

      {optionsError && <ErrorState message={optionsError} />}
      {recapError && <ErrorState message={recapError} />}

      <Card>
        <div className="attendance-toolbar attendance-tu-toolbar">
          <div>
            <h2>Filter Rekap Harian</h2>
            <p>Rekap menghitung setiap siswa satu kali per tanggal. Siswa tanpa catatan ditampilkan sebagai belum ada data.</p>
          </div>
          <Button
            variant="secondary"
            disabled={loadingRecap || loadingOptions || !selectedYearId || !selectedDate}
            onClick={loadClassRecap}
          >
            Muat Ulang
          </Button>
        </div>

        <div className="attendance-filters attendance-tu-filters">
          <Select
            id="attendance-recap-year"
            label="Tahun Akademik"
            value={selectedYearId}
            options={academicYears.map((year) => ({ value: String(year.id), label: getYearLabel(year) }))}
            placeholder="Pilih tahun akademik"
            disabled={loadingOptions || academicYears.length === 0}
            required
            onChange={(event) => setSelectedYearId(event.target.value)}
          />
          <Input
            id="attendance-recap-date"
            label="Tanggal Presensi"
            type="date"
            value={selectedDate}
            required
            onChange={(event) => setSelectedDate(event.target.value)}
          />
          <Select
            id="attendance-recap-semester"
            label="Semester"
            value={selectedYear?.semester || ''}
            options={selectedYear?.semester ? [{ value: selectedYear.semester, label: selectedYear.semester }] : []}
            placeholder="Pilih tahun akademik terlebih dahulu"
            disabled
          />
          <Select
            id="attendance-recap-class"
            label="Kelas"
            value={selectedClassId}
            options={classes.map((item) => ({ value: String(item.id), label: `${item.nama} • Tingkat ${item.tingkat}` }))}
            placeholder="Semua kelas"
            disabled={!selectedYearId || loadingOptions || classes.length === 0}
            onChange={(event) => setSelectedClassId(event.target.value)}
          />
        </div>
      </Card>

      <section className="attendance-recap attendance-recap--class attendance-tu-summary" aria-label="Ringkasan presensi kelas">
        {[
          { label: 'Total Siswa', value: totals.total_siswa },
          { label: 'Hadir', value: totals.hadir },
          { label: 'Izin', value: totals.izin },
          { label: 'Sakit', value: totals.sakit },
          { label: 'Alpa', value: totals.alpa },
          { label: 'Belum Ada Data', value: totals.belum_ada_data },
        ].map((item) => (
          <Card key={item.label}>
            <div className="attendance-tu-summary-card">
              <span className="attendance-tu-summary-label">{item.label}</span>
              <strong className="attendance-tu-summary-value">{item.value}</strong>
            </div>
          </Card>
        ))}
      </section>

      <Card>
        <div className="attendance-table-heading attendance-tu-table-heading">
          <div>
            <h2>Rekap Presensi per Kelas</h2>
            <p>
              {selectedDate ? `Tanggal ${selectedDate}` : 'Pilih tanggal'}
              {selectedYear ? ` • Tahun ${selectedYear.tahun_mulai}/${selectedYear.tahun_selesai} • ${selectedYear.semester}` : ''}
            </p>
          </div>
          {recapMeta && <span className="attendance-tu-count">{recapMeta.total_classes ?? recapRows.length} kelas</span>}
        </div>

        {loadingRecap || loadingOptions ? (
          <LoadingState />
        ) : recapRows.length === 0 ? (
          <EmptyState
            title="Belum ada data rekap"
            description="Periksa tahun akademik, tanggal, dan filter kelas yang dipilih. Siswa tanpa catatan presensi tetap dihitung sebagai belum ada data jika kelas tersebut memiliki anggota aktif."
          />
        ) : (
          <Table
            columns={columns}
            data={recapRows}
            renderCell={(row, column) => {
              if (column.key === 'tingkat') return row.tingkat ?? '-'
              return row[column.key] ?? 0
            }}
            emptyMessage="Tidak ada data rekap."
          />
        )}
      </Card>
    </div>
  )
}


export default AttendanceClassRecapPage
