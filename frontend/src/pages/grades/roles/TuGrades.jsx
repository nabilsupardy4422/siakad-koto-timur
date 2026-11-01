
import { useCallback, useEffect, useMemo, useState } from 'react'

import Card from '../../../components/ui/Card'
import Table from '../../../components/ui/Table'
import Select from '../../../components/ui/Select'
import LoadingState from '../../../components/ui/LoadingState'
import ErrorState from '../../../components/ui/ErrorState'
import EmptyState from '../../../components/ui/EmptyState'

import { gradeApi } from '../../../services/gradeApi'
import { academicYearApi } from '../../../services/academicYearApi'
import { classApi } from '../../../services/classApi'
import { scheduleApi } from '../../../services/scheduleApi'
import { classMemberApi } from '../../../services/classMemberApi'

import '../../../pages/grades/styles/TuGrades.css'

const PAGE_SIZE = 20
const DATA_PAGE_SIZE = 100

const columns = [
  { key: 'number', label: 'No.', className: 'tu-grades__number' },
  { key: 'student', label: 'Nama Siswa' },
  { key: 'nis', label: 'NIS / NISN' },
  { key: 'class', label: 'Kelas' },
  { key: 'level', label: 'Tingkat' },
  { key: 'subject', label: 'Mata Pelajaran' },
  { key: 'component', label: 'Komponen Penilaian' },
  { key: 'weight', label: 'Bobot' },
  { key: 'score', label: 'Nilai' },
  { key: 'note', label: 'Catatan' },
  { key: 'status', label: 'Status' },
]

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

  return {
    data: [],
    meta: {},
  }
}

async function getAllPages(fetchPage, perPage = DATA_PAGE_SIZE) {
  let page = 1
  let lastPage = 1
  const result = []

  do {
    const response = await fetchPage({
      page,
      per_page: perPage,
    })

    const collection = getCollection(response)

    result.push(...collection.data)

    lastPage = Number(collection.meta.last_page ?? 1)
    page += 1
  } while (page <= lastPage)

  return result
}

function formatAcademicYear(year) {
  if (!year) return '-'

  return `${year.tahun_mulai ?? '?'} / ${year.tahun_selesai ?? '?'}`
}

function formatClassName(classItem) {
  if (!classItem) return '-'

  return classItem.nama ?? classItem.nama_kelas ?? `Kelas ${classItem.id}`
}

function formatScore(value) {
  if (value === null || value === undefined || value === '') {
    return '-'
  }

  const number = Number(value)

  if (!Number.isFinite(number)) return value

  return number.toLocaleString('id-ID', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
  })
}

function getScheduleSubject(schedule) {
  return schedule?.mapel ?? schedule?.mata_pelajaran ?? null
}

function getGradeKey(componentId, studentId) {
  return `${Number(componentId)}-${Number(studentId)}`
}

export default function TuGrades() {
  const [filters, setFilters] = useState({
    academic_year_id: '',
    semester: '',
    tingkat: '',
    class_id: '',
    subject_id: '',
  })

  const [academicYears, setAcademicYears] = useState([])
  const [classes, setClasses] = useState([])
  const [schedules, setSchedules] = useState([])

  const [rows, setRows] = useState([])

  const [lookupLoading, setLookupLoading] = useState(true)
  const [scheduleLoading, setScheduleLoading] = useState(false)
  const [gradesLoading, setGradesLoading] = useState(false)

  const [lookupError, setLookupError] = useState('')
  const [scheduleError, setScheduleError] = useState('')
  const [gradesError, setGradesError] = useState('')

  const [pagination, setPagination] = useState({
    current_page: 1,
  })

  // =========================================================
  // LOOKUP DATA
  // =========================================================

  const loadLookups = useCallback(async () => {
    setLookupLoading(true)
    setLookupError('')

    try {
      const [yearResponse, classResponse] = await Promise.all([
        academicYearApi.getAll({ per_page: 100 }),
        classApi.getAll({ per_page: 100 }),
      ])

      setAcademicYears(getCollection(yearResponse).data)
      setClasses(getCollection(classResponse).data)
    } catch (error) {
      setLookupError(
        error?.message || 'Data filter penilaian gagal dimuat.',
      )
    } finally {
      setLookupLoading(false)
    }
  }, [])

  useEffect(() => {
    loadLookups()
  }, [loadLookups])

  // =========================================================
  // FILTER OPTIONS
  // =========================================================

  const filteredClasses = useMemo(() => {
    return classes.filter((classItem) => {
      const academicYear = academicYears.find(
        (year) =>
          Number(year.id) === Number(classItem.tahun_akademik_id),
      )

      if (
        filters.academic_year_id &&
        Number(classItem.tahun_akademik_id) !==
          Number(filters.academic_year_id)
      ) {
        return false
      }

      if (
        filters.semester &&
        academicYear?.semester !== filters.semester
      ) {
        return false
      }

      if (
        filters.tingkat &&
        Number(classItem.tingkat) !== Number(filters.tingkat)
      ) {
        return false
      }

      return true
    })
  }, [
    classes,
    academicYears,
    filters.academic_year_id,
    filters.semester,
    filters.tingkat,
  ])

  const levelOptions = useMemo(() => {
    const levels = [
      ...new Set(
        classes
          .filter((classItem) => {
            const year = academicYears.find(
              (item) =>
                Number(item.id) ===
                Number(classItem.tahun_akademik_id),
            )

            if (
              filters.academic_year_id &&
              Number(classItem.tahun_akademik_id) !==
                Number(filters.academic_year_id)
            ) {
              return false
            }

            if (
              filters.semester &&
              year?.semester !== filters.semester
            ) {
              return false
            }

            return classItem.tingkat !== null &&
              classItem.tingkat !== undefined
          })
          .map((item) => Number(item.tingkat)),
      ),
    ].sort((a, b) => a - b)

    return levels.map((level) => ({
      value: String(level),
      label: `Tingkat ${level}`,
    }))
  }, [
    classes,
    academicYears,
    filters.academic_year_id,
    filters.semester,
  ])

  const yearOptions = academicYears.map((year) => ({
    value: String(year.id),
    label: `${formatAcademicYear(year)}${
      year.semester ? ` - ${year.semester}` : ''
    }${year.is_active ? ' (Aktif)' : ''}`,
  }))

  const classOptions = filteredClasses
    .map((item) => ({
      value: String(item.id),
      label: `${formatClassName(item)} - Tingkat ${item.tingkat}`,
    }))
    .sort((a, b) => a.label.localeCompare(b.label, 'id'))

  // =========================================================
  // LOAD SCHEDULES
  // =========================================================

  useEffect(() => {
    let active = true

    async function loadSchedules() {
      setSchedules([])
      setScheduleError('')
      setRows([])

      if (!filters.class_id) {
        setScheduleLoading(false)
        return
      }

      setScheduleLoading(true)

      try {
        const response = await getAllPages((params) =>
          scheduleApi.getAll({
            ...params,
            academic_year_id:
              filters.academic_year_id || undefined,
            semester: filters.semester || undefined,
            class_id: filters.class_id,
          }),
        )

        if (!active) return

        setSchedules(response)
      } catch (error) {
        if (!active) return

        setScheduleError(
          error?.message || 'Jadwal mata pelajaran gagal dimuat.',
        )
      } finally {
        if (active) setScheduleLoading(false)
      }
    }

    loadSchedules()

    return () => {
      active = false
    }
  }, [
    filters.class_id,
    filters.academic_year_id,
    filters.semester,
  ])

  // =========================================================
  // SUBJECT OPTIONS FROM CLASS SCHEDULES
  // =========================================================

  const availableSubjects = useMemo(() => {
    const subjectMap = new Map()

    schedules.forEach((schedule) => {
      const subject = getScheduleSubject(schedule)

      if (!subject?.id) return

      const id = Number(subject.id)

      if (!subjectMap.has(id)) {
        subjectMap.set(id, {
          id,
          nama: subject.nama ?? subject.kode ?? `Mapel ${id}`,
          kode: subject.kode ?? '',
        })
      }
    })

    return [...subjectMap.values()].sort((a, b) =>
      a.nama.localeCompare(b.nama, 'id'),
    )
  }, [schedules])

  const subjectOptions = availableSubjects.map((subject) => ({
    value: String(subject.id),
    label: subject.kode
      ? `${subject.nama} (${subject.kode})`
      : subject.nama,
  }))

  const selectedSchedules = useMemo(() => {
    if (!filters.subject_id) return schedules

    return schedules.filter((schedule) => {
      const subject = getScheduleSubject(schedule)

      return Number(subject?.id) === Number(filters.subject_id)
    })
  }, [schedules, filters.subject_id])

  // =========================================================
  // LOAD STUDENTS + COMPONENTS + GRADES
  // =========================================================

  const loadGradeData = useCallback(async () => {
    if (!filters.class_id) {
      setRows([])
      setGradesError('')
      return
    }

    if (scheduleLoading) return

    if (selectedSchedules.length === 0) {
      setRows([])
      setGradesError('')
      return
    }

    setGradesLoading(true)
    setGradesError('')
    setRows([])

    try {
      // Ambil seluruh anggota aktif kelas.
      const students = await getAllPages((params) =>
        classMemberApi.getAll(filters.class_id, params),
      )

      const activeStudents = students
        .filter((member) => !member.tanggal_selesai)
        .map((member) => member.siswa)
        .filter((student) => student?.id)

      // Ambil komponen dan nilai untuk setiap jadwal yang sesuai.
      const scheduleResults = await Promise.all(
        selectedSchedules.map(async (schedule) => {
          const [componentResponse, gradeResponse] =
            await Promise.all([
              getAllPages((params) =>
                gradeApi.getComponents({
                  ...params,
                  schedule_id: schedule.id,
                  academic_year_id:
                    filters.academic_year_id || undefined,
                  semester: filters.semester || undefined,
                }),
              ),

              getAllPages((params) =>
                gradeApi.getGrades({
                  ...params,
                  schedule_id: schedule.id,
                  class_id: filters.class_id,
                  subject_id: filters.subject_id || undefined,
                  academic_year_id:
                    filters.academic_year_id || undefined,
                  semester: filters.semester || undefined,
                }),
              ),
            ])

          return {
            schedule,
            components: componentResponse,
            grades: gradeResponse,
          }
        }),
      )

      // Cocokkan nilai berdasarkan komponen dan siswa.
      const gradeMap = new Map()

      scheduleResults.forEach((result) => {
        result.grades.forEach((grade) => {
          gradeMap.set(
            getGradeKey(
              grade.komponen_nilai_id,
              grade.siswa_id,
            ),
            grade,
          )
        })
      })

      // Buat baris untuk setiap siswa pada setiap komponen.
      // Jika belum ada nilai, tetap buat baris dengan status Belum Dinilai.
      const generatedRows = []

      scheduleResults.forEach((result) => {
        const { schedule, components } = result
        const subject = getScheduleSubject(schedule)

        components.forEach((component) => {
          activeStudents.forEach((student) => {
            const existingGrade = gradeMap.get(
              getGradeKey(component.id, student.id),
            )

            generatedRows.push({
              id: `${schedule.id}-${component.id}-${student.id}`,
              student,
              schedule,
              component,
              grade: existingGrade ?? null,
              className: formatClassName(schedule.kelas),
              subjectName: subject?.nama ?? '-',
            })
          })
        })
      })

      generatedRows.sort((a, b) => {
        const studentComparison = (
          a.student.nama_lengkap ?? ''
        ).localeCompare(
          b.student.nama_lengkap ?? '',
          'id',
        )

        if (studentComparison !== 0) return studentComparison

        return (a.component.nama ?? '').localeCompare(
          b.component.nama ?? '',
          'id',
        )
      })

      setRows(generatedRows)
      setPagination({ current_page: 1 })
    } catch (error) {
      setRows([])
      setGradesError(
        error?.message || 'Data rekap penilaian gagal dimuat.',
      )
    } finally {
      setGradesLoading(false)
    }
  }, [
    filters.class_id,
    filters.academic_year_id,
    filters.semester,
    filters.subject_id,
    selectedSchedules,
    scheduleLoading,
  ])

  useEffect(() => {
    loadGradeData()
  }, [loadGradeData])

  // =========================================================
  // FILTER HANDLERS
  // =========================================================

  const handleFilterChange = (name, value) => {
    setPagination({ current_page: 1 })

    setFilters((previous) => {
      const next = {
        ...previous,
        [name]: value,
      }

      if (name === 'academic_year_id' || name === 'semester') {
        next.tingkat = ''
        next.class_id = ''
        next.subject_id = ''
      }

      if (name === 'tingkat') {
        next.class_id = ''
        next.subject_id = ''
      }

      if (name === 'class_id') {
        next.subject_id = ''
      }

      return next
    })
  }

  const handleReset = () => {
    setFilters({
      academic_year_id: '',
      semester: '',
      tingkat: '',
      class_id: '',
      subject_id: '',
    })

    setPagination({ current_page: 1 })
  }

  // =========================================================
  // SUMMARY AND PAGINATION
  // =========================================================

  const totalStudents = useMemo(() => {
    return new Set(
      rows.map((row) => Number(row.student.id)),
    ).size
  }, [rows])

  const assessedCount = rows.filter((row) => row.grade).length
  const unassessedCount = rows.length - assessedCount

  const lastPage = Math.max(
    1,
    Math.ceil(rows.length / PAGE_SIZE),
  )

  const currentPage = Math.min(
    pagination.current_page,
    lastPage,
  )

  const paginatedRows = rows.slice(
    (currentPage - 1) * PAGE_SIZE,
    currentPage * PAGE_SIZE,
  )

  const changePage = (nextPage) => {
    if (nextPage < 1 || nextPage > lastPage) return

    setPagination({ current_page: nextPage })
  }

  // =========================================================
  // TABLE CELL
  // =========================================================

  const renderCell = (row, column) => {
    const grade = row.grade
    const student = row.student

    switch (column.key) {
      case 'number':
        return (
          (currentPage - 1) * PAGE_SIZE +
          paginatedRows.indexOf(row) +
          1
        )

      case 'student':
        return <strong>{student.nama_lengkap ?? '-'}</strong>

      case 'nis':
        return student.nis || student.nisn || '-'

      case 'class':
        return row.className

      case 'level':
        return `Tingkat ${row.schedule.kelas?.tingkat ?? '-'}`

      case 'subject':
        return row.subjectName

      case 'component':
        return row.component.nama ?? '-'

      case 'weight':
        return `${formatScore(row.component.bobot)}%`

      case 'score':
        return grade ? (
          <strong className="tu-grades__score">
            {formatScore(grade.nilai)}
          </strong>
        ) : (
          <span className="tu-grades__unassessed">-</span>
        )

      case 'note':
        return grade?.catatan || '-'

      case 'status':
        return grade ? (
          <span className="tu-grades__status tu-grades__status--complete">
            Sudah Dinilai
          </span>
        ) : (
          <span className="tu-grades__status tu-grades__status--pending">
            Belum Dinilai
          </span>
        )

      default:
        return '-'
    }
  }

  // =========================================================
  // LOADING AND ERROR
  // =========================================================

  if (lookupLoading) {
    return (
      <LoadingState message="Memuat pilihan filter penilaian..." />
    )
  }

  if (lookupError) {
    return (
      <ErrorState
        title="Filter penilaian gagal dimuat"
        message={lookupError}
        onRetry={loadLookups}
      />
    )
  }

  // =========================================================
  // RENDER
  // =========================================================

  return (
    <div className="tu-grades">
      <header className="tu-grades__header">
        <div>
          <h1 className="tu-grades__title">Rekap Penilaian</h1>

          <p className="tu-grades__description">
            Monitoring nilai siswa berdasarkan tahun akademik,
            semester, tingkat, kelas, dan mata pelajaran.
          </p>
        </div>
      </header>

      <Card
        title="Filter Penilaian"
        description="Pilih periode, tingkat, dan kelas. Mata pelajaran akan mengikuti jadwal kelas yang dipilih."
      >
        <div className="tu-grades__filters">
          <Select
            id="grade-academic-year"
            label="Tahun Akademik"
            value={filters.academic_year_id}
            options={yearOptions}
            placeholder="Semua tahun akademik"
            onChange={(event) =>
              handleFilterChange(
                'academic_year_id',
                event.target.value,
              )
            }
          />

          <Select
            id="grade-semester"
            label="Semester"
            value={filters.semester}
            options={[
              { value: 'GANJIL', label: 'Ganjil' },
              { value: 'GENAP', label: 'Genap' },
            ]}
            placeholder="Semua semester"
            onChange={(event) =>
              handleFilterChange('semester', event.target.value)
            }
          />

          <Select
            id="grade-level"
            label="Tingkat"
            value={filters.tingkat}
            options={levelOptions}
            placeholder="Semua tingkat"
            onChange={(event) =>
              handleFilterChange('tingkat', event.target.value)
            }
          />

          <Select
            id="grade-class"
            label="Kelas"
            value={filters.class_id}
            options={classOptions}
            placeholder="Pilih kelas"
            onChange={(event) =>
              handleFilterChange('class_id', event.target.value)
            }
          />

          <Select
            id="grade-subject"
            label="Mata Pelajaran"
            value={filters.subject_id}
            options={subjectOptions}
            placeholder={
              !filters.class_id
                ? 'Pilih kelas terlebih dahulu'
                : scheduleLoading
                  ? 'Memuat mata pelajaran...'
                  : 'Semua mata pelajaran'
            }
            onChange={(event) =>
              handleFilterChange('subject_id', event.target.value)
            }
          />
        </div>

        {scheduleError && (
          <div className="tu-grades__inline-error">
            {scheduleError}
          </div>
        )}

        {scheduleLoading && (
          <p className="tu-grades__filter-info">
            Memuat jadwal mata pelajaran kelas...
          </p>
        )}

        {filters.class_id &&
          !scheduleLoading &&
          !scheduleError && (
            <p className="tu-grades__filter-info">
              {availableSubjects.length > 0
                ? `${availableSubjects.length} mata pelajaran tersedia berdasarkan jadwal kelas.`
                : 'Belum ada jadwal mata pelajaran untuk kombinasi filter ini.'}
            </p>
          )}

        <div className="tu-grades__filter-actions">
          <button
            type="button"
            className="tu-grades__button tu-grades__button--secondary"
            onClick={handleReset}
          >
            Reset Filter
          </button>
        </div>
      </Card>

      <Card
        title="Data Nilai Siswa"
        description="Siswa yang belum mendapatkan nilai tetap ditampilkan."
      >
        {!filters.class_id ? (
          <EmptyState
            title="Pilih kelas terlebih dahulu"
            message="Tentukan tahun akademik, semester, tingkat, dan kelas untuk melihat rekap penilaian."
          />
        ) : scheduleLoading || gradesLoading ? (
          <LoadingState message="Memuat data siswa dan rekap penilaian..." />
        ) : scheduleError ? (
          <ErrorState
            title="Jadwal gagal dimuat"
            message={scheduleError}
          />
        ) : gradesError ? (
          <ErrorState
            title="Rekap nilai gagal dimuat"
            message={gradesError}
            onRetry={loadGradeData}
          />
        ) : rows.length === 0 ? (
          <EmptyState
            title="Belum ada komponen penilaian"
            message="Tidak ditemukan komponen penilaian pada jadwal kelas dan mata pelajaran yang dipilih."
          />
        ) : (
          <>
            <div className="tu-grades__summary">
              <div>
                <span>Total Siswa</span>
                <strong>{totalStudents}</strong>
              </div>

              <div>
                <span>Total Data Penilaian</span>
                <strong>{rows.length}</strong>
              </div>

              <div>
                <span>Sudah Dinilai</span>
                <strong>{assessedCount}</strong>
              </div>

              <div>
                <span>Belum Dinilai</span>
                <strong>{unassessedCount}</strong>
              </div>
            </div>

            <div className="tu-grades__table-wrapper">
              <Table
                columns={columns}
                data={paginatedRows}
                renderCell={renderCell}
                emptyMessage="Tidak ada data penilaian."
              />
            </div>

            <div className="tu-grades__pagination">
              <span>
                Menampilkan {paginatedRows.length} dari {rows.length}{' '}
                baris penilaian.
              </span>

              <div className="tu-grades__pagination-actions">
                <button
                  type="button"
                  className="tu-grades__button tu-grades__button--secondary"
                  disabled={currentPage <= 1}
                  onClick={() => changePage(currentPage - 1)}
                >
                  Sebelumnya
                </button>

                <span className="tu-grades__page-info">
                  Halaman {currentPage} / {lastPage}
                </span>

                <button
                  type="button"
                  className="tu-grades__button tu-grades__button--primary"
                  disabled={currentPage >= lastPage}
                  onClick={() => changePage(currentPage + 1)}
                >
                  Berikutnya
                </button>
              </div>
            </div>
          </>
        )}
      </Card>
    </div>
  )
}
