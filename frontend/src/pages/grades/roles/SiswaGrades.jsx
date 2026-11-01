import { useCallback, useEffect, useMemo, useState } from 'react'

import LoadingState from '../../../components/ui/LoadingState'
import ErrorState from '../../../components/ui/ErrorState'
import EmptyState from '../../../components/ui/EmptyState'

import { useAuth } from '../../../context/useAuth'
import { academicYearApi } from '../../../services/academicYearApi'
import { scheduleApi } from '../../../services/scheduleApi'
import { gradeApi } from '../../../services/gradeApi'

import '../styles/SiswaGrades.css'

function getCollection(response) {
  const payload = response?.data ?? response

  if (Array.isArray(payload)) {
    return { data: payload, meta: response?.meta ?? {} }
  }

  if (Array.isArray(payload?.data)) {
    return { data: payload.data, meta: payload.meta ?? response?.meta ?? {} }
  }

  return { data: [], meta: {} }
}

async function getAllPages(fetchPage, perPage = 100) {
  const result = []
  let page = 1
  let lastPage = 1

  do {
    const response = await fetchPage(page, perPage)
    const collection = getCollection(response)

    result.push(...collection.data)
    lastPage = Math.max(1, Number(collection.meta.last_page ?? 1))
    page += 1
  } while (page <= lastPage && page <= 100)

  return result
}

function formatAcademicYear(year) {
  if (!year) return '-'

  return `${year.tahun_mulai ?? '?'} / ${year.tahun_selesai ?? '?'}`
}

function formatNumber(value) {
  if (value === null || value === undefined || value === '') return '-'

  return Number(value).toLocaleString('id-ID', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
  })
}

function formatWeight(value) {
  if (value === null || value === undefined || value === '') return '-'
  return `${formatNumber(value)}%`
}

function getSubjectName(schedule) {
  return (
    schedule?.mapel?.nama ??
    schedule?.mata_pelajaran?.nama ??
    (schedule?.mapel_id ? `Mata Pelajaran ${schedule.mapel_id}` : '-')
  )
}

function getClassName(schedule) {
  const className = schedule?.kelas?.nama ?? schedule?.kelas?.nama_kelas

  if (className) return className
  if (schedule?.kelas_id) return `Kelas ${schedule.kelas_id}`

  return '-'
}

export default function SiswaGrades() {
  const { user } = useAuth()

  const [academicYears, setAcademicYears] = useState([])
  const [selectedYearId, setSelectedYearId] = useState('')
  const [rows, setRows] = useState([])
  const [loadingYears, setLoadingYears] = useState(true)
  const [loadingGrades, setLoadingGrades] = useState(false)
  const [pageError, setPageError] = useState('')
  const [currentPage, setCurrentPage] = useState(1)

  const PAGE_SIZE = 15

  const loadAcademicYears = useCallback(async () => {
    setLoadingYears(true)
    setPageError('')

    try {
      const years = await getAllPages((page, perPage) =>
        academicYearApi.getAll({ page, per_page: perPage }),
      )

      setAcademicYears(years)

      const activeYear = years.find((year) => year.is_active === true)
      const initialYear = activeYear ?? years[0]

      if (initialYear) {
        setSelectedYearId(String(initialYear.id))
      }
    } catch (error) {
      setPageError(error?.message || 'Tahun akademik gagal dimuat.')
    } finally {
      setLoadingYears(false)
    }
  }, [])

  useEffect(() => {
    loadAcademicYears()
  }, [loadAcademicYears])

  const selectedYear = useMemo(
    () =>
      academicYears.find((year) => String(year.id) === selectedYearId) ?? null,
    [academicYears, selectedYearId],
  )

  const loadGrades = useCallback(async () => {
    if (!selectedYearId) {
      setRows([])
      setLoadingGrades(false)
      return
    }

    setLoadingGrades(true)
    setPageError('')

    try {
      // Scope SISWA diterapkan oleh backend berdasarkan akun yang sedang login.
      // Frontend tidak mengirim student_id agar tidak dapat dimanipulasi pengguna.
      const schedules = await getAllPages((page, perPage) =>
        scheduleApi.getAll({
          academic_year_id: selectedYearId,
          semester: selectedYear?.semester || undefined,
          page,
          per_page: perPage,
        }),
      )

      const scheduleResults = await Promise.all(
        schedules.map(async (schedule) => {
          const [components, grades] = await Promise.all([
            getAllPages((page, perPage) =>
              gradeApi.getComponents({
                schedule_id: schedule.id,
                academic_year_id: selectedYearId,
                semester: selectedYear?.semester || undefined,
                page,
                per_page: perPage,
              }),
            ),
            getAllPages((page, perPage) =>
              gradeApi.getGrades({
                schedule_id: schedule.id,
                academic_year_id: selectedYearId,
                semester: selectedYear?.semester || undefined,
                page,
                per_page: perPage,
              }),
            ),
          ])

          return { schedule, components, grades }
        }),
      )

      const nextRows = []

      scheduleResults.forEach(({ schedule, components, grades }) => {
        const gradesByComponent = new Map(
          grades.map((grade) => [
            Number(grade.komponen_nilai_id),
            grade,
          ]),
        )

        components.forEach((component) => {
          const grade = gradesByComponent.get(Number(component.id))

          nextRows.push({
            id: `${schedule.id}-${component.id}`,
            subject: getSubjectName(schedule),
            className: getClassName(schedule),
            day: schedule.hari ?? '-',
            startTime: schedule.jam_mulai?.slice(0, 5) ?? '',
            endTime: schedule.jam_selesai?.slice(0, 5) ?? '',
            component: component.nama ?? '-',
            weight: component.bobot,
            score: grade?.nilai ?? null,
            note: grade?.catatan ?? '',
            assessed: Boolean(grade),
          })
        })
      })

      nextRows.sort((a, b) => {
        const subjectOrder = a.subject.localeCompare(b.subject, 'id')
        if (subjectOrder !== 0) return subjectOrder
        return a.component.localeCompare(b.component, 'id')
      })

      setRows(nextRows)
      setCurrentPage(1)
    } catch (error) {
      setRows([])
      setPageError(
        error?.message ||
          'Nilai akademik gagal dimuat. Periksa koneksi dan hak akses akun siswa.',
      )
    } finally {
      setLoadingGrades(false)
    }
  }, [selectedYearId, selectedYear])

  useEffect(() => {
    if (!loadingYears && selectedYearId) {
      loadGrades()
    }
  }, [loadingYears, selectedYearId, loadGrades])

  const summary = useMemo(() => {
    const subjects = new Set(rows.map((row) => row.subject))

    return {
      subjects: subjects.size,
      components: rows.length,
      assessed: rows.filter((row) => row.assessed).length,
      pending: rows.filter((row) => !row.assessed).length,
    }
  }, [rows])

  const lastPage = Math.max(1, Math.ceil(rows.length / PAGE_SIZE))
  const safePage = Math.min(currentPage, lastPage)
  const visibleRows = rows.slice(
    (safePage - 1) * PAGE_SIZE,
    safePage * PAGE_SIZE,
  )

  const studentName =
    user?.name ??
    user?.nama_lengkap ??
    user?.username ??
    'Siswa'

  if (loadingYears) {
    return <LoadingState message="Memuat informasi tahun akademik..." />
  }

  if (pageError && academicYears.length === 0) {
    return (
      <ErrorState
        title="Halaman nilai tidak dapat dimuat"
        message={pageError}
        onRetry={loadAcademicYears}
      />
    )
  }

  return (
    <div className="siswa-grades">
      <header className="siswa-grades__header">
        <div>
          <p className="siswa-grades__eyebrow">AKADEMIK SISWA</p>
          <h1>Nilai Akademik Saya</h1>
          <p>
            Halo, <strong>{studentName}</strong>. Berikut ringkasan nilai
            berdasarkan tahun akademik yang dipilih.
          </p>
        </div>
        <div className="siswa-grades__identity">
          <span>Akun Siswa</span>
          <strong>{studentName}</strong>
        </div>
      </header>

      <section className="siswa-grades__panel">
        <div className="siswa-grades__panel-heading">
          <div>
            <h2>Periode Akademik</h2>
            <p>Pilih tahun akademik untuk melihat nilai pada periode tersebut.</p>
          </div>
          <button
            type="button"
            className="siswa-grades__button siswa-grades__button--secondary"
            onClick={loadGrades}
            disabled={loadingGrades || !selectedYearId}
          >
            Muat Ulang
          </button>
        </div>

        <div className="siswa-grades__filters">
          <label>
            <span>Tahun Akademik</span>
            <select
              value={selectedYearId}
              onChange={(event) => setSelectedYearId(event.target.value)}
            >
              {academicYears.map((year) => (
                <option key={year.id} value={year.id}>
                  {formatAcademicYear(year)} - {year.semester}
                  {year.is_active ? ' (Aktif)' : ''}
                </option>
              ))}
            </select>
          </label>

          <div className="siswa-grades__period-info">
            <span>Semester</span>
            <strong>
              {selectedYear?.semester === 'GANJIL'
                ? 'Ganjil'
                : selectedYear?.semester === 'GENAP'
                  ? 'Genap'
                  : '-'}
            </strong>
          </div>
        </div>
      </section>

      <section className="siswa-grades__panel">
        <div className="siswa-grades__panel-heading">
          <div>
            <h2>Ringkasan Nilai</h2>
            <p>
              Nilai dan catatan hanya berasal dari data akademik akun siswa
              yang sedang login.
            </p>
          </div>
        </div>

        {loadingGrades ? (
          <LoadingState message="Memuat nilai akademik..." />
        ) : pageError ? (
          <ErrorState
            title="Nilai gagal dimuat"
            message={pageError}
            onRetry={loadGrades}
          />
        ) : (
          <>
            <div className="siswa-grades__summary">
              <article>
                <span>Total Mata Pelajaran</span>
                <strong>{summary.subjects}</strong>
              </article>
              <article>
                <span>Total Komponen</span>
                <strong>{summary.components}</strong>
              </article>
              <article>
                <span>Nilai Tersedia</span>
                <strong className="is-success">{summary.assessed}</strong>
              </article>
              <article>
                <span>Belum Dinilai</span>
                <strong className="is-warning">{summary.pending}</strong>
              </article>
            </div>

            {rows.length === 0 ? (
              <EmptyState
                title="Belum ada data penilaian"
                message="Belum ditemukan komponen penilaian untuk kelas dan periode akademik akun ini."
              />
            ) : (
              <>
                <div className="siswa-grades__table-wrap">
                  <table className="siswa-grades__table">
                    <thead>
                      <tr>
                        <th>No.</th>
                        <th>Mata Pelajaran</th>
                        <th>Kelas</th>
                        <th>Komponen Penilaian</th>
                        <th>Bobot</th>
                        <th>Nilai</th>
                        <th>Catatan Guru</th>
                        <th>Status</th>
                      </tr>
                    </thead>
                    <tbody>
                      {visibleRows.map((row, index) => (
                        <tr key={row.id}>
                          <td>{(safePage - 1) * PAGE_SIZE + index + 1}</td>
                          <td><strong>{row.subject}</strong></td>
                          <td>{row.className}</td>
                          <td>{row.component}</td>
                          <td>{formatWeight(row.weight)}</td>
                          <td><strong>{formatNumber(row.score)}</strong></td>
                          <td>{row.note || '-'}</td>
                          <td>
                            <span
                              className={`siswa-grades__status ${
                                row.assessed
                                  ? 'siswa-grades__status--complete'
                                  : 'siswa-grades__status--pending'
                              }`}
                            >
                              {row.assessed ? 'Sudah Dinilai' : 'Belum Dinilai'}
                            </span>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>

                <div className="siswa-grades__pagination">
                  <span>
                    Menampilkan {visibleRows.length} dari {rows.length} data.
                  </span>
                  <div>
                    <button
                      type="button"
                      className="siswa-grades__button siswa-grades__button--secondary"
                      disabled={safePage <= 1}
                      onClick={() => setCurrentPage((page) => page - 1)}
                    >
                      Sebelumnya
                    </button>
                    <span>Halaman {safePage} / {lastPage}</span>
                    <button
                      type="button"
                      className="siswa-grades__button siswa-grades__button--primary"
                      disabled={safePage >= lastPage}
                      onClick={() => setCurrentPage((page) => page + 1)}
                    >
                      Berikutnya
                    </button>
                  </div>
                </div>
              </>
            )}
          </>
        )}
      </section>
    </div>
  )
}
