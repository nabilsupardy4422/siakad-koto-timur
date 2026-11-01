import { useCallback, useEffect, useMemo, useState } from 'react'

import LoadingState from '../../../components/ui/LoadingState'
import ErrorState from '../../../components/ui/ErrorState'
import EmptyState from '../../../components/ui/EmptyState'

import { academicYearApi } from '../../../services/academicYearApi'
import { scheduleApi } from '../../../services/scheduleApi'
import { classMemberApi } from '../../../services/classMemberApi'
import { gradeApi } from '../../../services/gradeApi'

import '../styles/KepalaSekolahGrades.css'

const PAGE_SIZE = 20

function getCollection(response) {
  const payload = response?.data ?? response

  if (Array.isArray(payload)) {
    return {
      data: payload,
      meta: response?.meta ?? {},
    }
  }

  if (Array.isArray(payload?.data)) {
    return {
      data: payload.data,
      meta: payload.meta ?? response?.meta ?? {},
    }
  }

  return { data: [], meta: {} }
}

async function getAllPages(fetchPage, perPage = 100) {
  const allData = []
  let page = 1
  let lastPage = 1

  do {
    const response = await fetchPage(page, perPage)
    const result = getCollection(response)

    allData.push(...result.data)
    lastPage = Math.max(1, Number(result.meta.last_page ?? 1))
    page += 1
  } while (page <= lastPage && page <= 100)

  return allData
}

function getStudent(member) {
  return member?.siswa ?? member?.student ?? {}
}

function getClassName(schedule) {
  const classData = schedule?.kelas ?? {}

  if (classData.nama) return classData.nama
  if (classData.nama_kelas) return classData.nama_kelas
  if (schedule?.kelas_id) return `Kelas ${schedule.kelas_id}`

  return '-'
}

function getSubjectName(schedule) {
  return (
    schedule?.mapel?.nama ??
    schedule?.mata_pelajaran?.nama ??
    (schedule?.mapel_id ? `Mapel ${schedule.mapel_id}` : '-')
  )
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

function createGradeKey(componentId, studentId) {
  return `${Number(componentId)}-${Number(studentId)}`
}

export default function KepalaSekolahGrades() {
  const [academicYears, setAcademicYears] = useState([])
  const [filters, setFilters] = useState({
    academic_year_id: '',
    semester: '',
    tingkat: '',
    class_id: '',
    subject_id: '',
  })

  const [monitoringRows, setMonitoringRows] = useState([])
  const [loadingYears, setLoadingYears] = useState(true)
  const [loadingMonitoring, setLoadingMonitoring] = useState(false)
  const [lookupError, setLookupError] = useState('')
  const [monitoringError, setMonitoringError] = useState('')
  const [currentPage, setCurrentPage] = useState(1)

  const loadAcademicYears = useCallback(async () => {
    setLoadingYears(true)
    setLookupError('')

    try {
      const years = await getAllPages((page, perPage) =>
        academicYearApi.getAll({ page, per_page: perPage }),
      )

      setAcademicYears(years)

      const activeYear = years.find((year) => year.is_active === true)

      if (activeYear) {
        setFilters((previous) => ({
          ...previous,
          academic_year_id: String(activeYear.id),
          semester: activeYear.semester ?? '',
        }))
      }
    } catch (error) {
      setLookupError(
        error?.message || 'Daftar tahun akademik gagal dimuat.',
      )
    } finally {
      setLoadingYears(false)
    }
  }, [])

  useEffect(() => {
    loadAcademicYears()
  }, [loadAcademicYears])

  const loadMonitoring = useCallback(async () => {
    if (!filters.academic_year_id) {
      setMonitoringRows([])
      setMonitoringError('')
      setLoadingMonitoring(false)
      return
    }

    setLoadingMonitoring(true)
    setMonitoringError('')

    try {
      const schedules = await getAllPages((page, perPage) =>
        scheduleApi.getAll({
          academic_year_id: filters.academic_year_id,
          semester: filters.semester || undefined,
          per_page: perPage,
          page,
        }),
      )

      if (schedules.length === 0) {
        setMonitoringRows([])
        return
      }

      const classIds = [
        ...new Set(
          schedules
            .map((schedule) => Number(schedule.kelas_id ?? schedule.kelas?.id))
            .filter((id) => Number.isFinite(id) && id > 0),
        ),
      ]

      const [membersByClass, scheduleData] = await Promise.all([
        Promise.all(
          classIds.map(async (classId) => {
            const members = await getAllPages((page, perPage) =>
              classMemberApi.getAll(classId, {
                page,
                per_page: perPage,
              }),
            )

            return [classId, members]
          }),
        ),
        Promise.all(
          schedules.map(async (schedule) => {
            const [components, grades] = await Promise.all([
              getAllPages((page, perPage) =>
                gradeApi.getComponents({
                  schedule_id: schedule.id,
                  academic_year_id: filters.academic_year_id,
                  semester: filters.semester || undefined,
                  page,
                  per_page: perPage,
                }),
              ),
              getAllPages((page, perPage) =>
                gradeApi.getGrades({
                  schedule_id: schedule.id,
                  academic_year_id: filters.academic_year_id,
                  semester: filters.semester || undefined,
                  page,
                  per_page: perPage,
                }),
              ),
            ])

            return { schedule, components, grades }
          }),
        ),
      ])

      const memberMap = new Map(membersByClass)
      const rows = []

      scheduleData.forEach(({ schedule, components, grades }) => {
        const classId = Number(schedule.kelas_id ?? schedule.kelas?.id)
        const members = memberMap.get(classId) ?? []
        const gradeMap = new Map(
          grades.map((grade) => [
            createGradeKey(grade.komponen_nilai_id, grade.siswa_id),
            grade,
          ]),
        )

        components.forEach((component) => {
          members.forEach((member) => {
            if (member.tanggal_selesai) return

            const student = getStudent(member)
            const studentId = Number(member.siswa_id ?? student.id)

            if (!studentId) return

            const grade = gradeMap.get(
              createGradeKey(component.id, studentId),
            )

            rows.push({
              id: `${schedule.id}-${component.id}-${studentId}`,
              scheduleId: schedule.id,
              componentId: component.id,
              studentId,
              studentName: student.nama_lengkap ?? student.user?.name ?? '-',
              nis: student.nis ?? '-',
              nisn: student.nisn ?? '-',
              classId,
              className: getClassName(schedule),
              tingkat: schedule.kelas?.tingkat ?? '-',
              subjectId: Number(schedule.mapel_id ?? schedule.mapel?.id),
              subjectName: getSubjectName(schedule),
              componentName: component.nama ?? '-',
              weight: component.bobot,
              score: grade?.nilai ?? null,
              note: grade?.catatan ?? '',
              assessed: Boolean(grade),
            })
          })
        })
      })

      setMonitoringRows(rows)
    } catch (error) {
      setMonitoringRows([])
      setMonitoringError(
        error?.message ||
          'Data monitoring penilaian gagal dimuat. Periksa akses API dan koneksi server.',
      )
    } finally {
      setLoadingMonitoring(false)
    }
  }, [filters.academic_year_id, filters.semester])

  useEffect(() => {
    let active = true

    const run = async () => {
      if (!filters.academic_year_id) {
        setMonitoringRows([])
        setLoadingMonitoring(false)
        return
      }

      setLoadingMonitoring(true)
      setMonitoringError('')

      try {
        await loadMonitoring()
      } finally {
        if (!active) return
      }
    }

    run()

    return () => {
      active = false
    }
  }, [loadMonitoring, filters.academic_year_id])

  const selectedYear = useMemo(
    () =>
      academicYears.find(
        (year) => String(year.id) === String(filters.academic_year_id),
      ) ?? null,
    [academicYears, filters.academic_year_id],
  )

  const availableRows = useMemo(() => {
    return monitoringRows.filter((row) => {
      if (filters.tingkat && String(row.tingkat) !== filters.tingkat) {
        return false
      }

      if (filters.class_id && String(row.classId) !== filters.class_id) {
        return false
      }

      if (
        filters.subject_id &&
        String(row.subjectId) !== filters.subject_id
      ) {
        return false
      }

      return true
    })
  }, [monitoringRows, filters.tingkat, filters.class_id, filters.subject_id])

  const levelOptions = useMemo(() => {
    const levels = [
      ...new Set(
        monitoringRows
          .map((row) => String(row.tingkat))
          .filter((level) => level !== '-' && level !== 'undefined'),
      ),
    ].sort((a, b) => Number(a) - Number(b))

    return levels
  }, [monitoringRows])

  const classOptions = useMemo(() => {
    const classMap = new Map()

    monitoringRows.forEach((row) => {
      if (
        filters.tingkat &&
        String(row.tingkat) !== filters.tingkat
      ) {
        return
      }

      classMap.set(String(row.classId), {
        value: String(row.classId),
        label: `${row.className} - Tingkat ${row.tingkat}`,
      })
    })

    return [...classMap.values()].sort((a, b) =>
      a.label.localeCompare(b.label, 'id'),
    )
  }, [monitoringRows, filters.tingkat])

  const subjectOptions = useMemo(() => {
    const subjectMap = new Map()

    monitoringRows.forEach((row) => {
      if (
        filters.class_id &&
        String(row.classId) !== filters.class_id
      ) {
        return
      }

      if (
        filters.tingkat &&
        String(row.tingkat) !== filters.tingkat
      ) {
        return
      }

      subjectMap.set(String(row.subjectId), {
        value: String(row.subjectId),
        label: row.subjectName,
      })
    })

    return [...subjectMap.values()].sort((a, b) =>
      a.label.localeCompare(b.label, 'id'),
    )
  }, [monitoringRows, filters.class_id, filters.tingkat])

  const summary = useMemo(() => {
    const studentIds = new Set(
      availableRows.map((row) => row.studentId),
    )
    const assessed = availableRows.filter((row) => row.assessed).length

    return {
      students: studentIds.size,
      slots: availableRows.length,
      assessed,
      pending: availableRows.length - assessed,
    }
  }, [availableRows])

  const lastPage = Math.max(1, Math.ceil(availableRows.length / PAGE_SIZE))
  const safePage = Math.min(currentPage, lastPage)
  const pageRows = availableRows.slice(
    (safePage - 1) * PAGE_SIZE,
    safePage * PAGE_SIZE,
  )

  const yearOptions = academicYears.map((year) => ({
    value: String(year.id),
    label: `${formatAcademicYear(year)}${year.semester ? ` - ${year.semester}` : ''}${year.is_active ? ' (Aktif)' : ''}`,
  }))

  const updateFilter = (name, value) => {
    setCurrentPage(1)

    setFilters((previous) => {
      const next = { ...previous, [name]: value }

      if (name === 'tingkat') {
        next.class_id = ''
        next.subject_id = ''
      }

      if (name === 'class_id') {
        next.subject_id = ''
      }

      if (name === 'academic_year_id') {
        const nextYear = academicYears.find(
          (year) => String(year.id) === String(value),
        )
        next.semester = nextYear?.semester ?? ''
        next.tingkat = ''
        next.class_id = ''
        next.subject_id = ''
      }

      if (name === 'semester') {
        next.tingkat = ''
        next.class_id = ''
        next.subject_id = ''
      }

      return next
    })
  }

  const resetFilters = () => {
    setFilters({
      academic_year_id: selectedYear?.is_active
        ? String(selectedYear.id)
        : '',
      semester: selectedYear?.is_active
        ? selectedYear.semester ?? ''
        : '',
      tingkat: '',
      class_id: '',
      subject_id: '',
    })
    setCurrentPage(1)
  }

  const refreshMonitoring = () => {
    loadMonitoring()
    setCurrentPage(1)
  }

  if (loadingYears) {
    return <LoadingState message="Memuat tahun akademik..." />
  }

  if (lookupError) {
    return (
      <ErrorState
        title="Tahun akademik gagal dimuat"
        message={lookupError}
        onRetry={loadAcademicYears}
      />
    )
  }

  return (
    <div className="kepala-grades">
      <header className="kepala-grades__header">
        <div>
          <h1>Monitoring Penilaian</h1>
          <p>
            Pantau kelengkapan penilaian siswa berdasarkan periode akademik,
            tingkat, kelas, dan mata pelajaran.
          </p>
        </div>
        <div className="kepala-grades__period">
          <span>Periode terpilih</span>
          <strong>
            {selectedYear
              ? `${formatAcademicYear(selectedYear)} - ${filters.semester || selectedYear.semester || '-'}`
              : 'Belum dipilih'}
          </strong>
        </div>
      </header>

      <section className="kepala-grades__panel">
        <div className="kepala-grades__panel-heading">
          <div>
            <h2>Filter Monitoring</h2>
            <p>
              Data historis tersedia sesuai hak akses. Periode aktif dipilih
              secara otomatis saat halaman dibuka.
            </p>
          </div>
          <button
            type="button"
            className="kepala-grades__button kepala-grades__button--secondary"
            onClick={resetFilters}
          >
            Reset Filter
          </button>
        </div>

        <div className="kepala-grades__filters">
          <label>
            <span>Tahun Akademik</span>
            <select
              value={filters.academic_year_id}
              onChange={(event) =>
                updateFilter('academic_year_id', event.target.value)
              }
            >
              <option value="">Pilih tahun akademik</option>
              {yearOptions.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          </label>

          <label>
            <span>Semester</span>
            <select
              value={filters.semester}
              onChange={(event) =>
                updateFilter('semester', event.target.value)
              }
              disabled={!filters.academic_year_id}
            >
              <option value="">Semua semester</option>
              <option value="GANJIL">Ganjil</option>
              <option value="GENAP">Genap</option>
            </select>
          </label>

          <label>
            <span>Tingkat</span>
            <select
              value={filters.tingkat}
              onChange={(event) =>
                updateFilter('tingkat', event.target.value)
              }
              disabled={loadingMonitoring || monitoringRows.length === 0}
            >
              <option value="">Semua tingkat</option>
              {levelOptions.map((level) => (
                <option key={level} value={level}>
                  Tingkat {level}
                </option>
              ))}
            </select>
          </label>

          <label>
            <span>Kelas</span>
            <select
              value={filters.class_id}
              onChange={(event) =>
                updateFilter('class_id', event.target.value)
              }
              disabled={loadingMonitoring || monitoringRows.length === 0}
            >
              <option value="">Semua kelas</option>
              {classOptions.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          </label>

          <label>
            <span>Mata Pelajaran</span>
            <select
              value={filters.subject_id}
              onChange={(event) =>
                updateFilter('subject_id', event.target.value)
              }
              disabled={loadingMonitoring || monitoringRows.length === 0}
            >
              <option value="">Semua mata pelajaran</option>
              {subjectOptions.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          </label>
        </div>
      </section>

      <section className="kepala-grades__panel">
        <div className="kepala-grades__panel-heading">
          <div>
            <h2>Ringkasan Penilaian</h2>
            <p>
              Total slot penilaian dihitung dari siswa aktif dikalikan
              komponen yang tersedia pada jadwal.
            </p>
          </div>
          <button
            type="button"
            className="kepala-grades__button kepala-grades__button--primary"
            onClick={refreshMonitoring}
            disabled={loadingMonitoring || !filters.academic_year_id}
          >
            Muat Ulang
          </button>
        </div>

        {loadingMonitoring ? (
          <LoadingState message="Memuat monitoring penilaian sekolah..." />
        ) : monitoringError ? (
          <ErrorState
            title="Monitoring penilaian gagal dimuat"
            message={monitoringError}
            onRetry={refreshMonitoring}
          />
        ) : !filters.academic_year_id ? (
          <EmptyState
            title="Pilih tahun akademik"
            message="Pilih periode akademik untuk menampilkan monitoring penilaian."
          />
        ) : monitoringRows.length === 0 ? (
          <EmptyState
            title="Belum ada slot penilaian"
            message="Belum ditemukan jadwal, komponen penilaian, atau siswa aktif pada periode yang dipilih."
          />
        ) : (
          <>
            <div className="kepala-grades__summary">
              <article>
                <span>Total Siswa</span>
                <strong>{summary.students}</strong>
              </article>
              <article>
                <span>Total Slot Penilaian</span>
                <strong>{summary.slots}</strong>
              </article>
              <article>
                <span>Sudah Dinilai</span>
                <strong className="is-success">{summary.assessed}</strong>
              </article>
              <article>
                <span>Belum Dinilai</span>
                <strong className="is-warning">{summary.pending}</strong>
              </article>
            </div>

            {availableRows.length === 0 ? (
              <EmptyState
                title="Tidak ada data sesuai filter"
                message="Ubah tingkat, kelas, atau mata pelajaran untuk melihat data lainnya."
              />
            ) : (
              <>
                <div className="kepala-grades__table-wrap">
                  <table className="kepala-grades__table">
                    <thead>
                      <tr>
                        <th>No.</th>
                        <th>Nama Siswa</th>
                        <th>NIS / NISN</th>
                        <th>Kelas</th>
                        <th>Tingkat</th>
                        <th>Mata Pelajaran</th>
                        <th>Komponen</th>
                        <th>Bobot</th>
                        <th>Nilai</th>
                        <th>Catatan</th>
                        <th>Status</th>
                      </tr>
                    </thead>
                    <tbody>
                      {pageRows.map((row, index) => (
                        <tr key={row.id}>
                          <td>{(safePage - 1) * PAGE_SIZE + index + 1}</td>
                          <td><strong>{row.studentName}</strong></td>
                          <td>{row.nis !== '-' ? row.nis : row.nisn}</td>
                          <td>{row.className}</td>
                          <td>Tingkat {row.tingkat}</td>
                          <td>{row.subjectName}</td>
                          <td>{row.componentName}</td>
                          <td>{formatWeight(row.weight)}</td>
                          <td>
                            <strong>{formatNumber(row.score)}</strong>
                          </td>
                          <td>{row.note || '-'}</td>
                          <td>
                            <span
                              className={`kepala-grades__status ${
                                row.assessed
                                  ? 'kepala-grades__status--complete'
                                  : 'kepala-grades__status--pending'
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

                <div className="kepala-grades__pagination">
                  <span>
                    Menampilkan {pageRows.length} dari {availableRows.length}{' '}
                    slot penilaian.
                  </span>
                  <div>
                    <button
                      type="button"
                      className="kepala-grades__button kepala-grades__button--secondary"
                      disabled={safePage <= 1}
                      onClick={() => setCurrentPage((page) => page - 1)}
                    >
                      Sebelumnya
                    </button>
                    <span>
                      Halaman {safePage} / {lastPage}
                    </span>
                    <button
                      type="button"
                      className="kepala-grades__button kepala-grades__button--primary"
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
