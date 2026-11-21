
import { useCallback, useEffect, useState } from 'react'

import { useAuth } from '../../context/useAuth'
import PageHeader from '../../components/ui/PageHeader'
import Card from '../../components/ui/Card'
import Table from '../../components/ui/Table'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import Button from '../../components/ui/Button'
import Badge from '../../components/ui/Badge'
import Modal from '../../components/ui/Modal'
import EmptyState from '../../components/ui/EmptyState'
import ErrorState from '../../components/ui/ErrorState'
import LoadingState from '../../components/ui/LoadingState'

import { teacherApi } from '../../services/teacherApi'

import './TeachersPage.css'

const initialForm = {
  user_id: '',
  nip: '',
  nama_lengkap: '',
  jenis_kelamin: '',
  no_telepon: '',
  alamat: '',
}

const columns = [
  { key: 'identity', label: 'Identitas Guru' },
  { key: 'nip', label: 'NIP' },
  { key: 'gender', label: 'Jenis Kelamin' },
  { key: 'status', label: 'Status Akun' },
  { key: 'actions', label: 'Aksi' },
]

function getFieldError(errors, field) {
  const value = errors?.[field]
  return Array.isArray(value) ? value[0] || '' : value || ''
}

function getTeacherName(teacher) {
  return teacher?.nama_lengkap || teacher?.user?.name || '-'
}

function getApiMessage(error, fallback) {
  return error?.message || fallback
}

export default function TeachersPage() {
  const { role, permissions = [] } = useAuth()
  const canManage =
    role?.code === 'TU' || permissions.includes('teachers.create')

  const [teachers, setTeachers] = useState([])
  const [accounts, setAccounts] = useState([])
  const [searchInput, setSearchInput] = useState('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)

  const [meta, setMeta] = useState({
    current_page: 1,
    last_page: 1,
    per_page: 15,
    total: 0,
  })

  const [loading, setLoading] = useState(true)
  const [listError, setListError] = useState(null)
  const [deleteError, setDeleteError] = useState(null)
  const [modalOpen, setModalOpen] = useState(false)
  const [editingTeacher, setEditingTeacher] = useState(null)
  const [form, setForm] = useState(initialForm)
  const [fieldErrors, setFieldErrors] = useState({})
  const [submitError, setSubmitError] = useState(null)
  const [successMessage, setSuccessMessage] = useState('')
  const [saving, setSaving] = useState(false)
  const [deletingId, setDeletingId] = useState(null)
  const [deleteTarget, setDeleteTarget] = useState(null)
  // State Import Excel
const [importModalOpen, setImportModalOpen] = useState(false)
const [selectedExcel, setSelectedExcel] = useState(null)
const [previewData, setPreviewData] = useState(null)
const [importError, setImportError] = useState('')
const [previewLoading, setPreviewLoading] = useState(false)
const [importing, setImporting] = useState(false)
const [downloadingTemplate, setDownloadingTemplate] = useState(false)

  const loadTeachers = useCallback(async () => {
    setLoading(true)
    setListError(null)

    try {
      const response = await teacherApi.getAll({
        search,
        page,
        page_size: 15,
      })

      setTeachers(Array.isArray(response?.data) ? response.data : [])
      setMeta((current) => ({
        ...current,
        ...(response?.meta || {}),
      }))
    } catch (error) {
      setListError(error)
    } finally {
      setLoading(false)
    }
  }, [page, search])

  useEffect(() => {
    loadTeachers()
  }, [loadTeachers])

  function resetForm() {
    setForm(initialForm)
    setFieldErrors({})
    setSubmitError(null)
    setEditingTeacher(null)
  }

  function closeModal() {
    if (saving) return

    setModalOpen(false)
    resetForm()
  }

  async function openCreateModal() {
    resetForm()
    setSuccessMessage('')
    setAccounts([])
    setModalOpen(true)

    try {
      const response = await teacherApi.getAvailableAccounts()
      setAccounts(Array.isArray(response?.data) ? response.data : [])
    } catch (error) {
      setAccounts([])
      setSubmitError(error)
    }
  }

  async function openEditModal(teacher) {
    resetForm()
    setSuccessMessage('')
    setEditingTeacher(teacher)

    setForm({
      user_id: String(teacher.user_id ?? ''),
      nip: teacher.nip ?? '',
      nama_lengkap: teacher.nama_lengkap ?? '',
      jenis_kelamin: teacher.jenis_kelamin ?? '',
      no_telepon: teacher.no_telepon ?? '',
      alamat: teacher.alamat ?? '',
    })

    setModalOpen(true)

    try {
      const response = await teacherApi.getAvailableAccounts()
      const available = Array.isArray(response?.data) ? response.data : []
      const currentAccount = teacher.user

      if (
        currentAccount &&
        !available.some(
          (account) => String(account.id) === String(teacher.user_id),
        )
      ) {
        setAccounts([
          {
            id: teacher.user_id,
            username: currentAccount.username,
            name: currentAccount.name,
            email: currentAccount.email,
          },
          ...available,
        ])
      } else {
        setAccounts(available)
      }
    } catch (error) {
      setAccounts(
        teacher.user
          ? [
              {
                id: teacher.user_id,
                username: teacher.user.username,
                name: teacher.user.name,
                email: teacher.user.email,
              },
            ]
          : [],
      )
      setSubmitError(error)
    }
  }

  function handleChange(event) {
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
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setFieldErrors({})
    setSubmitError(null)

    const localErrors = {}

    if (!form.user_id) {
      localErrors.user_id = 'Akun Guru wajib dipilih.'
    }

    if (!form.nip.trim()) {
      localErrors.nip = 'NIP wajib diisi.'
    }

    if (!form.nama_lengkap.trim()) {
      localErrors.nama_lengkap = 'Nama lengkap wajib diisi.'
    }

    if (Object.keys(localErrors).length > 0) {
      setFieldErrors(localErrors)
      return
    }

    setSaving(true)

    const payload = {
      user_id: Number(form.user_id),
      nip: form.nip.trim(),
      nama_lengkap: form.nama_lengkap.trim(),
      jenis_kelamin: form.jenis_kelamin || null,
      no_telepon: form.no_telepon.trim() || null,
      alamat: form.alamat.trim() || null,
    }

    try {
      if (editingTeacher) {
        await teacherApi.update(editingTeacher.id, payload)
        setSuccessMessage('Data Guru berhasil diperbarui.')
      } else {
        await teacherApi.create(payload)
        setSuccessMessage('Data Guru berhasil ditambahkan.')
      }

      setModalOpen(false)
      resetForm()
      await loadTeachers()
    } catch (error) {
      setFieldErrors(error?.validationErrors || {})
      setSubmitError(error)
    } finally {
      setSaving(false)
    }
  }

  function handleDelete(teacher) {
    setDeleteError(null)
    setSuccessMessage('')
    setDeleteTarget(teacher)
  }

  function closeDeleteModal() {
    if (deletingId !== null) return
    setDeleteTarget(null)
    setDeleteError(null)
  }

  async function confirmDelete() {
    if (!deleteTarget || deletingId !== null) return

    const teacher = deleteTarget
    setDeletingId(teacher.id)
    setSuccessMessage('')
    setDeleteError(null)

    try {
      await teacherApi.delete(teacher.id)
      setDeleteTarget(null)
      setSuccessMessage(
        `Data Guru ${getTeacherName(teacher)} berhasil dihapus.`,
      )

      if (teachers.length === 1 && page > 1) {
        setPage((current) => current - 1)
      } else {
        await loadTeachers()
      }
    } catch (error) {
      setDeleteError({
        message:
          error?.message ||
          'Data guru gagal dihapus. Silakan coba kembali.',
      })
    } finally {
      setDeletingId(null)
    }
  }

  function openImportModal() {
  setSelectedExcel(null)
  setPreviewData(null)
  setImportError('')
  setImportModalOpen(true)
  setSuccessMessage('')
}

function closeImportModal() {
  if (previewLoading || importing) return

  setImportModalOpen(false)
  setSelectedExcel(null)
  setPreviewData(null)
  setImportError('')
}

async function handleDownloadTemplate() {
  setDownloadingTemplate(true)
  setImportError('')

  try {
    const blob = await teacherApi.downloadTemplate()

    downloadBlob(blob, 'template_data_guru.xlsx')
  } catch (error) {
    setImportError(
      getApiMessage(error, 'Template Excel gagal diunduh.'),
    )
  } finally {
    setDownloadingTemplate(false)
  }
}

function handleExcelChange(event) {
  const file = event.target.files?.[0] || null

  setSelectedExcel(file)
  setPreviewData(null)
  setImportError('')

  if (!file) return

  const extension = file.name.split('.').pop()?.toLowerCase()

  if (extension !== 'xlsx') {
    setSelectedExcel(null)
    setImportError('Format file harus .xlsx.')
    event.target.value = ''
    return
  }

  if (file.size > 2 * 1024 * 1024) {
    setSelectedExcel(null)
    setImportError('Ukuran file maksimal 2 MB.')
    event.target.value = ''
  }
}

async function handlePreviewImport() {
  if (!selectedExcel) {
    setImportError('Pilih file Excel terlebih dahulu.')
    return
  }

  const formData = new FormData()
  formData.append('file', selectedExcel)

  setPreviewLoading(true)
  setImportError('')
  setPreviewData(null)

  try {
    const response = await teacherApi.previewImport(formData)

    setPreviewData(response?.data || null)

    if (!response?.data?.rows) {
      setImportError('Respons preview tidak memiliki data baris.')
    }
  } catch (error) {
    const responseData =
      error?.payload?.data ||
      error?.response?.data?.data ||
      error?.data?.data

    if (responseData?.rows) {
      setPreviewData(responseData)
    }

    setImportError(
      getApiMessage(error, 'Preview Excel gagal dilakukan.'),
    )
  } finally {
    setPreviewLoading(false)
  }
}

async function handleConfirmImport() {
  if (!selectedExcel || !previewData) return

  if (previewData.summary?.invalid_rows > 0) {
    setImportError(
      'Masih terdapat baris yang tidak valid. Perbaiki file Excel terlebih dahulu.',
    )
    return
  }

  if (previewData.summary?.valid_rows === 0) {
    setImportError('Tidak ada data Guru valid untuk diimpor.')
    return
  }

  const confirmed = window.confirm(
    `Impor ${previewData.summary.valid_rows} data Guru ke dalam sistem?`,
  )

  if (!confirmed) return

  const formData = new FormData()
  formData.append('file', selectedExcel)

  setImporting(true)
  setImportError('')

  try {
    const response = await teacherApi.importExcel(formData)

    const importedRows =
      response?.data?.imported_rows ??
      previewData.summary.valid_rows

    setImportModalOpen(false)
    setSelectedExcel(null)
    setPreviewData(null)

    setSuccessMessage(
      `${importedRows} data Guru berhasil diimpor.`,
    )

    setPage(1)

    if (page === 1) {
      await loadTeachers()
    }
  } catch (error) {
    setImportError(
      getApiMessage(error, 'Import Data Guru gagal dilakukan.'),
    )

    // Jika backend mengembalikan hasil validasi terbaru,
    // tampilkan kembali hasil tersebut.
    const responseData =
      error?.payload?.data ||
      error?.response?.data?.data ||
      error?.data?.data

    if (responseData?.rows) {
      setPreviewData(responseData)
    }
  } finally {
    setImporting(false)
  }
}

  const accountOptions = accounts.map((account) => ({
    value: String(account.id),
    label: `${account.name || account.username} (${account.username})`,
  }))

  return (
    <section className="teachers-page">
      <PageHeader
  title="Data Guru"
  description="Kelola identitas dan informasi dasar Guru."
  actions={
    canManage ? (
      <div className="teachers-page__header-actions">
        <Button
          type="button"
          onClick={handleDownloadTemplate}
          loading={downloadingTemplate}
          disabled={downloadingTemplate}
        >
          Download Template
        </Button>

        <Button
          type="button"
          onClick={openImportModal}
        >
          Import Excel
        </Button>

        <Button
          type="button"
          onClick={openCreateModal}
        >
          + Tambah Guru
        </Button>
      </div>
    ) : null
  }
/>

      {successMessage && (
        <div className="teachers-page__success" role="status" aria-live="polite">
          {successMessage}
        </div>
      )}

      <Card className="teachers-page__card">
        <div className="teachers-page__list-header">
          <div>
            <p className="teachers-page__eyebrow">Data akademik</p>
            <h2>Daftar Guru</h2>
            <p>Data identitas Guru yang tersimpan di sistem.</p>
          </div>

          <span className="teachers-page__count">{meta.total} Guru</span>
        </div>

        <form className="teachers-page__search" onSubmit={handleSearch}>
          <Input
            id="teacher-search"
            name="search"
            label="Cari Guru"
            value={searchInput}
            placeholder="Cari nama atau NIP"
            onChange={(event) => setSearchInput(event.target.value)}
          />

          <div className="teachers-page__search-actions">
            <Button type="submit">Cari</Button>
            <Button
              type="button"
              onClick={() => {
                setSearchInput('')
                setSearch('')
                setPage(1)
              }}
            >
              Reset
            </Button>
          </div>
        </form>

        {loading && <LoadingState message="Memuat data Guru..." />}

        {!loading && listError && (
          <ErrorState
            message={getApiMessage(
              listError,
              'Data Guru tidak dapat dimuat.',
            )}
          />
        )}

        {!loading && !listError && teachers.length === 0 && (
          <EmptyState
            title="Belum ada data Guru"
            description={
              search
                ? 'Tidak ditemukan Guru yang sesuai dengan pencarian.'
                : 'Data Guru belum tersedia.'
            }
          />
        )}

        {!loading && !listError && teachers.length > 0 && (
          <>
            <Table
              columns={columns}
              data={teachers}
              rowKey="id"
              renderCell={(teacher, column) => {
                switch (column.key) {
                  case 'identity':
                    return (
                      <div className="teachers-page__identity">
                        <strong>{getTeacherName(teacher)}</strong>
                        <span>{teacher.user?.username || '-'}</span>
                        <small>{teacher.user?.email || '-'}</small>
                      </div>
                    )

                  case 'nip':
                    return teacher.nip || '-'

                  case 'gender':
                    if (teacher.jenis_kelamin === 'L') return 'Laki-laki'
                    if (teacher.jenis_kelamin === 'P') return 'Perempuan'
                    return teacher.jenis_kelamin || '-'

                  case 'status':
                    return teacher.user?.account_status === 'active' ? (
                      <Badge variant="success">Aktif</Badge>
                    ) : (
                      <Badge variant="neutral">
                        {teacher.user?.account_status || 'Tidak diketahui'}
                      </Badge>
                    )

                  case 'actions':
                    return canManage ? (
                      <div className="teachers-page__actions">
                        <Button
                          type="button"
                          disabled={deletingId !== null}
                          onClick={() => openEditModal(teacher)}
                        >
                          Edit
                        </Button>

                        <Button
                          type="button"
                          loading={deletingId === teacher.id}
                          disabled={deletingId !== null}
                          onClick={() => handleDelete(teacher)}
                        >
                          Hapus
                        </Button>
                      </div>
                    ) : (
                      <Badge variant="neutral">Lihat</Badge>
                    )

                  default:
                    return '-'
                }
              }}
            />

            <div className="teachers-page__pagination">
              <span>
                Halaman {meta.current_page} dari {meta.last_page} ·{' '}
                {meta.total} Guru
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
                    setPage((current) => Math.min(meta.last_page, current + 1))
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
        open={importModalOpen}
        title="Import Data Guru"
        description="Unggah file Excel untuk menambahkan data Guru secara massal."
        onClose={closeImportModal}
        size="large"
        footer={
          <>
            <Button
              type="button"
              disabled={previewLoading || importing}
              onClick={closeImportModal}
            >
              Tutup
            </Button>

            {!previewData && (
              <Button
                type="button"
                loading={previewLoading}
                disabled={!selectedExcel || previewLoading}
                onClick={handlePreviewImport}
              >
                Preview Data
              </Button>
            )}

            {previewData && (
              <Button
                type="button"
                loading={importing}
                disabled={
                  importing ||
                  previewLoading ||
                  previewData.summary?.invalid_rows > 0 ||
                  previewData.summary?.valid_rows === 0
                }
                onClick={handleConfirmImport}
              >
                Konfirmasi Import
              </Button>
            )}
          </>
        }
      >
        <div className="teachers-page__import-card">
          <div className="teachers-page__import-heading">
            <h3>Petunjuk Import</h3>
            <p>
              Gunakan template resmi Data Guru. Pastikan username
              merupakan akun aktif dengan role GURU dan belum
              memiliki profil Guru.
            </p>
          </div>

          <div className="teachers-page__import-info">
            <strong>Ketentuan file:</strong>
            <br />
            Format: .xlsx
            <br />
            Ukuran maksimal: 2 MB
            <br />
            Maksimal data: 1.000 baris
            <br />
            Kolom wajib: username, nip, nama_lengkap.
          </div>

          <div className="teachers-page__file-control">
            <label
              htmlFor="teacher-excel-file"
              className="teachers-page__file-label"
            >
              Pilih File Excel
            </label>

            <input
              id="teacher-excel-file"
              className="teachers-page__file-input"
              type="file"
              accept=".xlsx"
              onChange={handleExcelChange}
              disabled={previewLoading || importing}
            />

            {selectedExcel && (
              <p className="teachers-page__selected-file">
                File dipilih: <strong>{selectedExcel.name}</strong>
              </p>
            )}
          </div>

          {importError && (
            <div
              className="teachers-page__import-error"
              role="alert"
            >
              {importError}
            </div>
          )}

          {previewData && (
            <div className="teachers-page__preview">
              <div className="teachers-page__preview-heading">
                <h3>Hasil Preview Import</h3>
                <p>
                  Periksa hasil validasi sebelum mengimpor data.
                </p>
              </div>

              <div className="teachers-page__preview-summary">
                <div className="teachers-page__preview-stat">
                  <span>Total Data</span>
                  <strong>
                    {previewData.summary?.total_rows ?? 0}
                  </strong>
                </div>

                <div className="teachers-page__preview-stat teachers-page__preview-stat--valid">
                  <span>Data Valid</span>
                  <strong>
                    {previewData.summary?.valid_rows ?? 0}
                  </strong>
                </div>

                <div className="teachers-page__preview-stat teachers-page__preview-stat--invalid">
                  <span>Data Tidak Valid</span>
                  <strong>
                    {previewData.summary?.invalid_rows ?? 0}
                  </strong>
                </div>
              </div>

              <div className="teachers-page__preview-table-wrapper">
                <table className="teachers-page__preview-table">
                  <thead>
                    <tr>
                      <th>Baris</th>
                      <th>Username</th>
                      <th>NIP</th>
                      <th>Nama Lengkap</th>
                      <th>Status</th>
                      <th>Keterangan</th>
                    </tr>
                  </thead>

                  <tbody>
                    {(previewData.rows || []).map((row) => (
                      <tr key={row.row}>
                        <td>{row.row}</td>
                        <td>{row.username || '-'}</td>
                        <td>{row.nip || '-'}</td>
                        <td>{row.nama_lengkap || '-'}</td>
                        <td>
                          <span
                            className={`teachers-page__preview-status ${
                              row.valid
                                ? 'teachers-page__preview-status--valid'
                                : 'teachers-page__preview-status--invalid'
                            }`}
                          >
                            {row.valid ? 'Valid' : 'Tidak Valid'}
                          </span>
                        </td>
                        <td className="teachers-page__preview-error">
                          {row.errors?.length > 0
                            ? row.errors.join(' ')
                            : '-'}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              {previewData.summary?.invalid_rows > 0 && (
                <div className="teachers-page__preview-note">
                  Import belum dapat dilakukan karena masih ada
                  {` ${previewData.summary.invalid_rows} `}
                  baris yang tidak valid. Perbaiki file Excel,
                  kemudian unggah ulang untuk melakukan preview.
                </div>
              )}

              {previewData.summary?.invalid_rows === 0 &&
                previewData.summary?.valid_rows > 0 && (
                  <div className="teachers-page__import-info">
                    Semua data telah lolos validasi. Kamu dapat
                    melanjutkan dengan menekan tombol
                    <strong> Konfirmasi Import</strong>.
                  </div>
                )}
            </div>
          )}
        </div>
      </Modal>

      <Modal
        open={Boolean(deleteTarget)}
        title="Hapus Data Guru"
        description="Konfirmasi penghapusan data guru."
        onClose={closeDeleteModal}
        size="small"
        footer={
          <>
            <Button
              type="button"
              disabled={deletingId !== null}
              onClick={closeDeleteModal}
            >
              Batal
            </Button>
            <button
              type="button"
              className="teachers-page__delete-confirm"
              disabled={deletingId !== null}
              onClick={confirmDelete}
            >
              {deletingId !== null ? 'Menghapus...' : 'Hapus Guru'}
            </button>
          </>
        }
      >
        <div className="teachers-page__delete-content">
          <div className="teachers-page__delete-icon" aria-hidden="true">
            <span>!</span>
          </div>
          <p>
            Apakah Anda yakin ingin menghapus data guru{' '}
            <strong>{deleteTarget ? getTeacherName(deleteTarget) : ''}</strong>?
          </p>
          <p className="teachers-page__delete-warning">
            Tindakan ini tidak dapat dibatalkan.
          </p>
          {deleteError && (
            <div className="teachers-page__delete-error" role="alert">
              <ErrorState
                message={getApiMessage(
                  deleteError,
                  'Data guru gagal dihapus. Silakan coba kembali.',
                )}
              />
            </div>
          )}
        </div>
      </Modal>

      <Modal
        open={modalOpen}
        title={editingTeacher ? 'Edit Data Guru' : 'Tambah Guru'}
        description={
          editingTeacher
            ? 'Perbarui informasi Guru yang dipilih.'
            : 'Pilih akun Guru, lalu lengkapi identitasnya.'
        }
        onClose={closeModal}
        size="large"
        footer={
          <>
            <Button type="button" disabled={saving} onClick={closeModal}>
              Batal
            </Button>

            <Button type="submit" form="teacher-form" loading={saving}>
              {editingTeacher ? 'Simpan Perubahan' : 'Simpan Guru'}
            </Button>
          </>
        }
      >
        {submitError && (
          <div className="teachers-page__form-error">
            <ErrorState
              message={getApiMessage(
                submitError,
                'Data Guru gagal disimpan.',
              )}
            />
          </div>
        )}

        <form id="teacher-form" onSubmit={handleSubmit}>
          <div className="teachers-page__form-grid">
            <Select
              id="user_id"
              name="user_id"
              label="Akun Guru"
              value={form.user_id}
              required
              disabled={saving || Boolean(editingTeacher)}
              placeholder="Pilih akun Guru"
              options={accountOptions}
              error={getFieldError(fieldErrors, 'user_id')}
              hint="Akun aktif dengan role Guru yang belum memiliki profil."
              onChange={handleChange}
            />

            <Input
              id="nip"
              name="nip"
              label="NIP"
              value={form.nip}
              required
              disabled={saving}
              error={getFieldError(fieldErrors, 'nip')}
              onChange={handleChange}
            />

            <Input
              id="nama_lengkap"
              name="nama_lengkap"
              label="Nama Lengkap"
              value={form.nama_lengkap}
              required
              disabled={saving}
              error={getFieldError(fieldErrors, 'nama_lengkap')}
              onChange={handleChange}
            />

            <Select
              id="jenis_kelamin"
              name="jenis_kelamin"
              label="Jenis Kelamin"
              value={form.jenis_kelamin}
              disabled={saving}
              options={[
                { value: 'L', label: 'Laki-laki' },
                { value: 'P', label: 'Perempuan' },
              ]}
              placeholder="Pilih jenis kelamin"
              error={getFieldError(fieldErrors, 'jenis_kelamin')}
              onChange={handleChange}
            />

            <Input
              id="no_telepon"
              name="no_telepon"
              label="Nomor Telepon"
              type="tel"
              inputMode="tel"
              maxLength={30}
              value={form.no_telepon}
              disabled={saving}
              error={getFieldError(fieldErrors, 'no_telepon')}
              onChange={handleChange}
            />

            <div className="teachers-page__full-width">
              <Input
                id="alamat"
                name="alamat"
                label="Alamat"
                value={form.alamat}
                disabled={saving}
                error={getFieldError(fieldErrors, 'alamat')}
                onChange={handleChange}
              />
            </div>
          </div>
        </form>
      </Modal>
    </section>
  )
}

function downloadBlob(blob, filename) {
  const url = window.URL.createObjectURL(blob)
  const link = document.createElement('a')

  link.href = url
  link.download = filename

  document.body.appendChild(link)
  link.click()
  link.remove()

  window.URL.revokeObjectURL(url)
}
