
import { useCallback, useEffect, useMemo, useState } from 'react'
import { useAuth } from '../../context/useAuth'
import { subjectApi } from '../../services/subjectApi'
import PageHeader from '../../components/ui/PageHeader'
import Card from '../../components/ui/Card'
import Table from '../../components/ui/Table'
import Input from '../../components/ui/Input'
import Button from '../../components/ui/Button'
import Badge from '../../components/ui/Badge'
import Modal from '../../components/ui/Modal'
import EmptyState from '../../components/ui/EmptyState'
import ErrorState from '../../components/ui/ErrorState'
import LoadingState from '../../components/ui/LoadingState'
import './SubjectsPage.css'

const INITIAL_FORM = {
  kode: '',
  nama: '',
  kkm: '',
}

const DEFAULT_PER_PAGE = 20
const NOTIFICATION_DURATION = 4500

function formatKkm(value) {
  if (value === null || value === undefined || value === '') {
    return '—'
  }

  const number = Number(value)

  if (!Number.isFinite(number)) {
    return '—'
  }

  return number.toLocaleString('id-ID', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
  })
}

function formatDate(value) {
  if (!value) return '—'

  const date = new Date(value)

  if (Number.isNaN(date.getTime())) {
    return '—'
  }

  return date.toLocaleDateString('id-ID', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
  })
}

function getErrorMessage(error, fallback) {
  return error?.message || fallback
}

function getFieldError(errors, field) {
  const value = errors?.[field]

  if (Array.isArray(value)) {
    return value[0] || ''
  }

  return typeof value === 'string' ? value : ''
}

export default function SubjectsPage() {
  const { role } = useAuth()
  const canManage = role?.code === 'TU'

  const [subjects, setSubjects] = useState([])
  const [pagination, setPagination] = useState({
    current_page: 1,
    per_page: DEFAULT_PER_PAGE,
    total: 0,
    last_page: 1,
  })

  const [search, setSearch] = useState('')
  const [loading, setLoading] = useState(true)
  const [pageError, setPageError] = useState('')

  const [modalOpen, setModalOpen] = useState(false)
  const [editingSubject, setEditingSubject] = useState(null)
  const [form, setForm] = useState(INITIAL_FORM)
  const [formErrors, setFormErrors] = useState({})
  const [formError, setFormError] = useState('')
  const [saving, setSaving] = useState(false)

  const [deleteTarget, setDeleteTarget] = useState(null)
  const [deleting, setDeleting] = useState(false)
  const [deleteError, setDeleteError] = useState('')

  const [notification, setNotification] = useState(null)

  const showNotification = useCallback((type, message) => {
    setNotification({
      id: Date.now(),
      type,
      message,
    })
  }, [])

  useEffect(() => {
    if (!notification) return undefined

    const timeoutId = setTimeout(() => {
      setNotification(null)
    }, NOTIFICATION_DURATION)

    return () => clearTimeout(timeoutId)
  }, [notification])

  const loadSubjects = useCallback(async (page = 1) => {
    setLoading(true)
    setPageError('')

    try {
      const response = await subjectApi.getAll({
        page,
        per_page: pagination.per_page || DEFAULT_PER_PAGE,
      })

      const rows = Array.isArray(response?.data) ? response.data : []
      const meta = response?.meta || {}

      setSubjects(rows)
      setPagination({
        current_page: Number(meta.current_page) || page,
        per_page: Number(meta.per_page) || DEFAULT_PER_PAGE,
        total: Number(meta.total) || 0,
        last_page: Math.max(1, Number(meta.last_page) || 1),
      })
    } catch (error) {
      setPageError(
        getErrorMessage(
          error,
          'Data mata pelajaran gagal dimuat. Silakan coba kembali.',
        ),
      )
    } finally {
      setLoading(false)
    }
  }, [pagination.per_page])

  useEffect(() => {
    loadSubjects(1)
  }, [loadSubjects])

  const filteredSubjects = useMemo(() => {
    const keyword = search.trim().toLocaleLowerCase('id-ID')

    if (!keyword) return subjects

    return subjects.filter((subject) => {
      const kode = String(subject.kode || '').toLocaleLowerCase('id-ID')
      const nama = String(subject.nama || '').toLocaleLowerCase('id-ID')

      return kode.includes(keyword) || nama.includes(keyword)
    })
  }, [subjects, search])

  const setField = (field, value) => {
    setForm((current) => ({
      ...current,
      [field]: value,
    }))

    setFormErrors((current) => ({
      ...current,
      [field]: '',
    }))

    setFormError('')
  }

  const openCreateModal = () => {
    setEditingSubject(null)
    setForm(INITIAL_FORM)
    setFormErrors({})
    setFormError('')
    setModalOpen(true)
  }

  const openEditModal = (subject) => {
    setEditingSubject(subject)
    setForm({
      kode: subject.kode || '',
      nama: subject.nama || '',
      kkm:
        subject.kkm === null || subject.kkm === undefined
          ? ''
          : String(subject.kkm),
    })
    setFormErrors({})
    setFormError('')
    setModalOpen(true)
  }

  const closeFormModal = () => {
    if (saving) return

    setModalOpen(false)
    setEditingSubject(null)
    setForm(INITIAL_FORM)
    setFormErrors({})
    setFormError('')
  }

  const validateForm = () => {
    const errors = {}
    const kode = form.kode.trim()
    const nama = form.nama.trim()
    const kkm = form.kkm.trim()

    if (!kode) {
      errors.kode = 'Kode mata pelajaran wajib diisi.'
    } else if (kode.length > 50) {
      errors.kode = 'Kode maksimal 50 karakter.'
    }

    if (!nama) {
      errors.nama = 'Nama mata pelajaran wajib diisi.'
    } else if (nama.length > 255) {
      errors.nama = 'Nama maksimal 255 karakter.'
    }

    if (kkm !== '') {
      const number = Number(kkm)

      if (!Number.isFinite(number)) {
        errors.kkm = 'KKM harus berupa angka.'
      } else if (number < 0 || number > 999.99) {
        errors.kkm = 'KKM harus berada pada rentang 0–999,99.'
      }
    }

    setFormErrors(errors)

    return Object.keys(errors).length === 0
  }

  const handleSubmit = async (event) => {
    event.preventDefault()

    if (!canManage || saving) return
    if (!validateForm()) return

    setSaving(true)
    setFormError('')

    const isEditing = Boolean(editingSubject)

    const payload = {
      kode: form.kode.trim(),
      nama: form.nama.trim(),
      kkm: form.kkm.trim() === '' ? null : Number(form.kkm),
    }

    try {
      const response = isEditing
        ? await subjectApi.update(editingSubject.id, payload)
        : await subjectApi.create(payload)

      setModalOpen(false)
      setEditingSubject(null)
      setForm(INITIAL_FORM)
      setFormErrors({})

      showNotification(
        'success',
        response?.message ||
          (isEditing
            ? 'Data mata pelajaran berhasil diperbarui.'
            : 'Data mata pelajaran berhasil ditambahkan.'),
      )

      await loadSubjects(
        isEditing ? pagination.current_page : 1,
      )
    } catch (error) {
      if (error?.errors && typeof error.errors === 'object') {
        setFormErrors((current) => ({
          ...current,
          ...Object.fromEntries(
            Object.entries(error.errors).map(([key, value]) => [
              key,
              Array.isArray(value) ? value[0] : String(value),
            ]),
          ),
        }))
      }

      const message = getErrorMessage(
        error,
        'Data mata pelajaran gagal disimpan. Silakan coba kembali.',
      )

      setFormError(message)
      showNotification('error', message)
    } finally {
      setSaving(false)
    }
  }

  const openDeleteModal = (subject) => {
    setDeleteTarget(subject)
    setDeleteError('')
  }

  const closeDeleteModal = () => {
    if (deleting) return

    setDeleteTarget(null)
    setDeleteError('')
  }

  const handleDelete = async () => {
    if (!canManage || !deleteTarget || deleting) return

    setDeleting(true)
    setDeleteError('')

    try {
      const response = await subjectApi.delete(deleteTarget.id)

      const nextPage =
        subjects.length === 1 && pagination.current_page > 1
          ? pagination.current_page - 1
          : pagination.current_page

      setDeleteTarget(null)

      showNotification(
        'success',
        response?.message || 'Data mata pelajaran berhasil dihapus.',
      )

      await loadSubjects(nextPage)
    } catch (error) {
      const message = getErrorMessage(
        error,
        'Data mata pelajaran gagal dihapus. Silakan coba kembali.',
      )

      setDeleteError(message)
      showNotification('error', message)
    } finally {
      setDeleting(false)
    }
  }

  const columns = [
    { key: 'number', label: 'No.' },
    { key: 'kode', label: 'Kode Mata Pelajaran' },
    { key: 'nama', label: 'Nama Mata Pelajaran' },
    { key: 'kkm', label: 'KKM' },
    { key: 'updated_at', label: 'Terakhir Diperbarui' },
    ...(canManage ? [{ key: 'actions', label: 'Aksi' }] : []),
  ]

  const renderCell = (subject, column) => {
    switch (column.key) {
      case 'number':
        return (
          <span className="subjects-page__number">
            {(pagination.current_page - 1) * pagination.per_page +
              subjects.indexOf(subject) +
              1}
          </span>
        )

      case 'kode':
        return (
          <span className="subjects-page__code">
            {subject.kode || '—'}
          </span>
        )

      case 'nama':
        return (
          <span className="subjects-page__name">
            {subject.nama || '—'}
          </span>
        )

      case 'kkm':
        return subject.kkm === null || subject.kkm === undefined ? (
          <Badge variant="neutral">Belum ditentukan</Badge>
        ) : (
          <Badge variant="info">{formatKkm(subject.kkm)}</Badge>
        )

      case 'updated_at':
        return (
          <span className="subjects-page__date">
            {formatDate(subject.updated_at)}
          </span>
        )

      case 'actions':
        return (
          <div className="subjects-page__actions">
            <Button
              type="button"
              variant="secondary"
              onClick={() => openEditModal(subject)}
            >
              Edit
            </Button>

            <Button
              type="button"
              variant="danger"
              onClick={() => openDeleteModal(subject)}
            >
              Hapus
            </Button>
          </div>
        )

      default:
        return '—'
    }
  }

  return (
    <div className="subjects-page">
      {notification && (
        <div
          key={notification.id}
          className={`subjects-page__notification subjects-page__notification--${notification.type}`}
          role={notification.type === 'error' ? 'alert' : 'status'}
          aria-live={notification.type === 'error' ? 'assertive' : 'polite'}
        >
          <span className="subjects-page__notification-icon" aria-hidden="true">
            {notification.type === 'success' ? '✓' : '!'}
          </span>

          <p className="subjects-page__notification-message">
            {notification.message}
          </p>

          <button
            type="button"
            className="subjects-page__notification-close"
            aria-label="Tutup notifikasi"
            onClick={() => setNotification(null)}
          >
            ×
          </button>
        </div>
      )}

      <PageHeader
        title="Mata Pelajaran"
        description="Kelola data mata pelajaran yang digunakan dalam kegiatan belajar mengajar."
      />

      <Card>
        <div className="subjects-page__toolbar">
          <div className="subjects-page__toolbar-info">
            <h2 className="subjects-page__section-title">
              Daftar Mata Pelajaran
            </h2>

            <p className="subjects-page__section-description">
              {pagination.total} mata pelajaran terdaftar
            </p>
          </div>

          <div className="subjects-page__toolbar-actions">
            <div className="subjects-page__search">
              <Input
                id="subject-search"
                name="subject-search"
                label="Cari mata pelajaran"
                value={search}
                placeholder="Cari kode atau nama..."
                onChange={(event) => setSearch(event.target.value)}
              />
            </div>

            {canManage && (
              <Button type="button" onClick={openCreateModal}>
                + Tambah Mata Pelajaran
              </Button>
            )}
          </div>
        </div>

        <p className="subjects-page__search-note">
          Pencarian berlaku pada data di halaman yang sedang ditampilkan.
        </p>

        {loading ? (
          <LoadingState />
        ) : pageError ? (
          <div className="subjects-page__error">
            <ErrorState message={pageError} />

            <Button
              type="button"
              variant="secondary"
              onClick={() => loadSubjects(pagination.current_page)}
            >
              Coba Lagi
            </Button>
          </div>
        ) : filteredSubjects.length === 0 ? (
          <EmptyState
            title={
              search.trim()
                ? 'Mata pelajaran tidak ditemukan'
                : 'Belum ada mata pelajaran'
            }
            description={
              search.trim()
                ? 'Tidak ada data pada halaman ini yang cocok dengan kata kunci pencarian.'
                : 'Data mata pelajaran yang tersedia akan ditampilkan di sini.'
            }
          />
        ) : (
          <>
            <Table
              columns={columns}
              data={filteredSubjects}
              rowKey="id"
              renderCell={renderCell}
            />

            <div className="subjects-page__pagination">
              <span className="subjects-page__pagination-info">
                Halaman {pagination.current_page} dari {pagination.last_page}
                {' · '}
                Total {pagination.total} data
              </span>

              <div className="subjects-page__pagination-actions">
                <Button
                  type="button"
                  variant="secondary"
                  disabled={loading || pagination.current_page <= 1}
                  onClick={() => loadSubjects(pagination.current_page - 1)}
                >
                  Sebelumnya
                </Button>

                <Button
                  type="button"
                  variant="secondary"
                  disabled={
                    loading ||
                    pagination.current_page >= pagination.last_page
                  }
                  onClick={() => loadSubjects(pagination.current_page + 1)}
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
        title={
          editingSubject
            ? 'Edit Mata Pelajaran'
            : 'Tambah Mata Pelajaran'
        }
        description={
          editingSubject
            ? 'Perbarui informasi mata pelajaran berikut.'
            : 'Isi informasi untuk menambahkan mata pelajaran baru.'
        }
        onClose={closeFormModal}
        size="medium"
        footer={
          <>
            <Button
              type="button"
              variant="secondary"
              disabled={saving}
              onClick={closeFormModal}
            >
              Batal
            </Button>

            <Button
              type="submit"
              form="subject-form"
              disabled={saving}
            >
              {saving
                ? 'Menyimpan...'
                : editingSubject
                  ? 'Simpan Perubahan'
                  : 'Simpan Mata Pelajaran'}
            </Button>
          </>
        }
      >
        <form
          id="subject-form"
          className="subjects-page__form"
          onSubmit={handleSubmit}
          noValidate
        >
          {formError && (
            <div className="subjects-page__form-error" role="alert">
              {formError}
            </div>
          )}

          <Input
            id="subject-kode"
            name="kode"
            label="Kode Mata Pelajaran"
            value={form.kode}
            onChange={(event) => setField('kode', event.target.value)}
            placeholder="Contoh: MTK-001"
            required
            maxLength={50}
            error={getFieldError(formErrors, 'kode')}
          />

          <Input
            id="subject-nama"
            name="nama"
            label="Nama Mata Pelajaran"
            value={form.nama}
            onChange={(event) => setField('nama', event.target.value)}
            placeholder="Contoh: Matematika"
            required
            maxLength={255}
            error={getFieldError(formErrors, 'nama')}
          />

          <Input
            id="subject-kkm"
            name="kkm"
            label="KKM"
            value={form.kkm}
            onChange={(event) => setField('kkm', event.target.value)}
            placeholder="Contoh: 75"
            inputType="number"
            error={getFieldError(formErrors, 'kkm')}
            hint="Opsional. Kosongkan jika KKM belum ditentukan."
          />
        </form>
      </Modal>

      <Modal
        open={Boolean(deleteTarget)}
        title="Hapus Mata Pelajaran"
        description="Pastikan data yang dipilih memang akan dihapus."
        onClose={closeDeleteModal}
        size="small"
        footer={
          <>
            <Button
              type="button"
              variant="secondary"
              disabled={deleting}
              onClick={closeDeleteModal}
            >
              Batal
            </Button>

            <Button
              type="button"
              variant="danger"
              disabled={deleting}
              onClick={handleDelete}
            >
              {deleting ? 'Menghapus...' : 'Ya, Hapus'}
            </Button>
          </>
        }
      >
        <div className="subjects-page__delete-content">
          {deleteError && (
            <div className="subjects-page__form-error" role="alert">
              {deleteError}
            </div>
          )}

          <p>Anda akan menghapus mata pelajaran:</p>

          <div className="subjects-page__delete-summary">
            <strong>{deleteTarget?.nama}</strong>
            <span>Kode: {deleteTarget?.kode}</span>
          </div>

          <p className="subjects-page__delete-warning">
            Tindakan ini tidak dapat dibatalkan. Jika data masih digunakan
            oleh jadwal atau data lain, server mungkin menolak penghapusan.
          </p>
        </div>
      </Modal>
    </div>
  )
}
