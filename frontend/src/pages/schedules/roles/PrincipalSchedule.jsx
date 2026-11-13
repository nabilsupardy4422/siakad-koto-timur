import { useEffect, useMemo, useState } from 'react'

import { academicYearApi } from '../../../services/academicYearApi'
import { scheduleApi } from '../../../services/scheduleApi'
import ErrorState from '../../../components/ui/ErrorState'
import InteractiveTimetable from '../components/InteractiveTimetable'

import '../styles/principal-schedule.css'
import '../styles/principal-timetable.css'

const PAGE_SIZE = 100

function getResponseData(response) {
  return Array.isArray(response?.data) ? response.data : []
}

function uniqueOptions(items, getValue, getLabel) {
  const values = new Map()

  items.forEach((item) => {
    const value = getValue(item)

    if (value === null || value === undefined || value === '') return

    values.set(String(value), {
      value: String(value),
      label: getLabel(item),
    })
  })

  return [...values.values()].sort((a, b) =>
    a.label.localeCompare(b.label, 'id')
  )
}

export default function PrincipalSchedule() {
  const [academicYear, setAcademicYear] = useState(null)
  const [schedules, setSchedules] = useState([])
  const [filters, setFilters] = useState({
    class_id: '',
    teacher_id: '',
    subject_id: '',
    day: '',
  })
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    let cancelled = false

    async function loadMonitoringData() {
      setLoading(true)
      setError('')

      try {
        // API filter is_active=1 is the source of truth for the active year.
        const academicResponse = await academicYearApi.getAll({
          is_active: 1,
          per_page: 100,
        })
        const academicYears = getResponseData(academicResponse)
        const activeYear = academicYears[0]

        if (!activeYear) {
          if (!cancelled) {
            setAcademicYear(null)
            setSchedules([])
          }
          return
        }

        const allSchedules = []
        let page = 1
        let lastPage = 1

        do {
          const response = await scheduleApi.getAll({
            academic_year_id: activeYear.id,
            page,
            per_page: PAGE_SIZE,
          })

          allSchedules.push(...getResponseData(response))
          lastPage = Math.max(1, Number(response?.meta?.last_page || 1))
          page += 1
        } while (!cancelled && page <= lastPage)

        if (!cancelled) {
          setAcademicYear(activeYear)
          setSchedules(allSchedules)
        }
      } catch (err) {
        if (!cancelled) {
          setError(
            err?.message ||
              'Terjadi kesalahan saat mengambil data monitoring jadwal.'
          )
        }
      } finally {
        if (!cancelled) setLoading(false)
      }
    }

    loadMonitoringData()

    return () => {
      cancelled = true
    }
  }, [])

  const classOptions = useMemo(
    () =>
      uniqueOptions(
        schedules,
        (item) => item.kelas?.id ?? item.kelas_id,
        (item) => {
          const tingkat = item.kelas?.tingkat
          const nama = item.kelas?.nama
  
          if (tingkat && nama) {
            return `Tingkat ${tingkat} - ${nama}`
          }
  
          return nama || `Kelas #${item.kelas_id}`
        }
      ),
    [schedules]
  )

  const teacherOptions = useMemo(
    () =>
      uniqueOptions(
        schedules,
        (item) => item.guru?.id ?? item.guru_id,
        (item) =>
          item.guru?.nama_lengkap ||
          item.guru?.user?.name ||
          `Guru #${item.guru_id}`
      ),
    [schedules]
  )

  const subjectOptions = useMemo(
    () =>
      uniqueOptions(
        schedules,
        (item) => item.mapel?.id ?? item.mapel_id,
        (item) =>
          item.mapel?.nama ||
          item.mapel?.kode ||
          `Mapel #${item.mapel_id}`
      ),
    [schedules]
  )

  const filteredSchedules = useMemo(
    () =>
      schedules.filter((item) => {
        if (
          filters.class_id &&
          String(item.kelas?.id ?? item.kelas_id) !== filters.class_id
        ) {
          return false
        }

        if (
          filters.teacher_id &&
          String(item.guru?.id ?? item.guru_id) !== filters.teacher_id
        ) {
          return false
        }

        if (
          filters.subject_id &&
          String(item.mapel?.id ?? item.mapel_id) !== filters.subject_id
        ) {
          return false
        }

        if (
          filters.day &&
          String(item.hari || '').toUpperCase() !== filters.day
        ) {
          return false
        }

        return true
      }),
    [schedules, filters]
  )

  const summary = useMemo(
    () => ({
      schedules: filteredSchedules.length,
      classes: new Set(
        filteredSchedules
          .map((item) => item.kelas?.id ?? item.kelas_id)
          .filter((value) => value !== null && value !== undefined)
      ).size,
      teachers: new Set(
        filteredSchedules
          .map((item) => item.guru?.id ?? item.guru_id)
          .filter((value) => value !== null && value !== undefined)
      ).size,
      subjects: new Set(
        filteredSchedules
          .map((item) => item.mapel?.id ?? item.mapel_id)
          .filter((value) => value !== null && value !== undefined)
      ).size,
    }),
    [filteredSchedules]
  )

  function updateFilter(name, value) {
    setFilters((current) => ({
      ...current,
      [name]: value,
    }))
  }

  function resetFilters() {
    setFilters({
      class_id: '',
      teacher_id: '',
      subject_id: '',
      day: '',
    })
  }

  if (loading) {
    return (
      <div className="principal-schedule">
        <div className="principal-schedule__state">
          <span className="principal-schedule__spinner" />
          <p>Memuat monitoring jadwal...</p>
        </div>
      </div>
    )
  }

  if (error) {
    return (
      <div className="principal-schedule">
        <ErrorState
          title="Monitoring jadwal gagal dimuat"
          message={error}
        />
      </div>
    )
  }

  if (!academicYear) {
    return (
      <div className="principal-schedule">
        <ErrorState
          title="Tahun akademik aktif tidak ditemukan"
          message="Tetapkan tahun akademik aktif terlebih dahulu melalui pengelolaan tahun akademik."
        />
      </div>
    )
  }

  return (
    <div className="principal-schedule">
      <header className="principal-schedule__header">
        <div>
          <span className="principal-schedule__eyebrow">
            MONITORING AKADEMIK
          </span>
          <h1>Monitoring Jadwal Pelajaran</h1>
          <p>
            Pantau distribusi jadwal pembelajaran seluruh kelas dan guru.
          </p>
          <span className="principal-schedule__academic-year">
            {academicYear.tahun_mulai}/{academicYear.tahun_selesai}
            {' · '}
            Semester {academicYear.semester === 'GENAP' ? 'Genap' : 'Ganjil'}
          </span>
        </div>
      </header>

      <section className="principal-schedule__summary">
        <article className="principal-schedule__summary-card">
          <span>Total Jadwal</span>
          <strong>{summary.schedules}</strong>
          <small>Jadwal sesuai filter</small>
        </article>

        <article className="principal-schedule__summary-card">
          <span>Kelas Terjadwal</span>
          <strong>{summary.classes}</strong>
          <small>Kelas yang memiliki jadwal</small>
        </article>

        <article className="principal-schedule__summary-card">
          <span>Guru Terlibat</span>
          <strong>{summary.teachers}</strong>
          <small>Guru dalam jadwal terpilih</small>
        </article>

        <article className="principal-schedule__summary-card">
          <span>Mata Pelajaran</span>
          <strong>{summary.subjects}</strong>
          <small>Mata pelajaran terjadwal</small>
        </article>
      </section>

      <section className="principal-schedule__filters">
        <div className="principal-schedule__filter-heading">
          <div>
            <h2>Filter Monitoring</h2>
            <p>Persempit jadwal berdasarkan data akademik.</p>
          </div>
          <button type="button" onClick={resetFilters}>
            Reset Filter
          </button>
        </div>

        <div className="principal-schedule__filter-grid">
          <label>
            <span>Kelas</span>
            <select
              value={filters.class_id}
              onChange={(event) => updateFilter('class_id', event.target.value)}
            >
              <option value="">Semua kelas</option>
              {classOptions.map((item) => (
                <option key={item.value} value={item.value}>
                  {item.label}
                </option>
              ))}
            </select>
          </label>

          <label>
            <span>Guru</span>
            <select
              value={filters.teacher_id}
              onChange={(event) => updateFilter('teacher_id', event.target.value)}
            >
              <option value="">Semua guru</option>
              {teacherOptions.map((item) => (
                <option key={item.value} value={item.value}>
                  {item.label}
                </option>
              ))}
            </select>
          </label>

          <label>
            <span>Mata Pelajaran</span>
            <select
              value={filters.subject_id}
              onChange={(event) => updateFilter('subject_id', event.target.value)}
            >
              <option value="">Semua mata pelajaran</option>
              {subjectOptions.map((item) => (
                <option key={item.value} value={item.value}>
                  {item.label}
                </option>
              ))}
            </select>
          </label>

          <label>
            <span>Hari</span>
            <select
              value={filters.day}
              onChange={(event) => updateFilter('day', event.target.value)}
            >
              <option value="">Semua hari</option>
              <option value="SENIN">Senin</option>
              <option value="SELASA">Selasa</option>
              <option value="RABU">Rabu</option>
              <option value="KAMIS">Kamis</option>
              <option value="JUMAT">Jumat</option>
              <option value="SABTU">Sabtu</option>
              <option value="MINGGU">Minggu</option>
            </select>
          </label>
        </div>
      </section>

      {filteredSchedules.length === 0 ? (
        <div className="principal-schedule__empty">
          <h2>Tidak ada jadwal ditemukan</h2>
          <p>
            Tidak ada jadwal yang sesuai dengan filter saat ini.
            Coba ubah atau reset filter monitoring.
          </p>
          <button type="button" onClick={resetFilters}>
            Reset Filter
          </button>
        </div>
      ) : (
        <InteractiveTimetable
          role="KEPALA_SEKOLAH"
          schedules={filteredSchedules}
          academicYear={academicYear}
        />
      )}
    </div>
  )
}
