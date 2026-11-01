import { useCallback, useEffect, useState } from 'react'

import PageHeader from '../../components/ui/PageHeader'
import Card from '../../components/ui/Card'
import Table from '../../components/ui/Table'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import Button from '../../components/ui/Button'
import Modal from '../../components/ui/Modal'
import EmptyState from '../../components/ui/EmptyState'
import ErrorState from '../../components/ui/ErrorState'
import LoadingState from '../../components/ui/LoadingState'

import { classApi } from '../../services/classApi'
import { academicYearApi } from '../../services/academicYearApi'

import './ClassesPage.css'

const initialForm = {
  tahun_akademik_id: '',
  nama: '',
  tingkat: '',
}

const columns = [
  { key: 'nama', label: 'Nama Rombel' },
  { key: 'tingkat', label: 'Tingkat' },
  { key: 'tahun', label: 'Tahun Akademik' },
  { key: 'actions', label: 'Aksi' },
]

const gradeOptions = [
  { value: '10', label: '10' },
  { value: '11', label: '11' },
  { value: '12', label: '12' },
]

function fieldError(errors, field) {
  const value = errors?.[field]
  return Array.isArray(value) ? value[0] || '' : value || ''
}

function apiMessage(error, fallback) {
  return error?.message || fallback
}

function yearLabel(year) {
  return `${year.tahun_mulai}/${year.tahun_selesai} - ${year.semester}`
}

export default function ClassesPage() {
  const [classes, setClasses] = useState([])
  const [years, setYears] = useState([])

  const [searchInput, setSearchInput] = useState('')
  const [search, setSearch] = useState('')
  const [filterYear, setFilterYear] = useState('')
  const [filterGrade, setFilterGrade] = useState('')
  const [page, setPage] = useState(1)

  const [meta, setMeta] = useState({
    current_page: 1,
    last_page: 1,
    per_page: 20,
    total: 0,
  })

  const [loading, setLoading] = useState(true)
  const [yearsLoading, setYearsLoading] = useState(true)
  const [listError, setListError] = useState(null)
  const [yearsError, setYearsError] = useState(null)

  const [modalOpen, setModalOpen] = useState(false)
  const [editingClass, setEditingClass] = useState(null)
  const [form, setForm] = useState(initialForm)
  const [fieldErrors, setFieldErrors] = useState({})
  const [submitError, setSubmitError] = useState(null)
  const [saving, setSaving] = useState(false)
  const [deletingId, setDeletingId] = useState(null)
  const [deleteTarget, setDeleteTarget] = useState(null)
  const [successMessage, setSuccessMessage] = useState('')

  const loadYears = useCallback(async () => {
    setYearsLoading(true)
    setYearsError(null)

    try {
      const response = await academicYearApi.getAll({ per_page: 100 })
      setYears(Array.isArray(response?.data) ? response.data : [])
    } catch (error) {
      setYearsError(error)
    } finally {
      setYearsLoading(false)
    }
  }, [])

  const loadClasses = useCallback(async () => {
    setLoading(true)
    setListError(null)

    try {
      const response = await classApi.getAll({
        search,
        academic_year_id: filterYear,
        grade_level: filterGrade,
        page,
        per_page: 20,
      })

      setClasses(Array.isArray(response?.data) ? response.data : [])
      setMeta((current) => ({
        ...current,
        ...(response?.meta || {}),
      }))
    } catch (error) {
      setListError(error)
    } finally {
      setLoading(false)
    }
  }, [search, filterYear, filterGrade, page])

  useEffect(() => {
    loadYears()
  }, [loadYears])

  useEffect(() => {
    loadClasses()
  }, [loadClasses])

  function resetForm() {
    setForm(initialForm)
    setFieldErrors({})
    setSubmitError(null)
    setEditingClass(null)
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
      tahun_akademik_id: filterYear,
    })

    setModalOpen(true)
  }

  function openEditModal(item) {
    resetForm()
    setEditingClass(item)
    setSuccessMessage('')

    setForm({
      tahun_akademik_id: String(item.tahun_akademik_id),
      nama: item.nama ?? '',
      tingkat: String(item.tingkat ?? ''),
    })

    setModalOpen(true)
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

  function handleSearch(event) {
    event.preventDefault()
    setPage(1)
    setSearch(searchInput.trim())
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

    const nama = form.nama.trim()

    if (!nama) {
      errors.nama = 'Nama rombel wajib diisi.'
    } else if (nama.length > 100) {
      errors.nama = 'Nama rombel maksimal 100 karakter.'
    }

    if (!['10', '11', '12'].includes(form.tingkat)) {
      errors.tingkat = 'Tingkat harus berupa 10, 11, atau 12.'
    }

    if (Object.keys(errors).length > 0) {
      setFieldErrors(errors)
      return
    }

    const payload = {
      tahun_akademik_id: Number(form.tahun_akademik_id),
      nama,
      tingkat: Number(form.tingkat),
    }

    setSaving(true)

    try {
      if (editingClass) {
        await classApi.update(editingClass.id, payload)
        setSuccessMessage('Data kelas berhasil diperbarui.')
      } else {
        await classApi.create(payload)
        setSuccessMessage('Data kelas berhasil ditambahkan.')
      }

      setModalOpen(false)
      resetForm()
      await loadClasses()
    } catch (error) {
      setFieldErrors(error?.validationErrors || {})
      setSubmitError(error)
    } finally {
      setSaving(false)
    }
  }

  function openDeleteModal(item) {
    setSuccessMessage('')
    setDeleteTarget(item)
  }

  function closeDeleteModal() {
    if (deletingId !== null) return
    setDeleteTarget(null)
  }

  async function handleDelete() {
    if (!deleteTarget || deletingId !== null) return

    const item = deleteTarget
    setDeletingId(item.id)
    setSuccessMessage('')
    setListError(null)

    try {
      await classApi.delete(item.id)
      setDeleteTarget(null)
      setSuccessMessage(`Rombel "${item.nama}" berhasil dihapus.`)

      if (classes.length === 1 && page > 1) {
        setPage((current) => current - 1)
      } else {
        await loadClasses()
      }
    } catch (error) {
      setListError(error)
    } finally {
      setDeletingId(null)
    }
  }

  const yearOptions = years.map((year) => ({
    value: String(year.id),
    label: yearLabel(year),
  }))

  return (
    <section className="classes-page">
      <PageHeader
        title="Data Kelas"
        description="Kelola rombel berdasarkan tingkat dan tahun akademik."
        actions={
          <Button
            type="button"
            onClick={openCreateModal}
            disabled={yearsLoading || Boolean(yearsError)}
          >
            + Tambah Kelas
          </Button>
        }
      />

      {successMessage && (
        <div
          className="classes-page__success"
          role="status"
          aria-live="polite"
        >
          {successMessage}
        </div>
      )}

      {yearsError && (
        <ErrorState
          message={apiMessage(
            yearsError,
            'Data tahun akademik gagal dimuat.',
          )}
        />
      )}

      <Card>
        <div className="classes-page__list-header">
          <div>
            <p className="classes-page__eyebrow">Data akademik</p>
            <h2>Daftar Kelas</h2>
            <p>Data rombel pada setiap tingkat dan tahun akademik.</p>
          </div>

          <span className="classes-page__count">
            {meta.total} kelas
          </span>
        </div>

        <form
          className="classes-page__filters"
          onSubmit={handleSearch}
        >
          <Input
            id="class-search"
            name="search"
            label="Cari rombel"
            value={searchInput}
            placeholder="Contoh: IPA 1"
            onChange={(event) => setSearchInput(event.target.value)}
          />

          <Select
            id="class-filter-year"
            name="academic_year_id"
            label="Tahun Akademik"
            value={filterYear}
            options={yearOptions}
            placeholder="Semua tahun akademik"
            disabled={yearsLoading}
            onChange={(event) => {
              setFilterYear(event.target.value)
              setPage(1)
            }}
          />

          <Select
            id="class-filter-grade"
            name="grade_level"
            label="Tingkat"
            value={filterGrade}
            options={gradeOptions}
            placeholder="Semua tingkat"
            onChange={(event) => {
              setFilterGrade(event.target.value)
              setPage(1)
            }}
          />

          <div className="classes-page__filter-actions">
            <Button type="submit">Cari</Button>

            <Button
              type="button"
              onClick={() => {
                setSearchInput('')
                setSearch('')
                setFilterYear('')
                setFilterGrade('')
                setPage(1)
                setSuccessMessage('')
              }}
            >
              Reset
            </Button>
          </div>
        </form>

        {loading && <LoadingState message="Memuat data kelas..." />}

        {!loading && listError && (
          <ErrorState
            message={apiMessage(
              listError,
              'Data kelas gagal dimuat.',
            )}
          />
        )}

        {!loading && !listError && classes.length === 0 && (
          <EmptyState
            title="Belum ada data kelas"
            description={
              search || filterYear || filterGrade
                ? 'Tidak ditemukan kelas yang sesuai dengan filter.'
                : 'Tambahkan rombel untuk mulai mengelola data akademik.'
            }
          />
        )}

        {!loading && !listError && classes.length > 0 && (
          <>
            <Table
              columns={columns}
              data={classes}
              rowKey="id"
              renderCell={(item, column) => {
                switch (column.key) {
                  case 'nama':
                    return item.nama

                  case 'tingkat':
                    return item.tingkat

                  case 'tahun':
                    return (
                      years.find(
                        (year) =>
                          String(year.id) ===
                          String(item.tahun_akademik_id),
                      )
                        ? yearLabel(
                            years.find(
                              (year) =>
                                String(year.id) ===
                                String(item.tahun_akademik_id),
                            ),
                          )
                        : `ID tahun akademik: ${item.tahun_akademik_id}`
                    )

                  case 'actions':
                    return (
                      <div className="classes-page__actions">
                        <Button
                          type="button"
                          disabled={deletingId !== null || saving}
                          onClick={() => openEditModal(item)}
                        >
                          Edit
                        </Button>

                        <Button
                          type="button"
                          loading={deletingId === item.id}
                          disabled={deletingId !== null || saving}
                          onClick={() => openDeleteModal(item)}
                        >
                          Hapus
                        </Button>
                      </div>
                    )

                  default:
                    return '-'
                }
              }}
            />

            <div className="classes-page__pagination">
              <span>
                Halaman {meta.current_page} dari {meta.last_page}
                {' · '}
                {meta.total} kelas
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

      <Modal
        open={modalOpen}
        title={editingClass ? 'Edit Kelas' : 'Tambah Kelas'}
        description="Isi nama rombel, tingkat, dan tahun akademik."
        onClose={closeModal}
        size="medium"
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
              form="class-form"
              loading={saving}
            >
              {editingClass ? 'Simpan Perubahan' : 'Simpan Kelas'}
            </Button>
          </>
        }
      >
        {submitError && (
          <div className="classes-page__form-error">
            <ErrorState
              message={apiMessage(
                submitError,
                'Data kelas gagal disimpan.',
              )}
            />
          </div>
        )}

        <form id="class-form" onSubmit={handleSubmit}>
          <div className="classes-page__form-grid">
            <Select
              id="form-academic-year"
              name="tahun_akademik_id"
              label="Tahun Akademik"
              value={form.tahun_akademik_id}
              options={yearOptions}
              placeholder="Pilih tahun akademik"
              required
              disabled={saving || yearsLoading}
              error={fieldError(
                fieldErrors,
                'tahun_akademik_id',
              )}
              onChange={handleFormChange}
            />

            <Select
              id="form-class-grade"
              name="tingkat"
              label="Tingkat"
              value={form.tingkat}
              options={gradeOptions}
              placeholder="Pilih tingkat"
              required
              disabled={saving}
              error={fieldError(fieldErrors, 'tingkat')}
              onChange={handleFormChange}
              hint="Pilih tingkat SMA: 10, 11, atau 12."
            />

            <Input
              id="form-class-name"
              name="nama"
              label="Nama Rombel"
              value={form.nama}
              placeholder="Contoh: IPA 1, IPA 2, atau A"
              required
              maxLength={100}
              disabled={saving}
              error={fieldError(fieldErrors, 'nama')}
              onChange={handleFormChange}
            />
          </div>
        </form>
      </Modal>

      <Modal
        open={Boolean(deleteTarget)}
        title="Hapus Rombel?"
        description="Konfirmasi penghapusan data kelas."
        onClose={closeDeleteModal}
        size="medium"
        footer={
          <>
            <Button
              type="button"
              disabled={deletingId !== null}
              onClick={closeDeleteModal}
            >
              Batal
            </Button>
            <Button
              type="button"
              loading={deletingId !== null}
              disabled={deletingId !== null}
              onClick={handleDelete}
              className="classes-page__delete-confirm"
            >
              {deletingId !== null ? 'Menghapus...' : 'Hapus Kelas'}
            </Button>
          </>
        }
      >
        {deleteTarget && (
          <div className="classes-page__delete-content">
            <div className="classes-page__delete-icon" aria-hidden="true">
              !
            </div>
            <p>
              Apakah kamu yakin ingin menghapus rombel{' '}
              <strong>{deleteTarget.nama}</strong> tingkat{' '}
              <strong>{deleteTarget.tingkat}</strong>?
            </p>
            <p className="classes-page__delete-warning">
              Pastikan kelas ini tidak lagi digunakan oleh data akademik terkait.
              Tindakan ini tidak dapat dibatalkan.
            </p>
          </div>
        )}
      </Modal>
    </section>
  )
}
