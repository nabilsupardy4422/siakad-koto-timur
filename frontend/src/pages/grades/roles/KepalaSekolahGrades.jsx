import { useCallback, useEffect, useMemo, useRef, useState } from 'react'

import LoadingState from '../../../components/ui/LoadingState'
import ErrorState from '../../../components/ui/ErrorState'
import EmptyState from '../../../components/ui/EmptyState'

import { academicYearApi } from '../../../services/academicYearApi'
import { scheduleApi } from '../../../services/scheduleApi'
import { classMemberApi } from '../../../services/classMemberApi'
import { gradeApi } from '../../../services/gradeApi'
import { getAcademicYears, getAllPages, isActivePeriod, strictId, scoreOf } from './principalGradeIntegrity'
import { matchesPrincipalStudentSearch } from './principalStudentSearch'

import '../styles/KepalaSekolahGrades.css'

const PAGE_SIZE = 20

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
  const [studentSearch, setStudentSearch] = useState('')
  const tableScrollRef = useRef(null)
  const [tableScroll, setTableScroll] = useState({ left: false, right: false })
  const monitoringRequest = useRef(0)

  const loadAcademicYears = useCallback(async () => {
    setLoadingYears(true)
    setLookupError('')

    try {
      const years = getAcademicYears(await academicYearApi.getAll())

      if (!years.every((year) => Number.isSafeInteger(Number(year?.id)) && ['GANJIL', 'GENAP'].includes(year?.semester))) {
        throw new Error('Data tahun akademik tidak valid.')
      }
      setAcademicYears(years)

      const activeYear = years.find(isActivePeriod) || years[0]

      if (activeYear) {
        setFilters((previous) => ({
          ...previous,
          academic_year_id: String(activeYear.id),
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
    const requestId = ++monitoringRequest.current
    const selectedPeriod = academicYears.find(
      (year) => String(year.id) === String(filters.academic_year_id),
    )

    if (!selectedPeriod) {
      setMonitoringRows([])
      setMonitoringError('')
      setLoadingMonitoring(false)
      return
    }

    const historical = !isActivePeriod(selectedPeriod)
    setLoadingMonitoring(true)
    setMonitoringError('')
    setMonitoringRows([])

    try {
      const schedules = await getAllPages((page, perPage) =>
        scheduleApi.getAll({
          academic_year_id: selectedPeriod.id,
          semester: selectedPeriod.semester,
          per_page: perPage,
          page,
        }),
      )
      // The year/semester must be guaranteed by the server, not inferred from
      // a label in the frontend.
      for (const schedule of schedules) {
        strictId(schedule.id)
        if (Number(schedule.tahun_akademik_id) !== Number(selectedPeriod.id)) {
          throw new Error('Jadwal dari periode akademik lain terdeteksi.')
        }
      }

      const classIds = [...new Set(schedules.map((s) => strictId(s.kelas_id ?? s.kelas?.id)))]
      // The regular class-members API returns TODAY's active memberships only.
      // Therefore historical periods must display STORED grades, not create
      // fictitious "missing slots" from today's roster.
      const membersByClass = historical ? [] : await Promise.all(
        classIds.map(async (classId) => [classId, await getAllPages((page, perPage) =>
          classMemberApi.getAll(classId, { page, per_page: perPage }),
        )]),
      )

      const scheduleData = await Promise.all(schedules.map(async (schedule) => {
        const [components, grades] = await Promise.all([
          getAllPages((page, perPage) => gradeApi.getComponents({
            schedule_id: schedule.id, academic_year_id: selectedPeriod.id,
            semester: selectedPeriod.semester, page, per_page: perPage,
          })),
          getAllPages((page, perPage) => gradeApi.getGrades({
            schedule_id: schedule.id, academic_year_id: selectedPeriod.id,
            semester: selectedPeriod.semester, page, per_page: perPage,
          })),
        ])
        return { schedule, components, grades }
      }))

      const memberMap = new Map(membersByClass)
      const rows = []
      const seen = new Set()
      for (const { schedule, components, grades } of scheduleData) {
        const scheduleId = strictId(schedule.id)
        const classId = strictId(schedule.kelas_id ?? schedule.kelas?.id)
        const componentMap = new Map()
        const gradeMap = new Map()
        for (const component of components) {
          const componentId = strictId(component.id)
          if (Number(component.jadwal_pelajaran_id) !== scheduleId || componentMap.has(componentId)) {
            throw new Error('Komponen penilaian tidak sesuai jadwal atau terduplikasi.')
          }
          componentMap.set(componentId, component)
        }
        for (const grade of grades) {
          const componentId = strictId(grade.komponen_nilai_id)
          const studentId = strictId(grade.siswa_id)
          const key = createGradeKey(componentId, studentId)
          if (!componentMap.has(componentId) || gradeMap.has(key)) {
            throw new Error('Nilai tidak sesuai komponen atau terduplikasi.')
          }
          scoreOf(grade)
          gradeMap.set(key, grade)
        }

        function append(component, studentId, student, grade) {
          const componentId = strictId(component.id)
          const id = `${scheduleId}-${componentId}-${studentId}`
          if (seen.has(id)) throw new Error('Slot penilaian ganda ditemukan.')
          seen.add(id)
          rows.push({
            id,
            scheduleId,
            componentId,
            studentId,
            studentName: student?.nama_lengkap ?? student?.user?.name ?? '-',
            nis: student?.nis ?? '-',
            nisn: student?.nisn ?? '-',
            classId,
            className: getClassName(schedule),
            tingkat: schedule.kelas?.tingkat ?? '-',
            subjectId: strictId(schedule.mapel_id ?? schedule.mapel?.id),
            subjectName: getSubjectName(schedule),
            componentName: component.nama ?? '-',
            weight: component.bobot,
            score: scoreOf(grade),
            note: grade?.catatan ?? '',
            assessed: Boolean(grade),
          })
        }

        if (historical) {
          // Historical data includes only recorded grades. Never report
          // "0 unassessed" as proof that historical scoring is complete.
          for (const grade of gradeMap.values()) {
            const studentId = strictId(grade.siswa_id)
            if (grade.siswa && Number(grade.siswa.id) !== studentId) {
              throw new Error('Identitas siswa pada nilai tidak sesuai.')
            }
            append(componentMap.get(strictId(grade.komponen_nilai_id)), studentId, grade.siswa, grade)
          }
        } else {
          const members = memberMap.get(classId) ?? []
          const usedMembers = new Set()
          for (const member of members) {
            if (Number(member.kelas_id) !== classId) throw new Error('Anggota dari kelas lain terdeteksi.')
            const student = getStudent(member)
            const studentId = strictId(member.siswa_id ?? student.id)
            if (usedMembers.has(studentId)) throw new Error('Keanggotaan kelas ganda ditemukan.')
            usedMembers.add(studentId)
            for (const component of components) {
              const grade = gradeMap.get(createGradeKey(component.id, studentId))
              append(component, studentId, student, grade)
            }
          }
        }
      }

      if (monitoringRequest.current === requestId) setMonitoringRows(rows)
    } catch (error) {
      if (monitoringRequest.current === requestId) {
        setMonitoringRows([])
        setMonitoringError(error?.message || 'Data monitoring penilaian gagal dimuat.')
      }
    } finally {
      if (monitoringRequest.current === requestId) setLoadingMonitoring(false)
    }
  }, [academicYears, filters.academic_year_id])

  useEffect(() => {
    loadMonitoring()
    return () => { monitoringRequest.current += 1 }
  }, [loadMonitoring])

  const selectedYear = useMemo(
    () =>
      academicYears.find(
        (year) => String(year.id) === String(filters.academic_year_id),
      ) ?? null,
    [academicYears, filters.academic_year_id],
  )

  const historical = selectedYear !== null && !isActivePeriod(selectedYear)

  const availableRows = useMemo(() => {
    return monitoringRows.filter((row) => {
      if (!matchesPrincipalStudentSearch(row, studentSearch)) return false
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
  }, [monitoringRows, filters.tingkat, filters.class_id, filters.subject_id, studentSearch])

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
      classes: new Set(availableRows.map((row) => row.classId)).size,
      components: new Set(availableRows.map((row) => `${row.scheduleId}-${row.componentId}`)).size,
    }
  }, [availableRows])

  const lastPage = Math.max(1, Math.ceil(availableRows.length / PAGE_SIZE))
  const safePage = Math.min(currentPage, lastPage)
  const pageRows = availableRows.slice(
    (safePage - 1) * PAGE_SIZE,
    safePage * PAGE_SIZE,
  )

  const updateTableScroll = useCallback(() => {
    const element = tableScrollRef.current
    if (!element) {
      setTableScroll({ left: false, right: false })
      return
    }
    const max = Math.max(0, element.scrollWidth - element.clientWidth)
    setTableScroll({
      left: max > 2 && element.scrollLeft > 2,
      right: max > 2 && element.scrollLeft < max - 2,
    })
  }, [])

  useEffect(() => {
    // Re-measure when filter results, loading state, or viewport size changes.
    // The scrollable element belongs to the table, not the page/document.
    const element = tableScrollRef.current
    if (!element) return undefined
    const frame = window.requestAnimationFrame(updateTableScroll)
    window.addEventListener('resize', updateTableScroll)
    const observer = typeof ResizeObserver !== 'undefined'
      ? new ResizeObserver(updateTableScroll)
      : null
    observer?.observe(element)
    return () => {
      window.cancelAnimationFrame(frame)
      window.removeEventListener('resize', updateTableScroll)
      observer?.disconnect()
    }
  }, [updateTableScroll, loadingMonitoring, monitoringError, availableRows.length])

  const scrollGradeTable = useCallback((direction) => {
    const element = tableScrollRef.current
    if (!element || (direction !== -1 && direction !== 1)) return
    const distance = Math.max(180, Math.round(element.clientWidth * 0.8))
    element.scrollBy({ left: direction * distance, behavior: 'smooth' })
  }, [])

  const updateSearch = (value) => {
    setStudentSearch(value)
    setCurrentPage(1)
  }

  const yearOptions = academicYears.map((year) => ({
    value: String(year.id),
    label: `${formatAcademicYear(year)}${year.semester ? ` - ${year.semester}` : ''}${isActivePeriod(year) ? ' (Aktif)' : ''}`,
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
        setStudentSearch('')
        next.tingkat = ''
        next.class_id = ''
        next.subject_id = ''
      }


      return next
    })
  }

  const resetFilters = () => {
    setStudentSearch('')
    setFilters({
      academic_year_id: selectedYear ? String(selectedYear.id) : '',
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
          <span className="kepala-grades__eyebrow">MONITORING AKADEMIK · PENILAIAN</span>
          <h1>Pantau penilaian sekolah,<br /><em>tanpa mengubah data guru.</em></h1>
          <p>Telusuri komponen dan nilai yang tercatat menurut periode, tingkat, kelas, serta mata pelajaran. Hanya akses baca.</p>
        </div>
        <div className="kepala-grades__period">
          <span>PERIODE DIPANTAU · BACA SAJA</span>
          <strong>
            {selectedYear
              ? `${formatAcademicYear(selectedYear)} - ${selectedYear.semester || '-'}`
              : 'Belum dipilih'}
          </strong>
        </div>
      </header>

      <section className="kepala-grades__panel">
        <div className="kepala-grades__panel-heading">
          <div>
            <h2>Filter Monitoring</h2>
            <p>
              Pilih tahun akademik beserta semesternya dalam satu kolom. Tingkat, kelas, dan mata pelajaran hanya menyaring data yang sudah dimuat.
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
            <span>Tahun Akademik / Semester</span>
            <select
              value={filters.academic_year_id}
              onChange={(event) =>
                updateFilter('academic_year_id', event.target.value)
              }
            >
              <option value="">Pilih tahun akademik / semester</option>
              {yearOptions.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
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
        <div className="kepala-grades__student-search">
          <label htmlFor="principal-grade-student-search">Cari siswa (nama / NISN)</label>
          <div className="kepala-grades__search-row">
            <input
              id="principal-grade-student-search"
              type="search"
              value={studentSearch}
              onChange={(event) => updateSearch(event.target.value)}
              placeholder="Ketik nama siswa atau NISN..."
              autoComplete="off"
              disabled={loadingMonitoring || monitoringRows.length === 0}
              aria-describedby="principal-grade-search-help"
            />
            {studentSearch && (
              <button
                type="button"
                className="kepala-grades__button kepala-grades__button--secondary"
                onClick={() => updateSearch('')}
              >
                Hapus
              </button>
            )}
          </div>
          <small id="principal-grade-search-help">
            Pencarian pada data periode terpilih. NISN (nomor nasional) berbeda dari NIS (nomor sekolah).
          </small>
        </div>
      </section>

      <section className="kepala-grades__panel">
        <div className="kepala-grades__panel-heading">
          <div>
            <h2>Ringkasan Penilaian</h2>
            <p>
              {historical
                ? 'Riwayat hanya menampilkan nilai yang benar-benar tersimpan. Tidak menghitung slot belum dinilai dari anggota kelas saat ini.'
                : 'Slot monitoring dihitung dari anggota kelas aktif saat ini dan komponen nilai yang tersedia pada jadwal. Bukan hasil rapor akhir.'}
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
              title={historical ? 'Belum ada nilai tersimpan' : 'Belum ada slot penilaian'}
            message={historical
              ? 'Tidak ditemukan catatan nilai tersimpan untuk periode ini.'
              : 'Belum ditemukan jadwal, komponen penilaian, atau anggota kelas aktif saat ini pada periode yang dipilih.'}
          />
        ) : (
          <>
            <div className="kepala-grades__summary" aria-label="Ringkasan monitoring nilai">
              {historical ? (
                <>
                  <article><span>Siswa dengan nilai tersimpan</span><strong>{summary.students}</strong></article>
                  <article><span>Catatan nilai tersimpan</span><strong>{summary.assessed}</strong></article>
                  <article><span>Kelas dengan nilai tersimpan</span><strong>{summary.classes}</strong></article>
                  <article><span>Komponen dengan nilai tersimpan</span><strong>{summary.components}</strong></article>
                </>
              ) : (
                <>
                  <article><span>Siswa pada slot yang terdata</span><strong>{summary.students}</strong></article>
                  <article><span>Slot penilaian terdata</span><strong>{summary.slots}</strong></article>
                  <article><span>Sudah dinilai</span><strong className="is-success">{summary.assessed}</strong></article>
                  <article><span>Belum ada nilai</span><strong className="is-warning">{summary.pending}</strong></article>
                </>
              )}
            </div>
            <p className="kepala-grades__integrity-note">
              {historical
                ? 'Riwayat tidak bisa dipakai untuk menyimpulkan kelengkapan nilai setiap siswa. Anggota kelas historis tidak diasumsikan sama dengan anggota kelas saat ini.'
                : 'Nilai 0 adalah skor tercatat yang sah. Kolom “Belum ada nilai” berarti slot dari anggota kelas aktif dan komponen yang terdata, bukan berarti guru tidak mengajar atau nilai rapor 0.'}
            </p>

            {availableRows.length === 0 ? (
              <EmptyState
                title="Tidak ada data sesuai filter"
                message={studentSearch.trim()
                  ? 'Nama atau NISN tidak ditemukan dalam data periode dan filter saat ini.'
                  : 'Ubah tingkat, kelas, atau mata pelajaran untuk melihat data lainnya.'}
              />
            ) : (
              <>
                <div className="kepala-grades__table-tools">
                  <span>Geser tabel ke samping untuk melihat seluruh kolom, termasuk Nilai dan Status.</span>
                  <div className="kepala-grades__table-actions" aria-label="Navigasi horizontal tabel nilai">
                    <button type="button" aria-label="Geser tabel nilai ke kiri" title="Geser ke kiri"
                      disabled={!tableScroll.left} onClick={() => scrollGradeTable(-1)}>‹</button>
                    <button type="button" aria-label="Geser tabel nilai ke kanan" title="Geser ke kanan"
                      disabled={!tableScroll.right} onClick={() => scrollGradeTable(1)}>›</button>
                  </div>
                </div>
                <div className="kepala-grades__table-wrap" ref={tableScrollRef}
                  role="region" aria-label="Tabel monitoring penilaian siswa, dapat digeser horizontal"
                  tabIndex={0} onScroll={updateTableScroll}>
                  <table className="kepala-grades__table">
                    <thead>
                      <tr>
                        <th>No.</th>
                        <th>Nama Siswa</th>
                        <th>NISN</th>
                        <th>NIS</th>
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
                          <td className="kepala-grades__identity">{row.nisn}</td>
                          <td className="kepala-grades__identity">{row.nis}</td>
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
                              {historical ? 'Tercatat' : row.assessed ? 'Sudah Dinilai' : 'Belum Dinilai'}
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
