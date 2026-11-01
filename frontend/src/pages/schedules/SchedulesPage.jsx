import { useCallback, useEffect, useState } from 'react'

import { useAuth } from '../../context/useAuth'

import PageHeader from '../../components/ui/PageHeader'
import Card from '../../components/ui/Card'
import Table from '../../components/ui/Table'
import Select from '../../components/ui/Select'
import Input from '../../components/ui/Input'
import Button from '../../components/ui/Button'
import Modal from '../../components/ui/Modal'
import EmptyState from '../../components/ui/EmptyState'
import ErrorState from '../../components/ui/ErrorState'
import LoadingState from '../../components/ui/LoadingState'

import { scheduleApi } from '../../services/scheduleApi'
import { academicYearApi } from '../../services/academicYearApi'
import { classApi } from '../../services/classApi'
import { teacherApi } from '../../services/teacherApi'
import { subjectApi } from '../../services/subjectApi'

import './SchedulesPage.css'

const initialForm = {
  tahun_akademik_id: '',
  kelas_id: '',
  mapel_id: '',
  guru_id: '',
  hari: '',
  jam_mulai: '',
  jam_selesai: '',
}

const initialFilters = {
  academic_year_id: '',
  semester: '',
  class_id: '',
  subject_id: '',
  teacher_id: '',
  day: '',
}

const DAYS = [
  { value: 'SENIN', label: 'Senin' },
  { value: 'SELASA', label: 'Selasa' },
  { value: 'RABU', label: 'Rabu' },
  { value: 'KAMIS', label: 'Kamis' },
  { value: 'JUMAT', label: 'Jumat' },
  { value: 'SABTU', label: 'Sabtu' },
  { value: 'MINGGU', label: 'Minggu' },
]

const SEMESTERS = [
  { value: 'GANJIL', label: 'Ganjil' },
  { value: 'GENAP', label: 'Genap' },
]

const columns = [
  { key: 'day', label: 'Hari' },
  { key: 'time', label: 'Waktu' },
  { key: 'class', label: 'Kelas' },
  { key: 'subject', label: 'Mata Pelajaran' },
  { key: 'teacher', label: 'Guru' },
  { key: 'semester', label: 'Semester' },
  { key: 'actions', label: 'Aksi' },
]

function fieldError(errors, field) {
  const value = errors?.[field]
  return Array.isArray(value) ? value[0] || '' : value || ''
}

function apiMessage(error, fallback) {
  return (
    error?.response?.data?.message ||
    error?.data?.message ||
    error?.message ||
    fallback
  )
}

function relationId(item, field, relation) {
  return item?.[field] ?? item?.[relation]?.id ?? '-'
}

function yearLabel(year) {
  return `${year.tahun_mulai}/${year.tahun_selesai}`
}

function teacherLabel(teacher) {
  return (
    teacher?.nama_lengkap ||
    teacher?.user?.name ||
    teacher?.nip ||
    `Guru #${teacher?.id}`
  )
}

function subjectLabel(subject) {
  if (!subject) return '-'

  return subject.kode
    ? `${subject.kode} - ${subject.nama}`
    : subject.nama || `Mapel #${subject.id}`
}

function classLabel(item) {
  if (!item) return 'Kelas tidak diketahui'

  const name = item.nama || item.nama_kelas || `Kelas #${item.id}`
  const level = item.tingkat

  return level !== null && level !== undefined && level !== ''
    ? `${name} — Tingkat ${level}`
    : name
}

function displayTime(value) {
  return value ? String(value).slice(0, 5) : '-'
}

function getRoleCode(role) {
  return typeof role === 'string'
    ? role
    : role?.code || role?.name || null
}

export default function SchedulesPage() {
  const { role, permissions = [] } = useAuth()

  const roleCode = getRoleCode(role)
  const canManage =
    roleCode === 'TU' || permissions.includes('schedules.create')

  const [schedules, setSchedules] = useState([])
  const [years, setYears] = useState([])
  const [classes, setClasses] = useState([])
  const [teachers, setTeachers] = useState([])
  const [subjects, setSubjects] = useState([])

  const [filters, setFilters] = useState(initialFilters)
  const [page, setPage] = useState(1)

  const [meta, setMeta] = useState({
    current_page: 1,
    last_page: 1,
    per_page: 20,
    total: 0,
  })

  const [loading, setLoading] = useState(true)
  const [optionsLoading, setOptionsLoading] = useState(true)
  const [listError, setListError] = useState(null)
  const [optionsError, setOptionsError] = useState(null)

  const [modalOpen, setModalOpen] = useState(false)
  const [editingSchedule, setEditingSchedule] = useState(null)
  const [scheduleToDelete, setScheduleToDelete] = useState(null)

  const [form, setForm] = useState(initialForm)
  const [fieldErrors, setFieldErrors] = useState({})
  const [submitError, setSubmitError] = useState(null)
  const [saving, setSaving] = useState(false)
  const [deletingId, setDeletingId] = useState(null)
  const [successMessage, setSuccessMessage] = useState('')

  const loadOptions = useCallback(async () => {
    setOptionsLoading(true)
    setOptionsError(null)

    try {
      const [
        yearResponse,
        classResponse,
        teacherResponse,
        subjectResponse,
      ] = await Promise.all([
        academicYearApi.getAll({ per_page: 100 }),
        classApi.getAll({ per_page: 100 }),
        teacherApi.getAll({ per_page: 100 }),
        subjectApi.getAll({ per_page: 100 }),
      ])

      setYears(Array.isArray(yearResponse?.data) ? yearResponse.data : [])
      setClasses(Array.isArray(classResponse?.data) ? classResponse.data : [])
      setTeachers(
        Array.isArray(teacherResponse?.data) ? teacherResponse.data : [],
      )
      setSubjects(
        Array.isArray(subjectResponse?.data) ? subjectResponse.data : [],
      )
    } catch (error) {
      setOptionsError(error)
    } finally {
      setOptionsLoading(false)
    }
  }, [])

  const loadSchedules = useCallback(async () => {
    setLoading(true)
    setListError(null)

    try {
      const response = await scheduleApi.getAll({
        ...filters,
        page,
        per_page: 20,
      })

      setSchedules(Array.isArray(response?.data) ? response.data : [])

      setMeta((current) => ({
        ...current,
        ...(response?.meta || {}),
      }))
    } catch (error) {
      setListError(error)
    } finally {
      setLoading(false)
    }
  }, [filters, page])

  useEffect(() => {
    loadOptions()
  }, [loadOptions])

  useEffect(() => {
    loadSchedules()
  }, [loadSchedules])

  function resetForm() {
    setForm(initialForm)
    setFieldErrors({})
    setSubmitError(null)
    setEditingSchedule(null)
  }

  function openCreateModal() {
    resetForm()
    setSuccessMessage('')

    setForm({
      ...initialForm,
      tahun_akademik_id: filters.academic_year_id,
    })

    setModalOpen(true)
  }

  function openEditModal(item) {
    resetForm()
    setSuccessMessage('')
    setEditingSchedule(item)

    const yearId = relationId(item, 'tahun_akademik_id', 'tahun_akademik')
    const classId = relationId(item, 'kelas_id', 'kelas')
    const subjectId = relationId(item, 'mapel_id', 'mapel')
    const teacherId = relationId(item, 'guru_id', 'guru')

    setForm({
      tahun_akademik_id: yearId === '-' ? '' : String(yearId),
      kelas_id: classId === '-' ? '' : String(classId),
      mapel_id: subjectId === '-' ? '' : String(subjectId),
      guru_id: teacherId === '-' ? '' : String(teacherId),
      hari: item.hari || 'SENIN',
      jam_mulai:
        displayTime(item.jam_mulai) === '-'
          ? ''
          : displayTime(item.jam_mulai),
      jam_selesai:
        displayTime(item.jam_selesai) === '-'
          ? ''
          : displayTime(item.jam_selesai),
    })

    setModalOpen(true)
  }

  function closeModal() {
    if (saving) return

    setModalOpen(false)
    resetForm()
  }

  function handleFormChange(event) {
    const { name, value } = event.target

    setForm((current) => ({
      ...current,
      [name]: value,
    }))

    setFieldErrors((current) => ({
      ...current,
      [name]: '',
    }))

    setSubmitError(null)
  }

  function setFormField(name, value) {
    handleFormChange({ target: { name, value } })
  }

  function handleFilterChange(name, value) {
    setFilters((current) => ({
      ...current,
      [name]: value,
    }))

    setPage(1)
    setSuccessMessage('')
  }

  function resetFilters() {
    setFilters(initialFilters)
    setPage(1)
    setSuccessMessage('')
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setFieldErrors({})
    setSubmitError(null)

    const errors = {}

    if (!form.tahun_akademik_id) {
      errors.tahun_akademik_id = 'Tahun akademik wajib dipilih.'
    }

    if (!form.kelas_id) {
      errors.kelas_id = 'Kelas wajib dipilih.'
    }

    if (!form.mapel_id) {
      errors.mapel_id = 'Mata pelajaran wajib dipilih.'
    }

    if (!form.guru_id) {
      errors.guru_id = 'Guru wajib dipilih.'
    }


    if (!form.hari) {
      errors.hari = 'Hari wajib dipilih.'
    }

    if (!form.jam_mulai) {
      errors.jam_mulai = 'Jam mulai wajib diisi.'
    }

    if (!form.jam_selesai) {
      errors.jam_selesai = 'Jam selesai wajib diisi.'
    }

    if (
      form.jam_mulai &&
      form.jam_selesai &&
      form.jam_mulai >= form.jam_selesai
    ) {
      errors.jam_selesai =
        'Jam selesai harus lebih besar dari jam mulai.'
    }

    if (Object.keys(errors).length > 0) {
      setFieldErrors(errors)
      return
    }

    const payload = {
      tahun_akademik_id: Number(form.tahun_akademik_id),
      kelas_id: Number(form.kelas_id),
      mapel_id: Number(form.mapel_id),
      guru_id: Number(form.guru_id),
      hari: form.hari,
      jam_mulai: form.jam_mulai,
      jam_selesai: form.jam_selesai,
    }

    setSaving(true)

    try {
      if (editingSchedule) {
        await scheduleApi.update(editingSchedule.id, payload)
        setSuccessMessage('Jadwal berhasil diperbarui.')
      } else {
        await scheduleApi.create(payload)
        setSuccessMessage('Jadwal berhasil ditambahkan.')
      }

      setModalOpen(false)
      resetForm()
      await loadSchedules()
    } catch (error) {
      const responseErrors =
        error?.response?.data?.errors ||
        error?.data?.errors ||
        error?.errors ||
        {}

      setFieldErrors(responseErrors)
      setSubmitError(error)
    } finally {
      setSaving(false)
    }
  }

  async function handleDelete() {
    if (!scheduleToDelete) return

    const target = scheduleToDelete

    setDeletingId(target.id)
    setListError(null)

    try {
      await scheduleApi.delete(target.id)

      setSuccessMessage('Jadwal berhasil dihapus.')
      setScheduleToDelete(null)

      if (schedules.length === 1 && page > 1) {
        setPage((current) => current - 1)
      } else {
        await loadSchedules()
      }
    } catch (error) {
      setListError(error)
      setScheduleToDelete(null)
    } finally {
      setDeletingId(null)
    }
  }

  function getScheduleYear(item) {
    const relation = item.tahun_akademik || item.tahunAkademik

    if (relation) return yearLabel(relation)

    const id = relationId(item, 'tahun_akademik_id', 'tahun_akademik')
    const year = years.find((entry) => String(entry.id) === String(id))

    return year ? yearLabel(year) : `Tahun #${id}`
  }

  return (
    <div className="schedules-page">
      <PageHeader
        eyebrow="KEGIATAN AKADEMIK"
        title="Jadwal Pelajaran"
        description="Kelola jadwal pembelajaran berdasarkan tahun akademik, semester, kelas, mata pelajaran, dan guru."
        actions={
          canManage ? (
            <Button
              type="button"
              onClick={openCreateModal}
              disabled={optionsLoading}
            >
              + Tambah Jadwal
            </Button>
          ) : null
        }
      />

      {successMessage && (
        <div className="schedules-page__success" role="status">
          <span aria-hidden="true">✓</span>
          {successMessage}
          <button
            type="button"
            aria-label="Tutup pesan"
            onClick={() => setSuccessMessage('')}
          >
            ×
          </button>
        </div>
      )}

      {optionsError && (
        <ErrorState
          message={apiMessage(
            optionsError,
            'Pilihan data jadwal gagal dimuat.',
          )}
          onRetry={loadOptions}
        />
      )}

      <Card>
        <div className="schedules-page__section-heading">
          <div>
            <span className="schedules-page__eyebrow">
              PENCARIAN DATA
            </span>
            <h2>Filter Jadwal</h2>
            <p>
              Saring jadwal berdasarkan konteks akademik yang dibutuhkan.
            </p>
          </div>
        </div>

        <div className="schedules-page__filters">
          <Select
            label="Tahun Akademik"
            name="academic_year_id"
            value={filters.academic_year_id}
            placeholder="Semua tahun akademik"
            onChange={(event) =>
              handleFilterChange('academic_year_id', event.target.value)
            }
            options={years.map((year) => ({
                value: String(year.id),
                label: yearLabel(year),
              }))}
          />

          <Select
            label="Semester"
            name="semester"
            value={filters.semester}
            placeholder="Semua semester"
            onChange={(event) =>
              handleFilterChange('semester', event.target.value)
            }
            options={SEMESTERS}
          />

          <Select
            label="Kelas"
            name="class_id"
            value={filters.class_id}
            placeholder="Semua kelas"
            onChange={(event) =>
              handleFilterChange('class_id', event.target.value)
            }
            options={classes.map((item) => ({
                value: String(item.id),
                label: classLabel(item),
              }))}
          />

          <Select
            label="Mata Pelajaran"
            name="subject_id"
            value={filters.subject_id}
            placeholder="Semua mata pelajaran"
            onChange={(event) =>
              handleFilterChange('subject_id', event.target.value)
            }
            options={subjects.map((item) => ({
                value: String(item.id),
                label: subjectLabel(item),
              }))}
          />

          <Select
            label="Guru"
            name="teacher_id"
            value={filters.teacher_id}
            placeholder="Semua guru"
            onChange={(event) =>
              handleFilterChange('teacher_id', event.target.value)
            }
            options={teachers.map((item) => ({
                value: String(item.id),
                label: teacherLabel(item),
              }))}
          />

          <Select
            label="Hari"
            name="day"
            value={filters.day}
            placeholder="Semua hari"
            onChange={(event) =>
              handleFilterChange('day', event.target.value)
            }
            options={DAYS}
          />
        </div>

        <div className="schedules-page__filter-actions">
          <Button type="button" onClick={resetFilters}>
            Reset Filter
          </Button>

          <Button
            type="button"
            onClick={loadSchedules}
            disabled={loading}
          >
            {loading ? 'Memuat...' : 'Muat Ulang'}
          </Button>
        </div>
      </Card>

      <Card>
        <div className="schedules-page__list-heading">
          <div>
            <span className="schedules-page__eyebrow">
              DATA PEMBELAJARAN
            </span>
            <h2>Daftar Jadwal</h2>
            <p>
              {loading
                ? 'Memuat data jadwal...'
                : `${meta.total} jadwal ditemukan`}
            </p>
          </div>
        </div>

        {listError && (
          <ErrorState
            message={apiMessage(
              listError,
              'Data jadwal gagal dimuat.',
            )}
            onRetry={loadSchedules}
          />
        )}

        {loading && (
          <LoadingState message="Memuat jadwal pelajaran..." />
        )}

        {!loading && !listError && schedules.length === 0 && (
          <EmptyState
            title="Belum ada jadwal"
            description="Tidak ada jadwal yang cocok dengan filter saat ini. Coba ubah filter atau tambahkan jadwal baru."
          />
        )}

        {!loading && !listError && schedules.length > 0 && (
          <>
            <Table
              columns={columns}
              data={schedules}
              rowKey="id"
              renderCell={(item, column) => {
                switch (column.key) {
                  case 'day':
                    return (
                      <span className="schedules-page__day">
                        {DAYS.find((day) => day.value === item.hari)
                          ?.label || item.hari || '-'}
                      </span>
                    )

                  case 'time':
                    return (
                      <span className="schedules-page__time">
                        {displayTime(item.jam_mulai)} –{' '}
                        {displayTime(item.jam_selesai)}
                      </span>
                    )

                  case 'class': {
                    const classItem = item.kelas || item.class

                    if (!classItem) {
                      return `Kelas #${relationId(item, 'kelas_id', 'kelas')}`
                    }

                    return (
                      <div className="schedules-page__class">
                        <span className="schedules-page__class-name">
                          {classItem.nama || classItem.nama_kelas || 'Kelas'}
                        </span>

                        {classItem.tingkat !== null &&
                          classItem.tingkat !== undefined &&
                          classItem.tingkat !== '' && (
                            <span className="schedules-page__class-level">
                              Tingkat {classItem.tingkat}
                            </span>
                          )}
                      </div>
                    )
                  }

                  case 'subject': {
                    const subject = item.mapel || item.subject

                    return subject
                      ? subjectLabel(subject)
                      : `Mapel #${relationId(item, 'mapel_id', 'mapel')}`
                  }

                  case 'teacher':
                    return teacherLabel(item.guru || item.teacher)

                  case 'semester': {
                    const semester =
                      item.tahun_akademik?.semester ||
                      item.tahunAkademik?.semester ||
                      '-'

                    return (
                      <span className="schedules-page__semester">
                        {semester === 'GENAP'
                          ? 'Genap'
                          : semester === 'GANJIL'
                            ? 'Ganjil'
                            : semester}
                      </span>
                    )
                  }

                  case 'actions':
                    return canManage ? (
                      <div className="schedules-page__row-actions">
                        <Button
                          type="button"
                          onClick={() => openEditModal(item)}
                          disabled={deletingId !== null}
                        >
                          Edit
                        </Button>

                        <Button
                          type="button"
                          disabled={deletingId !== null}
                          loading={
                            String(deletingId) === String(item.id)
                          }
                          onClick={() => setScheduleToDelete(item)}
                        >
                          Hapus
                        </Button>
                      </div>
                    ) : (
                      '-'
                    )

                  default:
                    return '-'
                }
              }}
            />

            <div className="schedules-page__pagination">
              <span>
                Halaman {meta.current_page || page} dari{' '}
                {meta.last_page || 1}
              </span>

              <div>
                <Button
                  type="button"
                  disabled={page <= 1 || loading}
                  onClick={() =>
                    setPage((current) => Math.max(1, current - 1))
                  }
                >
                  Sebelumnya
                </Button>

                <Button
                  type="button"
                  disabled={
                    page >= (meta.last_page || 1) || loading
                  }
                  onClick={() => setPage((current) => current + 1)}
                >
                  Berikutnya
                </Button>
              </div>
            </div>
          </>
        )}
      </Card>

      <Modal
        open={modalOpen}
        title={editingSchedule ? 'Edit Jadwal' : 'Tambah Jadwal'}
        description="Lengkapi informasi akademik dan waktu pembelajaran."
        onClose={closeModal}
        size="large"
        footer={
          <>
            <Button
              type="button"
              disabled={saving}
              onClick={closeModal}
            >
              Batal
            </Button>

            <Button
              type="submit"
              form="schedule-form"
              loading={saving}
            >
              {editingSchedule ? 'Simpan Perubahan' : 'Simpan Jadwal'}
            </Button>
          </>
        }
      >
        {submitError && (
          <div className="schedules-page__form-error">
            <ErrorState
              message={apiMessage(
                submitError,
                'Jadwal gagal disimpan. Periksa data dan kemungkinan bentrok jadwal.',
              )}
            />
          </div>
        )}

        {optionsLoading && (
          <LoadingState message="Memuat pilihan data..." />
        )}

        {!optionsLoading && !optionsError && (
          <form
            id="schedule-form"
            className="schedules-page__form"
            onSubmit={handleSubmit}
          >
            <Select
              label="Tahun Akademik"
              name="tahun_akademik_id"
              value={form.tahun_akademik_id}
              placeholder="Pilih tahun akademik"
              onChange={(event) => {
                const value = event.target.value
                setFormField('tahun_akademik_id', value)
                setFormField('kelas_id', '')
              }}
              options={years.map((year) => ({
                  value: String(year.id),
                  label: yearLabel(year),
                }))}
              error={fieldError(fieldErrors, 'tahun_akademik_id')}
            />

            <Select
              label="Kelas"
              name="kelas_id"
              value={form.kelas_id}
              placeholder="Pilih kelas"
              onChange={(event) => setFormField('kelas_id', event.target.value)}
              options={classes
                  .filter(
                    (item) =>
                      !form.tahun_akademik_id ||
                      String(item.tahun_akademik_id) ===
                        String(form.tahun_akademik_id),
                  )
                  .map((item) => ({
                    value: String(item.id),
                    label: classLabel(item),
                  }))}
              error={fieldError(fieldErrors, 'kelas_id')}
            />

            <Select
              label="Mata Pelajaran"
              name="mapel_id"
              value={form.mapel_id}
              placeholder="Pilih mata pelajaran"
              onChange={(event) => setFormField('mapel_id', event.target.value)}
              options={subjects.map((item) => ({
                  value: String(item.id),
                  label: subjectLabel(item),
                }))}
              error={fieldError(fieldErrors, 'mapel_id')}
            />

            <Select
              label="Guru Pengajar"
              name="guru_id"
              value={form.guru_id}
              placeholder="Pilih guru"
              onChange={(event) => setFormField('guru_id', event.target.value)}
              options={teachers.map((item) => ({
                  value: String(item.id),
                  label: teacherLabel(item),
                }))}
              error={fieldError(fieldErrors, 'guru_id')}
            />

            <Select
              label="Hari"
              name="hari"
              value={form.hari}
              placeholder="Pilih hari"
              onChange={(event) => setFormField('hari', event.target.value)}
              options={DAYS}
              error={fieldError(fieldErrors, 'hari')}
            />

            <Input
              label="Jam Mulai"
              name="jam_mulai"
              type="time"
              value={form.jam_mulai}
              onChange={handleFormChange}
              error={fieldError(fieldErrors, 'jam_mulai')}
            />

            <Input
              label="Jam Selesai"
              name="jam_selesai"
              type="time"
              value={form.jam_selesai}
              onChange={handleFormChange}
              error={fieldError(fieldErrors, 'jam_selesai')}
            />
          </form>
        )}
      </Modal>

      <Modal
        open={Boolean(scheduleToDelete)}
        title="Konfirmasi Hapus Jadwal"
        description="Jadwal yang dihapus tidak dapat ditampilkan lagi."
        onClose={() => {
          if (deletingId === null) setScheduleToDelete(null)
        }}
        size="small"
        footer={
          <>
            <Button
              type="button"
              disabled={deletingId !== null}
              onClick={() => setScheduleToDelete(null)}
            >
              Batal
            </Button>

            <Button
              type="button"
              loading={deletingId !== null}
              onClick={handleDelete}
            >
              Ya, Hapus
            </Button>
          </>
        }
      >
        {scheduleToDelete && (
          <p>
            Yakin ingin menghapus jadwal{' '}
            <strong>
              {scheduleToDelete.mapel?.nama ||
                scheduleToDelete.subject?.nama ||
                `Mapel #${relationId(
                  scheduleToDelete,
                  'mapel_id',
                  'mapel',
                )}`}
            </strong>{' '}
            pada hari <strong>{scheduleToDelete.hari || '-'}</strong>,
            pukul{' '}
            <strong>
              {displayTime(scheduleToDelete.jam_mulai)} –{' '}
              {displayTime(scheduleToDelete.jam_selesai)}
            </strong>
            ?
          </p>
        )}
      </Modal>
    </div>
  )
}