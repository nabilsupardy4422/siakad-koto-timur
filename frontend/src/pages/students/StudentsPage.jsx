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

import { studentApi } from '../../services/studentApi'

import './StudentsPage.css'

const initialForm = {
  user_id: '',
  nisn: '',
  nis: '',
  nama_lengkap: '',
  jenis_kelamin: '',
  tanggal_lahir: '',
  tempat_lahir: '',
  no_telepon: '',
  alamat: '',
}

const columns = [
  { key: 'identity', label: 'Identitas Siswa' },
  { key: 'nisn', label: 'NISN' },
  { key: 'nis', label: 'NIS' },
  { key: 'gender', label: 'Jenis Kelamin' },
  { key: 'actions', label: 'Aksi' },
]

function getFieldError(errors, field) {
  const value = errors?.[field]
  return Array.isArray(value) ? value[0] || '' : value || ''
}

function getStudentName(student) {
  return student?.nama_lengkap || student?.user?.name || '-'
}

function getApiMessage(error, fallback) {
  return error?.message || fallback
}

export default function StudentsPage() {
  const { role, permissions = [] } = useAuth()
  const canManage =
    role?.code === 'TU' || permissions.includes('students.create')

  const [students, setStudents] = useState([])
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
  const [modalOpen, setModalOpen] = useState(false)
  const [editingStudent, setEditingStudent] = useState(null)
  const [form, setForm] = useState(initialForm)
  const [fieldErrors, setFieldErrors] = useState({})
  const [submitError, setSubmitError] = useState(null)
  const [successMessage, setSuccessMessage] = useState('')
  const [saving, setSaving] = useState(false)
  const [deletingId, setDeletingId] = useState(null)
  const [studentToDelete, setStudentToDelete] = useState(null)

  const loadStudents = useCallback(async () => {
    setLoading(true)
    setListError(null)

    try {
      const response = await studentApi.getAll({
        search,
        page,
        page_size: 15,
      })

      setStudents(Array.isArray(response?.data) ? response.data : [])
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
    loadStudents()
  }, [loadStudents])

  function resetForm() {
    setForm(initialForm)
    setFieldErrors({})
    setSubmitError(null)
    setEditingStudent(null)
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
      const response = await studentApi.getAvailableAccounts()
      setAccounts(Array.isArray(response?.data) ? response.data : [])
    } catch (error) {
      setAccounts([])
      setSubmitError(error)
    }
  }

  async function openEditModal(student) {
    resetForm()
    setSuccessMessage('')
    setEditingStudent(student)

    setForm({
      user_id: String(student.user_id ?? ''),
      nisn: student.nisn ?? '',
      nis: student.nis ?? '',
      nama_lengkap: student.nama_lengkap ?? '',
      jenis_kelamin: student.jenis_kelamin ?? '',
      tanggal_lahir: student.tanggal_lahir
        ? String(student.tanggal_lahir).slice(0, 10)
        : '',
      tempat_lahir: student.tempat_lahir ?? '',
      no_telepon: student.no_telepon ?? '',
      alamat: student.alamat ?? '',
    })

    setModalOpen(true)

    try {
      const response = await studentApi.getAvailableAccounts()
      const available = Array.isArray(response?.data) ? response.data : []
      const currentAccount = student.user

      if (
        currentAccount &&
        !available.some(
          (account) => String(account.id) === String(student.user_id),
        )
      ) {
        setAccounts([
          {
            id: student.user_id,
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
        student.user
          ? [
              {
                id: student.user_id,
                username: student.user.username,
                name: student.user.name,
                email: student.user.email,
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
      localErrors.user_id = 'Akun siswa wajib dipilih.'
    }

    if (!form.nisn.trim()) {
      localErrors.nisn = 'NISN wajib diisi.'
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
      nisn: form.nisn.trim(),
      nis: form.nis.trim() || null,
      nama_lengkap: form.nama_lengkap.trim(),
      jenis_kelamin: form.jenis_kelamin || null,
      tanggal_lahir: form.tanggal_lahir || null,
      tempat_lahir: form.tempat_lahir.trim() || null,
      no_telepon: form.no_telepon.trim() || null,
      alamat: form.alamat.trim() || null,
    }

    try {
      if (editingStudent) {
        await studentApi.update(editingStudent.id, payload)
        setSuccessMessage('Data siswa berhasil diperbarui.')
      } else {
        await studentApi.create(payload)
        setSuccessMessage('Data siswa berhasil ditambahkan.')
      }

      setModalOpen(false)
      resetForm()
      await loadStudents()
    } catch (error) {
      setFieldErrors(error?.validationErrors || {})
      setSubmitError(error)
    } finally {
      setSaving(false)
    }
  }

  function openDeleteConfirmation(student) {
    setSuccessMessage('')
    setListError(null)
    setStudentToDelete(student)
  }

  function closeDeleteConfirmation() {
    if (deletingId !== null) return
    setStudentToDelete(null)
  }

  async function confirmDeleteStudent() {
    if (!studentToDelete || deletingId !== null) return

    const student = studentToDelete
    setDeletingId(student.id)
    setSuccessMessage('')
    setListError(null)

    try {
      await studentApi.delete(student.id)
      setStudentToDelete(null)
      setSuccessMessage(
        `Data siswa ${getStudentName(student)} berhasil dihapus.`,
      )

      if (students.length === 1 && page > 1) {
        setPage((current) => current - 1)
      } else {
        await loadStudents()
      }
    } catch (error) {
      setListError(error)
      setStudentToDelete(null)
    } finally {
      setDeletingId(null)
    }
  }

  const accountOptions = accounts.map((account) => ({
    value: String(account.id),
    label: `${account.name || account.username} (${account.username})`,
  }))

  return (
    <section className="students-page">
      <PageHeader
        title="Data Siswa"
        description="Kelola identitas dan informasi dasar siswa."
        actions={
          canManage ? (
            <Button onClick={openCreateModal}>+ Tambah Siswa</Button>
          ) : null
        }
      />

      {successMessage && (
        <div
          className="students-page__success"
          role="status"
          aria-live="polite"
        >
          {successMessage}
        </div>
      )}

      <Card className="students-page__card">
        <div className="students-page__list-header">
          <div>
            <p className="students-page__eyebrow">Data akademik</p>
            <h2>Daftar Siswa</h2>
            <p>Data identitas siswa yang tersimpan di sistem.</p>
          </div>

          <span className="students-page__count">{meta.total} siswa</span>
        </div>

        <form className="students-page__search" onSubmit={handleSearch}>
          <Input
            id="student-search"
            name="search"
            label="Cari siswa"
            value={searchInput}
            placeholder="Cari nama, NISN, atau NIS"
            onChange={(event) => setSearchInput(event.target.value)}
          />

          <div className="students-page__search-actions">
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

        {loading && <LoadingState message="Memuat data siswa..." />}

        {!loading && listError && (
          <ErrorState
            message={getApiMessage(
              listError,
              'Data siswa tidak dapat dimuat.',
            )}
          />
        )}

        {!loading && !listError && students.length === 0 && (
          <EmptyState
            title="Belum ada data siswa"
            description={
              search
                ? 'Tidak ditemukan siswa yang sesuai dengan pencarian.'
                : 'Data siswa belum tersedia.'
            }
          />
        )}

        {!loading && !listError && students.length > 0 && (
          <>
            <Table
              columns={columns}
              data={students}
              rowKey="id"
              renderCell={(student, column) => {
                switch (column.key) {
                  case 'identity':
                    return (
                      <div className="students-page__identity">
                        <strong>{getStudentName(student)}</strong>
                        <span>{student.user?.username || '-'}</span>
                        <small>{student.user?.email || '-'}</small>
                      </div>
                    )

                  case 'nisn':
                    return student.nisn || '-'

                  case 'nis':
                    return student.nis || '-'

                  case 'gender':
                    return student.jenis_kelamin || '-'

                  case 'actions':
                    return canManage ? (
                      <div className="students-page__actions">
                        <Button
                          type="button"
                          disabled={deletingId !== null}
                          onClick={() => openEditModal(student)}
                        >
                          Edit
                        </Button>

                        <Button
                          type="button"
                          loading={deletingId === student.id}
                          disabled={deletingId !== null}
                          onClick={() => openDeleteConfirmation(student)}
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

            <div className="students-page__pagination">
              <span>
                Halaman {meta.current_page} dari {meta.last_page} ·{' '}
                {meta.total} siswa
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
        open={modalOpen}
        title={editingStudent ? 'Edit Data Siswa' : 'Tambah Siswa'}
        description={
          editingStudent
            ? 'Perbarui informasi siswa yang dipilih.'
            : 'Pilih akun siswa, lalu lengkapi identitasnya.'
        }
        onClose={closeModal}
        size="large"
        footer={
          <>
            <Button type="button" disabled={saving} onClick={closeModal}>
              Batal
            </Button>

            <Button type="submit" form="student-form" loading={saving}>
              {editingStudent ? 'Simpan Perubahan' : 'Simpan Siswa'}
            </Button>
          </>
        }
      >
        {submitError && (
          <div className="students-page__form-error">
            <ErrorState
              message={getApiMessage(
                submitError,
                'Data siswa gagal disimpan.',
              )}
            />
          </div>
        )}

        <form id="student-form" onSubmit={handleSubmit}>
          <div className="students-page__form-grid">
            <Select
              id="user_id"
              name="user_id"
              label="Akun Siswa"
              value={form.user_id}
              required
              disabled={saving || Boolean(editingStudent)}
              placeholder="Pilih akun siswa"
              options={accountOptions}
              error={getFieldError(fieldErrors, 'user_id')}
              hint="Akun aktif dengan role Siswa yang belum memiliki profil."
              onChange={handleChange}
            />

            <Input
              id="nisn"
              name="nisn"
              label="NISN"
              type="text"
              inputMode="numeric"
              maxLength={50}
              value={form.nisn}
              required
              disabled={saving}
              error={getFieldError(fieldErrors, 'nisn')}
              onChange={handleChange}
            />

            <Input
              id="nis"
              name="nis"
              label="NIS"
              type="text"
              inputMode="numeric"
              maxLength={50}
              value={form.nis}
              disabled={saving}
              error={getFieldError(fieldErrors, 'nis')}
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
              id="tanggal_lahir"
              name="tanggal_lahir"
              label="Tanggal Lahir"
              type="date"
              value={form.tanggal_lahir}
              disabled={saving}
              error={getFieldError(fieldErrors, 'tanggal_lahir')}
              onChange={handleChange}
            />

            <Input
              id="tempat_lahir"
              name="tempat_lahir"
              label="Tempat Lahir"
              value={form.tempat_lahir}
              disabled={saving}
              error={getFieldError(fieldErrors, 'tempat_lahir')}
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

            <div className="students-page__full-width">
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

      <Modal
        open={Boolean(studentToDelete)}
        title="Hapus Data Siswa?"
        description="Konfirmasi penghapusan data siswa."
        onClose={closeDeleteConfirmation}
        size="small"
        footer={
          <>
            <Button
              type="button"
              disabled={deletingId !== null}
              onClick={closeDeleteConfirmation}
            >
              Batal
            </Button>
            <Button
              type="button"
              className="students-page__delete-confirm"
              loading={deletingId !== null}
              disabled={deletingId !== null}
              onClick={confirmDeleteStudent}
            >
              {deletingId !== null ? 'Menghapus...' : 'Hapus Siswa'}
            </Button>
          </>
        }
      >
        {studentToDelete && (
          <div className="students-page__delete-content">
            <div className="students-page__delete-icon" aria-hidden="true">
              !
            </div>
            <p>
              Apakah kamu yakin ingin menghapus data siswa{' '}
              <strong>{getStudentName(studentToDelete)}</strong>?
            </p>
            <p className="students-page__delete-warning">
              Tindakan ini tidak dapat dibatalkan.
            </p>
            {listError && (
              <div className="students-page__delete-error" role="alert">
                <ErrorState
                  message={getApiMessage(
                    listError,
                    'Data siswa gagal dihapus. Silakan coba lagi.',
                  )}
                />
              </div>
            )}
          </div>
        )}
      </Modal>
    </section>
  )
}
