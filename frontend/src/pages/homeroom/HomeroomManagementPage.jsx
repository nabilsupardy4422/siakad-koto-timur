
import { useCallback, useEffect, useState } from 'react'

import PageHeader from '../../components/ui/PageHeader'
import Card from '../../components/ui/Card'
import Table from '../../components/ui/Table'
import Select from '../../components/ui/Select'
import Button from '../../components/ui/Button'
import Badge from '../../components/ui/Badge'
import Modal from '../../components/ui/Modal'
import EmptyState from '../../components/ui/EmptyState'
import ErrorState from '../../components/ui/ErrorState'
import LoadingState from '../../components/ui/LoadingState'
import Input from '../../components/ui/Input'

import { homeroomApi } from '../../services/homeroomApi'
import { academicYearApi } from '../../services/academicYearApi'
import { teacherApi } from '../../services/teacherApi'
import { classApi } from '../../services/classApi'

import './HomeroomManagementPage.css'

const initialForm = {
  guru_id: '',
  kelas_id: '',
  tahun_akademik_id: '',
  tanggal_mulai: '',
  tanggal_selesai: '',
}

const columns = [
  { key: 'teacher', label: 'Guru Wali' },
  { key: 'class', label: 'Kelas' },
  { key: 'year', label: 'Tahun Akademik' },
  { key: 'period', label: 'Masa Penugasan' },
  { key: 'status', label: 'Status' },
  { key: 'actions', label: 'Aksi' },
]

function fieldError(errors, field) {
  const value = errors?.[field]
  return Array.isArray(value) ? value[0] || '' : value || ''
}

function apiMessage(error, fallback) {
  return error?.message || fallback
}

function formatDate(value) {
  if (!value) return '-'

  const parts = String(value).slice(0, 10).split('-')

  if (parts.length !== 3) return value

  return `${parts[2]}-${parts[1]}-${parts[0]}`
}

function academicYearLabel(year) {
  return `${year.tahun_mulai}/${year.tahun_selesai} - ${year.semester}`
}

function teacherLabel(teacher) {
  return (
    teacher.nama_lengkap ||
    teacher.user?.name ||
    teacher.nip ||
    `Guru #${teacher.id}`
  )
}

export default function HomeroomManagementPage() {
  const [assignments, setAssignments] = useState([])
  const [years, setYears] = useState([])
  const [teachers, setTeachers] = useState([])
  const [classes, setClasses] = useState([])

  // Daftar kelas khusus untuk formulir.
  const [formClasses, setFormClasses] = useState([])
  const [formClassesLoading, setFormClassesLoading] = useState(false)
  const [formClassesError, setFormClassesError] = useState(null)

  const [filters, setFilters] = useState({
    academic_year_id: '',
    class_id: '',
    teacher_id: '',
  })

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
  const [editingAssignment, setEditingAssignment] = useState(null)
  const [assignmentToEnd, setAssignmentToEnd] = useState(null)

  const [form, setForm] = useState(initialForm)
  const [fieldErrors, setFieldErrors] = useState({})
  const [submitError, setSubmitError] = useState(null)
  const [saving, setSaving] = useState(false)
  const [endingId, setEndingId] = useState(null)
  const [successMessage, setSuccessMessage] = useState('')

  const loadOptions = useCallback(async () => {
    setOptionsLoading(true)
    setOptionsError(null)

    try {
      const [yearResponse, teacherResponse] = await Promise.all([
        academicYearApi.getAll({ per_page: 100 }),
        teacherApi.getAll({ per_page: 100 }),
      ])

      setYears(Array.isArray(yearResponse?.data) ? yearResponse.data : [])
      setTeachers(
        Array.isArray(teacherResponse?.data) ? teacherResponse.data : [],
      )
    } catch (error) {
      setOptionsError(error)
    } finally {
      setOptionsLoading(false)
    }
  }, [])

  // Memuat kelas untuk filter daftar penugasan.
  const loadClasses = useCallback(async () => {
    if (!filters.academic_year_id) {
      setClasses([])
      return
    }

    try {
      const response = await classApi.getAll({
        academic_year_id: filters.academic_year_id,
        per_page: 100,
      })

      setClasses(Array.isArray(response?.data) ? response.data : [])
    } catch (error) {
      setClasses([])
      setListError(error)
    }
  }, [filters.academic_year_id])

  // Memuat kelas sesuai tahun akademik pada formulir.
  const loadFormClasses = useCallback(async () => {
    const academicYearId = form.tahun_akademik_id

    if (!academicYearId) {
      setFormClasses([])
      setFormClassesError(null)
      setFormClassesLoading(false)
      return
    }

    setFormClassesLoading(true)
    setFormClassesError(null)

    try {
      const response = await classApi.getAll({
        academic_year_id: academicYearId,
        per_page: 100,
      })

      setFormClasses(Array.isArray(response?.data) ? response.data : [])
    } catch (error) {
      setFormClasses([])
      setFormClassesError(error)
    } finally {
      setFormClassesLoading(false)
    }
  }, [form.tahun_akademik_id])

  const loadAssignments = useCallback(async () => {
    setLoading(true)
    setListError(null)

    try {
      const response = await homeroomApi.getAll({
        ...filters,
        page,
        per_page: 20,
      })

      setAssignments(Array.isArray(response?.data) ? response.data : [])
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
    loadClasses()
  }, [loadClasses])

  useEffect(() => {
    loadFormClasses()
  }, [loadFormClasses])

  useEffect(() => {
    loadAssignments()
  }, [loadAssignments])

  function changeFilter(name, value) {
    setFilters((current) => ({
      ...current,
      [name]: value,
      ...(name === 'academic_year_id' ? { class_id: '' } : {}),
    }))

    setPage(1)
    setSuccessMessage('')
  }

  function resetFilters() {
    setFilters({
      academic_year_id: '',
      class_id: '',
      teacher_id: '',
    })

    setPage(1)
    setSuccessMessage('')
  }

  function resetForm() {
    setForm(initialForm)
    setFieldErrors({})
    setSubmitError(null)
    setEditingAssignment(null)
    setFormClasses([])
    setFormClassesError(null)
  }

  function closeModal() {
    if (saving) return

    setModalOpen(false)
    resetForm()
  }

  function openCreateModal() {
    resetForm()
    setSuccessMessage('')

    setForm({
      ...initialForm,
      tahun_akademik_id: filters.academic_year_id,
      kelas_id: filters.class_id,
    })

    setModalOpen(true)
  }

  function openEditModal(assignment) {
    resetForm()
    setSuccessMessage('')
    setEditingAssignment(assignment)

    setForm({
      guru_id: String(assignment.guru_id ?? ''),
      kelas_id: String(assignment.kelas_id ?? ''),
      tahun_akademik_id: String(assignment.tahun_akademik_id ?? ''),
      tanggal_mulai: assignment.tanggal_mulai?.slice(0, 10) ?? '',
      tanggal_selesai: assignment.tanggal_selesai?.slice(0, 10) ?? '',
    })

    setModalOpen(true)
  }

  function handleFormChange(event) {
    const { name, value } = event.target

    setForm((current) => ({
      ...current,
      [name]: value,
      ...(name === 'tahun_akademik_id' ? { kelas_id: '' } : {}),
    }))

    setFieldErrors((current) => ({
      ...current,
      [name]: '',
      ...(name === 'tahun_akademik_id' ? { kelas_id: '' } : {}),
    }))

    setSubmitError(null)
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setFieldErrors({})
    setSubmitError(null)

    const errors = {}

    if (!form.guru_id) {
      errors.guru_id = 'Guru wajib dipilih.'
    }

    if (!form.tahun_akademik_id) {
      errors.tahun_akademik_id = 'Tahun akademik wajib dipilih.'
    }

    if (!form.kelas_id) {
      errors.kelas_id = 'Kelas wajib dipilih.'
    }

    if (!form.tanggal_mulai) {
      errors.tanggal_mulai = 'Tanggal mulai wajib diisi.'
    }

    if (
      form.tanggal_selesai &&
      form.tanggal_mulai &&
      form.tanggal_selesai < form.tanggal_mulai
    ) {
      errors.tanggal_selesai =
        'Tanggal selesai tidak boleh sebelum tanggal mulai.'
    }

    if (Object.keys(errors).length > 0) {
      setFieldErrors(errors)
      return
    }

    const payload = {
      guru_id: Number(form.guru_id),
      kelas_id: Number(form.kelas_id),
      tahun_akademik_id: Number(form.tahun_akademik_id),
      tanggal_mulai: form.tanggal_mulai,
      tanggal_selesai: form.tanggal_selesai || null,
    }

    setSaving(true)

    try {
      if (editingAssignment) {
        await homeroomApi.update(editingAssignment.id, payload)
        setSuccessMessage('Penugasan Wali Kelas berhasil diperbarui.')
      } else {
        await homeroomApi.create(payload)
        setSuccessMessage('Penugasan Wali Kelas berhasil ditambahkan.')
      }

      setModalOpen(false)
      resetForm()
      await loadAssignments()
    } catch (error) {
      setFieldErrors(error?.validationErrors || {})
      setSubmitError(error)
    } finally {
      setSaving(false)
    }
  }

  // Tombol Akhiri hanya membuka modal konfirmasi.
  function handleEndAssignment(assignment) {
    setAssignmentToEnd(assignment)
  }

  // API hanya dipanggil setelah pengguna mengonfirmasi.
  async function confirmEndAssignment() {
    if (!assignmentToEnd || endingId !== null) return

    const assignment = assignmentToEnd

    setEndingId(assignment.id)
    setSuccessMessage('')
    setListError(null)

    try {
      await homeroomApi.end(assignment.id)

      setAssignmentToEnd(null)
      setSuccessMessage('Penugasan Wali Kelas berhasil diakhiri.')

      await loadAssignments()
    } catch (error) {
      setListError(error)
    } finally {
      setEndingId(null)
    }
  }

  const yearOptions = years.map((year) => ({
    value: String(year.id),
    label: academicYearLabel(year),
  }))

  const teacherOptions = teachers.map((teacher) => ({
    value: String(teacher.id),
    label: teacherLabel(teacher),
  }))

  const classFilterOptions = classes.map((item) => ({
    value: String(item.id),
    label: `${item.nama} - Tingkat ${item.tingkat}`,
  }))

  const formClassOptions = formClasses.map((item) => ({
    value: String(item.id),
    label: `${item.nama} - Tingkat ${item.tingkat}`,
  }))

  return (
    <section className="homeroom-management-page">
      <PageHeader
        title="Manajemen Wali Kelas"
        description="Kelola penugasan guru sebagai Wali Kelas berdasarkan tahun akademik."
        actions={
          <Button
            type="button"
            onClick={openCreateModal}
            disabled={optionsLoading || Boolean(optionsError)}
          >
            + Tambah Penugasan
          </Button>
        }
      />

      {successMessage && (
        <div
          className="homeroom-management-page__success"
          role="status"
          aria-live="polite"
        >
          {successMessage}
        </div>
      )}

      {optionsError && (
        <ErrorState
          message={apiMessage(
            optionsError,
            'Data pilihan guru dan tahun akademik gagal dimuat.',
          )}
        />
      )}

      <Card>
        <div className="homeroom-management-page__header">
          <div>
            <p className="homeroom-management-page__eyebrow">
              Data akademik
            </p>
            <h2>Daftar Penugasan</h2>
            <p>Penugasan aktif maupun riwayat penugasan Wali Kelas.</p>
          </div>

          <span className="homeroom-management-page__count">
            {meta.total} penugasan
          </span>
        </div>

        <div className="homeroom-management-page__filters">
          <Select
            id="filter-year"
            name="academic_year_id"
            label="Tahun Akademik"
            value={filters.academic_year_id}
            options={yearOptions}
            placeholder="Semua tahun akademik"
            disabled={optionsLoading}
            onChange={(event) =>
              changeFilter('academic_year_id', event.target.value)
            }
          />

          <Select
            id="filter-class"
            name="class_id"
            label="Kelas"
            value={filters.class_id}
            options={classFilterOptions}
            placeholder="Semua kelas"
            disabled={!filters.academic_year_id || classes.length === 0}
            onChange={(event) =>
              changeFilter('class_id', event.target.value)
            }
          />

          <Select
            id="filter-teacher"
            name="teacher_id"
            label="Guru"
            value={filters.teacher_id}
            options={teacherOptions}
            placeholder="Semua guru"
            disabled={optionsLoading}
            onChange={(event) =>
              changeFilter('teacher_id', event.target.value)
            }
          />

          <div className="homeroom-management-page__filter-actions">
            <Button type="button" onClick={resetFilters}>
              Reset Filter
            </Button>
          </div>
        </div>

        {loading && (
          <LoadingState message="Memuat penugasan Wali Kelas..." />
        )}

        {!loading && listError && (
          <ErrorState
            message={apiMessage(
              listError,
              'Data penugasan gagal dimuat.',
            )}
          />
        )}

        {!loading && !listError && assignments.length === 0 && (
          <EmptyState
            title="Belum ada penugasan"
            description="Tidak ada penugasan yang sesuai dengan filter yang dipilih."
          />
        )}

        {!loading && !listError && assignments.length > 0 && (
          <>
            <Table
              columns={columns}
              data={assignments}
              rowKey="id"
              renderCell={(assignment, column) => {
                const ended = Boolean(assignment.tanggal_selesai)

                switch (column.key) {
                  case 'teacher':
                    return (
                      <div className="homeroom-management-page__identity">
                        <strong>
                          {assignment.guru?.nama_lengkap || '-'}
                        </strong>
                        <span>NIP: {assignment.guru?.nip || '-'}</span>
                      </div>
                    )

                  case 'class':
                    return (
                      <div className="homeroom-management-page__identity">
                        <strong>{assignment.kelas?.nama || '-'}</strong>
                        <span>
                          Tingkat {assignment.kelas?.tingkat ?? '-'}
                        </span>
                      </div>
                    )

                  case 'year':
                    return assignment.tahun_akademik
                      ? academicYearLabel(assignment.tahun_akademik)
                      : '-'

                  case 'period':
                    return (
                      <div className="homeroom-management-page__identity">
                        <span>
                          Mulai: {formatDate(assignment.tanggal_mulai)}
                        </span>
                        <span>
                          Selesai: {formatDate(assignment.tanggal_selesai)}
                        </span>
                      </div>
                    )

                  case 'status':
                    return ended ? (
                      <Badge variant="neutral">Berakhir</Badge>
                    ) : (
                      <Badge variant="success">Aktif</Badge>
                    )

                  case 'actions':
                    return (
                      <div className="homeroom-management-page__actions">
                        <Button
                          type="button"
                          disabled={endingId !== null || saving}
                          onClick={() => openEditModal(assignment)}
                        >
                          Edit
                        </Button>

                        {!ended && (
                          <Button
                            type="button"
                            disabled={endingId !== null || saving}
                            onClick={() => handleEndAssignment(assignment)}
                          >
                            Akhiri
                          </Button>
                        )}
                      </div>
                    )

                  default:
                    return '-'
                }
              }}
            />

            <div className="homeroom-management-page__pagination">
              <span>
                Halaman {meta.current_page} dari {meta.last_page}
                {' · '}
                {meta.total} penugasan
              </span>

              <div>
                <Button
                  type="button"
                  disabled={loading || page <= 1}
                  onClick={() =>
                    setPage((current) => Math.max(1, current - 1))
                  }
                >
                  Sebelumnya
                </Button>

                <Button
                  type="button"
                  disabled={loading || page >= meta.last_page}
                  onClick={() =>
                    setPage((current) =>
                      Math.min(meta.last_page, current + 1),
                    )
                  }
                >
                  Berikutnya
                </Button>
              </div>
            </div>
          </>
        )}
      </Card>

      {/* Modal tambah dan edit penugasan */}
      <Modal
        open={modalOpen}
        title={
          editingAssignment
            ? 'Edit Penugasan Wali Kelas'
            : 'Tambah Penugasan Wali Kelas'
        }
        description="Pilih guru, kelas, tahun akademik, dan periode penugasan."
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
              form="homeroom-form"
              loading={saving}
            >
              {editingAssignment
                ? 'Simpan Perubahan'
                : 'Simpan Penugasan'}
            </Button>
          </>
        }
      >
        {submitError && (
          <div className="homeroom-management-page__form-error">
            <ErrorState
              message={apiMessage(
                submitError,
                'Penugasan gagal disimpan.',
              )}
            />
          </div>
        )}

        <form id="homeroom-form" onSubmit={handleSubmit}>
          <div className="homeroom-management-page__form-grid">
            <Select
              id="form-year"
              name="tahun_akademik_id"
              label="Tahun Akademik"
              value={form.tahun_akademik_id}
              options={yearOptions}
              placeholder="Pilih tahun akademik"
              required
              disabled={saving || optionsLoading}
              error={fieldError(fieldErrors, 'tahun_akademik_id')}
              onChange={handleFormChange}
            />

            {formClassesLoading && (
              <p role="status">Memuat daftar kelas...</p>
            )}

            {formClassesError && (
              <ErrorState
                message={apiMessage(
                  formClassesError,
                  'Data kelas gagal dimuat.',
                )}
              />
            )}

            {!formClassesLoading &&
              !formClassesError &&
              form.tahun_akademik_id &&
              formClasses.length === 0 && (
                <p role="status">
                  Belum ada kelas untuk tahun akademik yang dipilih.
                </p>
              )}

            <Select
              id="form-class"
              name="kelas_id"
              label="Kelas"
              value={form.kelas_id}
              options={formClassOptions}
              placeholder={
                !form.tahun_akademik_id
                  ? 'Pilih tahun akademik terlebih dahulu'
                  : formClassesLoading
                    ? 'Memuat kelas...'
                    : 'Pilih kelas'
              }
              required
              disabled={
                saving ||
                !form.tahun_akademik_id ||
                formClassesLoading ||
                Boolean(formClassesError) ||
                formClassOptions.length === 0
              }
              error={fieldError(fieldErrors, 'kelas_id')}
              onChange={handleFormChange}
            />

            <Select
              id="form-teacher"
              name="guru_id"
              label="Guru Wali Kelas"
              value={form.guru_id}
              options={teacherOptions}
              placeholder="Pilih guru"
              required
              disabled={saving || optionsLoading}
              error={fieldError(fieldErrors, 'guru_id')}
              onChange={handleFormChange}
            />

            <Input
              id="form-start"
              name="tanggal_mulai"
              label="Tanggal Mulai"
              type="date"
              value={form.tanggal_mulai}
              required
              disabled={saving}
              error={fieldError(fieldErrors, 'tanggal_mulai')}
              onChange={handleFormChange}
            />

            <Input
              id="form-end"
              name="tanggal_selesai"
              label="Tanggal Selesai"
              type="date"
              value={form.tanggal_selesai}
              disabled={saving}
              error={fieldError(fieldErrors, 'tanggal_selesai')}
              hint="Kosongkan jika penugasan masih aktif."
              onChange={handleFormChange}
            />
          </div>
        </form>
      </Modal>

      {/* Modal konfirmasi pengakhiran penugasan */}
      <Modal
        open={Boolean(assignmentToEnd)}
        title="Akhiri Penugasan?"
        description="Konfirmasi pengakhiran penugasan Wali Kelas."
        onClose={() => {
          if (endingId === null) {
            setAssignmentToEnd(null)
          }
        }}
        footer={
          <>
            <Button
              type="button"
              disabled={endingId !== null}
              onClick={() => setAssignmentToEnd(null)}
            >
              Batal
            </Button>

            <button
              type="button"
              className="homeroom-management-page__confirm-button"
              disabled={endingId !== null}
              onClick={confirmEndAssignment}
            >
              {endingId === assignmentToEnd?.id
                ? 'Memproses...'
                : 'Akhiri Penugasan'}
            </button>
          </>
        }
      >
        {assignmentToEnd && (
          <div className="homeroom-management-page__confirm">
            <div className="homeroom-management-page__confirm-icon" aria-hidden="true">
              !
            </div>

            <p className="homeroom-management-page__confirm-message">
              Kamu akan mengakhiri penugasan{' '}
              <strong>
                {assignmentToEnd.guru?.nama_lengkap || 'Guru'}
              </strong>{' '}
              sebagai Wali Kelas{' '}
              <strong>
                {assignmentToEnd.kelas?.nama || '-'}
              </strong>.
            </p>

            <div className="homeroom-management-page__confirm-info">
              <p>
                <strong>Tahun akademik:</strong>{' '}
                {assignmentToEnd.tahun_akademik
                  ? academicYearLabel(assignmentToEnd.tahun_akademik)
                  : '-'}
              </p>

              <p className="homeroom-management-page__confirm-warning">
                Riwayat penugasan tetap tersimpan. Setelah diakhiri,
                penugasan ini tidak lagi dianggap aktif.
              </p>
            </div>
          </div>
        )}
      </Modal>
    </section>
  )
}
