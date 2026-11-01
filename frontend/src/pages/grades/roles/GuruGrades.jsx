import {
  useCallback,
  useEffect,
  useMemo,
  useState,
} from 'react'

import { useAuth } from '../../../context/useAuth'
import { scheduleApi } from '../../../services/scheduleApi'
import { academicYearApi } from '../../../services/academicYearApi'
import { gradeApi } from '../../../services/gradeApi'
import { classMemberApi } from '../../../services/classMemberApi'

import Card from '../../../components/ui/Card'
import Modal from '../../../components/ui/Modal'
import Input from '../../../components/ui/Input'
import Select from '../../../components/ui/Select'
import Button from '../../../components/ui/Button'
import Table from '../../../components/ui/Table'
import LoadingState from '../../../components/ui/LoadingState'
import ErrorState from '../../../components/ui/ErrorState'
import EmptyState from '../../../components/ui/EmptyState'

import '../styles/GuruGrades.css'

const INITIAL_FORM = {
  nama: '',
  bobot: '',
}

/* =========================================
   HELPERS
========================================= */

function getCollection(response) {
  const payload = response?.data

  if (Array.isArray(payload)) {
    return payload
  }

  if (Array.isArray(payload?.data)) {
    return payload.data
  }

  return []
}

function getMeta(response) {
  return response?.data?.meta || null
}

async function getAllPages(fetchPage) {
  const allData = []
  let page = 1
  let lastPage = 1

  do {
    const response = await fetchPage(page)
    allData.push(...getCollection(response))

    const meta = getMeta(response)
    lastPage = Number(meta?.last_page || 1)
    page += 1
  } while (page <= lastPage)

  return allData
}

function getErrorMessage(error) {
  const validationErrors = error?.validationErrors || {}

  const firstError = Object.values(validationErrors)
    .flat()
    .find(Boolean)

  if (firstError) {
    return String(firstError)
  }

  if (error?.status === 403) {
    return 'Anda tidak memiliki izin untuk melakukan tindakan ini.'
  }

  if (error?.status === 401) {
    return 'Sesi Anda telah berakhir. Silakan masuk kembali.'
  }

  if (error?.status === 404) {
    return 'Data yang diminta tidak ditemukan.'
  }

  if (error?.status >= 500) {
    return 'Terjadi kesalahan pada server. Silakan coba lagi.'
  }

  return error?.message || 'Terjadi kesalahan. Silakan coba lagi.'
}

function formatSchedule(schedule) {
  const gradeLevel = schedule.kelas?.tingkat ?? '-'
  const className = schedule.kelas?.nama || 'Kelas tidak diketahui'
  const subjectName =
    schedule.mapel?.nama || 'Mata pelajaran tidak diketahui'

  const day = schedule.hari || '-'
  const start = schedule.jam_mulai?.slice(0, 5) || '--:--'
  const end = schedule.jam_selesai?.slice(0, 5) || '--:--'
  const semester = schedule.tahun_akademik?.semester || '-'

  return `Tingkat ${gradeLevel} — ${className} — ${subjectName} | ${day}, ${start}-${end} | Semester ${semester}`
}

function getStudentFromMember(member) {
  return member?.siswa || null
}

function createGradeRow(student, existingGrade = null) {
  return {
    studentId: student.id,
    gradeId: existingGrade?.id ?? null,
    nilai: existingGrade?.nilai ?? '',
    catatan: existingGrade?.catatan ?? '',
    saving: false,
    error: '',
    saved: false,
  }
}

/* =========================================
   MAIN COMPONENT
========================================= */

export default function GuruGrades() {
  const { user, assignments = [] } = useAuth()

  /* ---------------------------------------
     JADWAL
  --------------------------------------- */

  const [schedules, setSchedules] = useState([])
  const [selectedScheduleId, setSelectedScheduleId] = useState('')
  const [activeAcademicYear, setActiveAcademicYear] = useState(null)
  const [scheduleLoading, setScheduleLoading] = useState(true)
  const [scheduleError, setScheduleError] = useState('')

  /* ---------------------------------------
     KOMPONEN PENILAIAN
  --------------------------------------- */

  const [components, setComponents] = useState([])
  const [selectedComponentId, setSelectedComponentId] = useState('')
  const [componentLoading, setComponentLoading] = useState(false)
  const [componentError, setComponentError] = useState('')
  const [componentReloadKey, setComponentReloadKey] = useState(0)

  /* ---------------------------------------
     FORM KOMPONEN
  --------------------------------------- */

  const [formOpen, setFormOpen] = useState(false)
  const [editingComponent, setEditingComponent] = useState(null)
  const [form, setForm] = useState(INITIAL_FORM)
  const [formError, setFormError] = useState('')
  const [formLoading, setFormLoading] = useState(false)

  /* ---------------------------------------
     HAPUS KOMPONEN
  --------------------------------------- */

  const [deleteTarget, setDeleteTarget] = useState(null)
  const [deleteLoading, setDeleteLoading] = useState(false)
  const [deleteError, setDeleteError] = useState('')

  /* ---------------------------------------
     INPUT NILAI SISWA
  --------------------------------------- */

  const [students, setStudents] = useState([])
  const [gradeRows, setGradeRows] = useState({})
  const [studentLoading, setStudentLoading] = useState(false)
  const [studentError, setStudentError] = useState('')
  const [gradeReloadKey, setGradeReloadKey] = useState(0)

  /* ---------------------------------------
     IDENTITAS DAN HAK AKSES
  --------------------------------------- */

  const isWaliKelas = assignments.some(
    (assignment) => assignment.type === 'WALI_KELAS',
  )

  const guruId = user?.guru?.id

  const selectedSchedule = useMemo(
    () =>
      schedules.find(
        (schedule) =>
          String(schedule.id) === String(selectedScheduleId),
      ) || null,
    [schedules, selectedScheduleId],
  )

  // Hak pengelolaan mengikuti kepemilikan jadwal di backend.
  const canManageGrades = Boolean(
    selectedSchedule &&
    guruId &&
    String(selectedSchedule.guru_id) === String(guruId),
  )

  const scheduleOptions = useMemo(
    () =>
      schedules.map((schedule) => ({
        value: String(schedule.id),
        label: formatSchedule(schedule),
      })),
    [schedules],
  )

  const selectedComponent = useMemo(
    () =>
      components.find(
        (component) =>
          String(component.id) === String(selectedComponentId),
      ) || null,
    [components, selectedComponentId],
  )

  const componentOptions = useMemo(
    () =>
      components.map((component) => ({
        value: String(component.id),
        label: `${component.nama} — Bobot ${component.bobot}`,
      })),
    [components],
  )

  /* =========================================
     LOAD JADWAL
  ========================================= */

  const loadSchedules = useCallback(async () => {
    setScheduleLoading(true)
    setScheduleError('')
    setSchedules([])
    setSelectedScheduleId('')
    setActiveAcademicYear(null)

    try {
      const academicYears = await getAllPages((page) =>
        academicYearApi.getAll({
          page,
          per_page: 100,
        }),
      )

      const activeYear = academicYears.find(
        (year) => year.is_active === true,
      )

      if (!activeYear) {
        setActiveAcademicYear(null)
        setSchedules([])
        setSelectedScheduleId('')
        return
      }

      setActiveAcademicYear(activeYear)

      const scheduleData = await getAllPages((page) =>
        scheduleApi.getAll({
          academic_year_id: activeYear.id,
          per_page: 100,
          page,
        }),
      )

      setSchedules(scheduleData)
      setSelectedScheduleId(
        scheduleData.length ? String(scheduleData[0].id) : '',
      )
    } catch (error) {
      setSchedules([])
      setSelectedScheduleId('')
      setActiveAcademicYear(null)
      setScheduleError(getErrorMessage(error))
    } finally {
      setScheduleLoading(false)
    }
  }, [])

  useEffect(() => {
    loadSchedules()
  }, [loadSchedules])

  /* =========================================
     LOAD KOMPONEN
  ========================================= */

  useEffect(() => {
    let cancelled = false

    async function loadComponents() {
      if (!selectedScheduleId) {
        setComponents([])
        setSelectedComponentId('')
        setComponentError('')
        setComponentLoading(false)
        return
      }

      setComponents([])
      setSelectedComponentId('')
      setComponentError('')
      setComponentLoading(true)

      try {
        const response = await gradeApi.getComponents({
          schedule_id: selectedScheduleId,
          per_page: 100,
        })

        const data = getCollection(response)

        if (cancelled) return

        setComponents(data)

        setSelectedComponentId(
          data.length ? String(data[0].id) : '',
        )
      } catch (error) {
        if (!cancelled) {
          setComponents([])
          setSelectedComponentId('')
          setComponentError(getErrorMessage(error))
        }
      } finally {
        if (!cancelled) {
          setComponentLoading(false)
        }
      }
    }

    loadComponents()

    return () => {
      cancelled = true
    }
  }, [selectedScheduleId, componentReloadKey])

  const reloadComponents = useCallback(() => {
    setComponentReloadKey((current) => current + 1)
  }, [])

  /* =========================================
     LOAD SISWA DAN NILAI
  ========================================= */

  useEffect(() => {
    let cancelled = false

    async function loadStudentsAndGrades() {
      if (!selectedSchedule || !selectedComponentId) {
        setStudents([])
        setGradeRows({})
        setStudentError('')
        setStudentLoading(false)
        return
      }

      const classId = selectedSchedule.kelas_id

      if (!classId) {
        setStudents([])
        setGradeRows({})
        setStudentError('Jadwal tidak memiliki informasi kelas.')
        setStudentLoading(false)
        return
      }

      setStudents([])
      setGradeRows({})
      setStudentError('')
      setStudentLoading(true)

      try {
        const [memberData, gradeData] = await Promise.all([
          getAllPages((page) =>
            classMemberApi.getAll(classId, {
              page,
              per_page: 100,
            }),
          ),

          getAllPages((page) =>
            gradeApi.getGrades({
              schedule_id: selectedScheduleId,
              component_id: selectedComponentId,
              per_page: 100,
              page,
            }),
          ),
        ])

        if (cancelled) return

        const studentData = memberData
          .map(getStudentFromMember)
          .filter((student) => student?.id)

        const gradesByStudent = new Map(
          gradeData.map((grade) => [
            String(grade.siswa_id),
            grade,
          ]),
        )

        const nextRows = {}

        studentData.forEach((student) => {
          const existingGrade = gradesByStudent.get(
            String(student.id),
          )

          nextRows[student.id] = createGradeRow(
            student,
            existingGrade,
          )
        })

        setStudents(studentData)
        setGradeRows(nextRows)
      } catch (error) {
        if (!cancelled) {
          setStudents([])
          setGradeRows({})
          setStudentError(getErrorMessage(error))
        }
      } finally {
        if (!cancelled) {
          setStudentLoading(false)
        }
      }
    }

    loadStudentsAndGrades()

    return () => {
      cancelled = true
    }
  }, [
    selectedSchedule,
    selectedScheduleId,
    selectedComponentId,
    gradeReloadKey,
  ])

  const reloadGrades = useCallback(() => {
    setGradeReloadKey((current) => current + 1)
  }, [])

  /* =========================================
     FORM KOMPONEN
  ========================================= */

  function openCreateModal() {
    if (!canManageGrades) return

    setEditingComponent(null)
    setForm(INITIAL_FORM)
    setFormError('')
    setFormOpen(true)
  }

  function openEditModal(component) {
    if (!canManageGrades) return

    setEditingComponent(component)

    setForm({
      nama: component.nama || '',
      bobot: String(component.bobot ?? ''),
    })

    setFormError('')
    setFormOpen(true)
  }

  function closeFormModal() {
    if (formLoading) return

    setFormOpen(false)
    setEditingComponent(null)
    setForm(INITIAL_FORM)
    setFormError('')
  }

  function handleFormChange(event) {
    const { name, value } = event.target

    setForm((current) => ({
      ...current,
      [name]: value,
    }))

    setFormError('')
  }

  async function handleComponentSubmit(event) {
    event.preventDefault()

    if (!canManageGrades) {
      setFormError(
        'Jadwal ini tidak dapat dikelola oleh akun Anda.',
      )
      return
    }

    const nama = form.nama.trim()
    const bobot = Number(form.bobot)

    if (!nama) {
      setFormError('Nama komponen wajib diisi.')
      return
    }

    if (nama.length > 100) {
      setFormError('Nama komponen maksimal 100 karakter.')
      return
    }

    if (
      form.bobot.trim() === '' ||
      !Number.isFinite(bobot)
    ) {
      setFormError('Bobot harus berupa angka yang valid.')
      return
    }

    setFormLoading(true)
    setFormError('')

    try {
      if (editingComponent) {
        await gradeApi.updateComponent(editingComponent.id, {
          nama,
          bobot,
        })
      } else {
        await gradeApi.createComponent({
          jadwal_pelajaran_id: Number(selectedScheduleId),
          nama,
          bobot,
        })
      }

      setFormOpen(false)
      setEditingComponent(null)
      setForm(INITIAL_FORM)

      reloadComponents()
    } catch (error) {
      setFormError(getErrorMessage(error))
    } finally {
      setFormLoading(false)
    }
  }

  /* =========================================
     HAPUS KOMPONEN
  ========================================= */

  function openDeleteModal(component) {
    if (!canManageGrades) return

    setDeleteTarget(component)
    setDeleteError('')
  }

  function closeDeleteModal() {
    if (deleteLoading) return

    setDeleteTarget(null)
    setDeleteError('')
  }

  async function handleDelete() {
    if (!deleteTarget || !canManageGrades) return

    setDeleteLoading(true)
    setDeleteError('')

    try {
      await gradeApi.deleteComponent(deleteTarget.id)

      setDeleteTarget(null)
      reloadComponents()
    } catch (error) {
      setDeleteError(getErrorMessage(error))
    } finally {
      setDeleteLoading(false)
    }
  }

  /* =========================================
     INPUT NILAI
  ========================================= */

  function handleGradeChange(studentId, field, value) {
    setGradeRows((current) => ({
      ...current,
      [studentId]: {
        ...current[studentId],
        [field]: value,
        error: '',
        saved: false,
      },
    }))
  }

  async function handleSaveGrade(student) {
    if (!canManageGrades || !selectedComponentId) return

    const row = gradeRows[student.id]

    if (!row) return

    const rawValue = String(row.nilai).trim()
    const nilai = Number(rawValue)

    if (rawValue === '' || !Number.isFinite(nilai)) {
      setGradeRows((current) => ({
        ...current,
        [student.id]: {
          ...current[student.id],
          error: 'Nilai wajib diisi dengan angka yang valid.',
          saved: false,
        },
      }))
      return
    }

    setGradeRows((current) => ({
      ...current,
      [student.id]: {
        ...current[student.id],
        saving: true,
        error: '',
        saved: false,
      },
    }))

    const catatan = String(row.catatan ?? '').trim()

    try {
      if (row.gradeId) {
        // UpdateGradeRequest hanya menerima nilai dan catatan.
        await gradeApi.updateGrade(row.gradeId, {
          nilai,
          catatan: catatan || null,
        })
      } else {
        // Grade baru membutuhkan komponen dan siswa.
        await gradeApi.createGrade({
          komponen_nilai_id: Number(selectedComponentId),
          siswa_id: Number(student.id),
          nilai,
          catatan: catatan || null,
        })
      }

      // Muat ulang agar gradeId dari server tersimpan di state.
      reloadGrades()
    } catch (error) {
      setGradeRows((current) => ({
        ...current,
        [student.id]: {
          ...current[student.id],
          saving: false,
          error: getErrorMessage(error),
          saved: false,
        },
      }))
    }
  }

  /* =========================================
     KOLOM TABEL KOMPONEN
  ========================================= */

  const componentColumns = useMemo(() => {
    const columns = [
      {
        key: 'nama',
        label: 'Nama Komponen',
      },
      {
        key: 'bobot',
        label: 'Bobot',
      },
    ]

    if (canManageGrades) {
      columns.push({
        key: 'actions',
        label: 'Aksi',
        className: 'guru-grades__action-column',
      })
    }

    return columns
  }, [canManageGrades])

  function renderComponentCell(row, column) {
    if (column.key === 'bobot') {
      return row.bobot ?? '-'
    }

    if (column.key === 'actions') {
      return (
        <div className="guru-grades__actions">
          <Button
            size="small"
            variant="secondary"
            onClick={() => openEditModal(row)}
          >
            Edit
          </Button>

          <Button
            size="small"
            variant="danger"
            onClick={() => openDeleteModal(row)}
          >
            Hapus
          </Button>
        </div>
      )
    }

    return row[column.key] ?? '-'
  }

  /* =========================================
     KOLOM TABEL NILAI
  ========================================= */

  const gradeColumns = useMemo(() => {
    const columns = [
      {
        key: 'number',
        label: 'No.',
        className: 'guru-grades__number-column',
      },
      {
        key: 'nis',
        label: 'NIS',
      },
      {
        key: 'nama',
        label: 'Nama Siswa',
      },
      {
        key: 'nilai',
        label: 'Nilai',
        className: 'guru-grades__grade-column',
      },
      {
        key: 'catatan',
        label: 'Catatan',
        className: 'guru-grades__note-column',
      },
    ]

    if (canManageGrades) {
      columns.push({
        key: 'actions',
        label: 'Aksi',
        className: 'guru-grades__grade-action-column',
      })
    }

    return columns
  }, [canManageGrades])

  function renderGradeCell(student, column) {
    const row = gradeRows[student.id]

    if (!row) return '-'

    if (column.key === 'number') {
      return students.findIndex(
        (item) => item.id === student.id,
      ) + 1
    }

    if (column.key === 'nis') {
      return student.nis || student.nisn || '-'
    }

    if (column.key === 'nama') {
      return (
        <div className="guru-grades__student-name">
          <strong>{student.nama_lengkap || '-'}</strong>

          {row.gradeId && (
            <span className="guru-grades__grade-status">
              Nilai tersimpan
            </span>
          )}
        </div>
      )
    }

    if (column.key === 'nilai') {
      return (
        <div className="guru-grades__grade-input">
          <Input
            id={`grade-value-${student.id}`}
            name={`nilai-${student.id}`}
            type="number"
            value={row.nilai}
            placeholder="Nilai"
            disabled={!canManageGrades || row.saving}
            inputMode="decimal"
            onChange={(event) =>
              handleGradeChange(
                student.id,
                'nilai',
                event.target.value,
              )
            }
          />
        </div>
      )
    }

    if (column.key === 'catatan') {
      return (
        <div className="guru-grades__note-input">
          <Input
            id={`grade-note-${student.id}`}
            name={`catatan-${student.id}`}
            value={row.catatan}
            placeholder="Catatan (opsional)"
            disabled={!canManageGrades || row.saving}
            onChange={(event) =>
              handleGradeChange(
                student.id,
                'catatan',
                event.target.value,
              )
            }
          />
        </div>
      )
    }

    if (column.key === 'actions') {
      return (
        <div className="guru-grades__grade-actions">
          <Button
            size="small"
            onClick={() => handleSaveGrade(student)}
            loading={row.saving}
            disabled={!canManageGrades || row.saving}
          >
            {row.gradeId ? 'Perbarui' : 'Simpan'}
          </Button>

          {row.error && (
            <span
              className="guru-grades__row-error"
              role="alert"
            >
              {row.error}
            </span>
          )}
        </div>
      )
    }

    return '-'
  }

  /* =========================================
     RENDER
  ========================================= */

  return (
    <section className="guru-grades">
      <header className="guru-grades__header">
        <div>
          <h1 className="guru-grades__title">
            Penilaian
          </h1>

          <p className="guru-grades__description">
            Kelola komponen penilaian dan nilai siswa
            berdasarkan jadwal pembelajaran.
          </p>
        </div>
      </header>

      {/* INFORMASI PENGGUNA */}
      <Card
        title="Informasi Pengguna"
        description="Informasi akun dan penugasan yang terdeteksi."
      >
        <div className="guru-grades__identity">
          <div>
            <span className="guru-grades__label">
              Nama Guru
            </span>

            <strong>
              {user?.guru?.nama_lengkap || user?.name || '-'}
            </strong>
          </div>

          <div>
            <span className="guru-grades__label">
              Penugasan
            </span>

            <strong>
              {isWaliKelas
                ? 'Guru Mata Pelajaran dan Wali Kelas'
                : 'Guru Mata Pelajaran'}
            </strong>
          </div>
        </div>
      </Card>

      {/* JADWAL */}
      <Card
        title="Jadwal Pembelajaran"
        description="Pilih jadwal yang tersedia untuk akun Anda."
      >
        {scheduleLoading ? (
          <LoadingState message="Memuat jadwal pembelajaran..." />
        ) : scheduleError ? (
          <ErrorState
            title="Gagal memuat jadwal"
            message={scheduleError}
            onRetry={loadSchedules}
          />
        ) : schedules.length === 0 ? (
          <EmptyState
            title={
              !activeAcademicYear
                ? 'Tahun akademik aktif belum tersedia'
                : 'Belum ada jadwal pembelajaran'
            }
            message={
              !activeAcademicYear
                ? 'Silakan hubungi Tata Usaha untuk mengaktifkan tahun akademik.'
                : `Tidak ditemukan jadwal pembelajaran untuk akun Anda pada tahun akademik ${activeAcademicYear.tahun_mulai}/${activeAcademicYear.tahun_selesai}.`
            }
          />
        ) : (
          <div className="guru-grades__schedule">
            <Select
              id="grade-schedule"
              name="schedule_id"
              label="Jadwal Pembelajaran"
              value={selectedScheduleId}
              options={scheduleOptions}
              placeholder="Pilih jadwal pembelajaran"
              onChange={(event) =>
                setSelectedScheduleId(event.target.value)
              }
            />

            {selectedSchedule && (
              <div className="guru-grades__schedule-detail">
                <div>
                  <span className="guru-grades__label">
                    Tingkat
                  </span>
                  <strong>
                    {selectedSchedule.kelas?.tingkat ?? '-'}
                  </strong>
                </div>

                <div>
                  <span className="guru-grades__label">
                    Nama Kelas
                  </span>
                  <strong>
                    {selectedSchedule.kelas?.nama || '-'}
                  </strong>
                </div>

                <div>
                  <span className="guru-grades__label">
                    Mata Pelajaran
                  </span>
                  <strong>
                    {selectedSchedule.mapel?.nama || '-'}
                  </strong>
                </div>

                <div>
                  <span className="guru-grades__label">
                    Guru Pengampu
                  </span>
                  <strong>
                    {selectedSchedule.guru?.nama_lengkap || '-'}
                  </strong>
                </div>

                <div>
                  <span className="guru-grades__label">
                    Tahun Akademik
                  </span>
                  <strong>
                    {selectedSchedule.tahun_akademik?.tahun_mulai ?? '-'}
                    {' / '}
                    {selectedSchedule.tahun_akademik?.tahun_selesai ?? '-'}
                  </strong>
                </div>

                <div>
                  <span className="guru-grades__label">
                    Semester
                  </span>
                  <strong>
                    {selectedSchedule.tahun_akademik?.semester || '-'}
                  </strong>
                </div>

                <div>
                  <span className="guru-grades__label">
                    KKM
                  </span>
                  <strong>
                    {selectedSchedule.mapel?.kkm ?? '-'}
                  </strong>
                </div>
              </div>
            )}
          </div>
        )}
      </Card>

      {/* KOMPONEN PENILAIAN */}
      {selectedSchedule && !scheduleLoading && !scheduleError && (
        <Card
          title="Komponen Penilaian"
          description="Daftar komponen penilaian berdasarkan jadwal yang dipilih."
          actions={
            canManageGrades ? (
              <Button onClick={openCreateModal}>
                + Tambah Komponen
              </Button>
            ) : null
          }
        >
          {!canManageGrades && (
            <div className="guru-grades__readonly-notice">
              Jadwal ini hanya dapat dilihat. Pengelolaan komponen
              dan nilai hanya tersedia untuk guru pengampu jadwal.
            </div>
          )}

          {componentLoading ? (
            <LoadingState message="Memuat komponen penilaian..." />
          ) : componentError ? (
            <ErrorState
              title="Gagal memuat komponen"
              message={componentError}
              onRetry={reloadComponents}
            />
          ) : (
            <Table
              columns={componentColumns}
              data={components}
              rowKey="id"
              emptyMessage="Belum ada komponen penilaian untuk jadwal ini."
              renderCell={renderComponentCell}
            />
          )}
        </Card>
      )}

      {/* INPUT NILAI SISWA */}
      {selectedSchedule && !scheduleLoading && !scheduleError && (
        <Card
          title="Input Nilai Siswa"
          description="Pilih komponen penilaian untuk menampilkan daftar siswa dan mengisi nilai."
        >
          {componentLoading ? (
            <LoadingState message="Menyiapkan komponen penilaian..." />
          ) : componentError ? (
            <ErrorState
              title="Komponen tidak tersedia"
              message={componentError}
              onRetry={reloadComponents}
            />
          ) : components.length === 0 ? (
            <EmptyState
              title="Komponen belum tersedia"
              message="Buat komponen penilaian terlebih dahulu sebelum menginput nilai siswa."
            />
          ) : (
            <div className="guru-grades__grade-section">
              <Select
                id="grade-component"
                name="component_id"
                label="Komponen Penilaian"
                value={selectedComponentId}
                options={componentOptions}
                placeholder="Pilih komponen penilaian"
                onChange={(event) =>
                  setSelectedComponentId(event.target.value)
                }
              />

              {selectedComponent && (
                <div className="guru-grades__selected-component">
                  <div>
                    <span className="guru-grades__label">
                      Komponen
                    </span>
                    <strong>{selectedComponent.nama}</strong>
                  </div>

                  <div>
                    <span className="guru-grades__label">
                      Bobot
                    </span>
                    <strong>{selectedComponent.bobot}</strong>
                  </div>

                  <div>
                    <span className="guru-grades__label">
                      Jumlah Siswa
                    </span>
                    <strong>
                      {studentLoading ? '...' : students.length}
                    </strong>
                  </div>
                </div>
              )}

              {!canManageGrades && (
                <div className="guru-grades__readonly-notice">
                  Anda hanya dapat melihat nilai pada jadwal ini.
                  Input dan perubahan nilai hanya dapat dilakukan
                  oleh guru pengampu.
                </div>
              )}

              {studentLoading ? (
                <LoadingState message="Memuat daftar siswa dan nilai..." />
              ) : studentError ? (
                <ErrorState
                  title="Gagal memuat daftar siswa atau nilai"
                  message={studentError}
                  onRetry={reloadGrades}
                />
              ) : students.length === 0 ? (
                <EmptyState
                  title="Belum ada siswa"
                  message="Tidak ditemukan anggota kelas aktif pada kelas untuk jadwal ini."
                />
              ) : (
                <>
                  <div className="guru-grades__table-info">
                    <span>
                      Menampilkan {students.length} siswa aktif.
                    </span>

                    <Button
                      variant="secondary"
                      size="small"
                      onClick={reloadGrades}
                    >
                      Muat Ulang
                    </Button>
                  </div>

                  <Table
                    columns={gradeColumns}
                    data={students}
                    rowKey="id"
                    emptyMessage="Belum ada siswa."
                    renderCell={renderGradeCell}
                  />
                </>
              )}
            </div>
          )}
        </Card>
      )}

      {/* MODAL TAMBAH DAN EDIT KOMPONEN */}
      <Modal
        open={formOpen}
        title={
          editingComponent
            ? 'Edit Komponen Penilaian'
            : 'Tambah Komponen Penilaian'
        }
        description={
          editingComponent
            ? 'Perbarui nama dan bobot komponen penilaian.'
            : 'Masukkan nama dan bobot komponen penilaian baru.'
        }
        onClose={closeFormModal}
        size="medium"
        footer={
          <>
            <Button
              variant="secondary"
              onClick={closeFormModal}
              disabled={formLoading}
            >
              Batal
            </Button>

            <Button
              type="submit"
              form="grade-component-form"
              loading={formLoading}
              disabled={!canManageGrades}
            >
              {editingComponent ? 'Simpan Perubahan' : 'Simpan'}
            </Button>
          </>
        }
      >
        <form
          id="grade-component-form"
          className="guru-grades__form"
          onSubmit={handleComponentSubmit}
        >
          {formError && (
            <div className="guru-grades__form-error" role="alert">
              {formError}
            </div>
          )}

          <Input
            id="component-name"
            name="nama"
            label="Nama Komponen"
            value={form.nama}
            placeholder="Contoh: Tugas Harian"
            maxLength={100}
            required
            disabled={formLoading}
            onChange={handleFormChange}
            hint="Maksimal 100 karakter."
          />

          <Input
            id="component-weight"
            name="bobot"
            label="Bobot"
            type="number"
            value={form.bobot}
            placeholder="Masukkan bobot"
            required
            disabled={formLoading}
            inputMode="decimal"
            onChange={handleFormChange}
            hint="Masukkan nilai numerik sesuai ketentuan sekolah."
          />
        </form>
      </Modal>

      {/* MODAL HAPUS KOMPONEN */}
      <Modal
        open={Boolean(deleteTarget)}
        title="Hapus Komponen Penilaian"
        description="Tindakan ini akan menghapus komponen penilaian yang dipilih."
        onClose={closeDeleteModal}
        size="small"
        footer={
          <>
            <Button
              variant="secondary"
              onClick={closeDeleteModal}
              disabled={deleteLoading}
            >
              Batal
            </Button>

            <Button
              variant="danger"
              onClick={handleDelete}
              loading={deleteLoading}
            >
              Hapus Komponen
            </Button>
          </>
        }
      >
        {deleteError && (
          <div className="guru-grades__form-error" role="alert">
            {deleteError}
          </div>
        )}

        <p>
          Apakah kamu yakin ingin menghapus komponen{' '}
          <strong>{deleteTarget?.nama}</strong>?
        </p>
      </Modal>
    </section>
  )
}