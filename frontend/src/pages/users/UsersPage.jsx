
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

import { userApi } from '../../services/userApi'

import './UsersPage.css'

const ROLES = [
  { value: '1', label: 'Tata Usaha' },
  { value: '2', label: 'Kepala Sekolah' },
  { value: '3', label: 'Guru' },
  { value: '4', label: 'Siswa' },
]

const initialForm = {
  username: '',
  name: '',
  email: '',
  role_id: '',
  password: '',
}

const columns = [
  { key: 'identity', label: 'Identitas Pengguna' },
  { key: 'role', label: 'Role' },
  { key: 'status', label: 'Status Akun' },
  { key: 'google', label: 'Google' },
  { key: 'actions', label: 'Aksi' },
]

function getFieldError(errors, field) {
  const value = errors?.[field]
  return Array.isArray(value) ? value[0] || '' : value || ''
}

function getApiMessage(error, fallback) {
  return error?.message || fallback
}

function getRoleName(user) {
  return user?.role?.name ||
    ROLES.find((role) => role.value === String(user?.role_id))?.label ||
    '-'
}

export default function UsersPage() {
  const { role, permissions = [] } = useAuth()

  const roleCode =
    typeof role === 'string'
      ? role
      : role?.code || null

  const canView =
    roleCode === 'TU' || permissions.includes('users.view')

  const canCreate =
    roleCode === 'TU' || permissions.includes('users.create')

  const canUpdate =
    roleCode === 'TU' || permissions.includes('users.update')

  const canActivate =
    roleCode === 'TU' || permissions.includes('users.activate')

  const canDeactivate =
    roleCode === 'TU' || permissions.includes('users.deactivate')

  const [users, setUsers] = useState([])
  const [searchInput, setSearchInput] = useState('')
  const [search, setSearch] = useState('')
  const [roleFilter, setRoleFilter] = useState('')
  const [statusFilter, setStatusFilter] = useState('')
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
  const [editingUser, setEditingUser] = useState(null)
  const [form, setForm] = useState(initialForm)
  const [fieldErrors, setFieldErrors] = useState({})
  const [submitError, setSubmitError] = useState(null)
  const [successMessage, setSuccessMessage] = useState('')
  const [saving, setSaving] = useState(false)
  const [processingId, setProcessingId] = useState(null)
  const [userToToggle, setUserToToggle] = useState(null)

  const loadUsers = useCallback(async () => {
    if (!canView) {
      setUsers([])
      setLoading(false)
      return
    }

    setLoading(true)
    setListError(null)

    try {
      const response = await userApi.getAll({
        search,
        role_id: roleFilter,
        account_status: statusFilter,
        page,
        page_size: 15,
      })

      setUsers(Array.isArray(response?.data) ? response.data : [])
      setMeta((current) => ({
        ...current,
        ...(response?.meta || {}),
      }))
    } catch (error) {
      setListError(error)
    } finally {
      setLoading(false)
    }
  }, [canView, page, roleFilter, search, statusFilter])

  useEffect(() => {
    loadUsers()
  }, [loadUsers])

  function resetForm() {
    setForm(initialForm)
    setFieldErrors({})
    setSubmitError(null)
    setEditingUser(null)
  }

  function closeModal() {
    if (saving) return

    setModalOpen(false)
    resetForm()
  }

  function openCreateModal() {
    resetForm()
    setSuccessMessage('')
    setModalOpen(true)
  }

  function openEditModal(user) {
    resetForm()
    setSuccessMessage('')
    setEditingUser(user)

    setForm({
      username: user.username || '',
      name: user.name || '',
      email: user.email || '',
      role_id: String(user.role_id ?? ''),
      password: '',
    })

    setModalOpen(true)
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

  function resetFilters() {
    setSearchInput('')
    setSearch('')
    setRoleFilter('')
    setStatusFilter('')
    setPage(1)
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setFieldErrors({})
    setSubmitError(null)

    const localErrors = {}

    if (!form.username.trim()) {
      localErrors.username = 'Username wajib diisi.'
    }

    if (!form.name.trim()) {
      localErrors.name = 'Nama pengguna wajib diisi.'
    }

    if (!form.role_id) {
      localErrors.role_id = 'Role wajib dipilih.'
    }

    if (!editingUser && !form.password) {
      localErrors.password = 'Password wajib diisi.'
    }

    if (Object.keys(localErrors).length > 0) {
      setFieldErrors(localErrors)
      return
    }

    const payload = {
      username: form.username.trim(),
      name: form.name.trim(),
      email: form.email.trim() || null,
      role_id: Number(form.role_id),
    }

    if (!editingUser) {
      payload.password = form.password
    }

    setSaving(true)

    try {
      if (editingUser) {
        await userApi.update(editingUser.id, payload)
        setSuccessMessage('Data pengguna berhasil diperbarui.')
      } else {
        await userApi.create(payload)
        setSuccessMessage('Akun pengguna berhasil dibuat.')
        setPage(1)
        setSearch('')
        setSearchInput('')
        setRoleFilter('')
        setStatusFilter('')
      }

      setModalOpen(false)
      resetForm()
      await loadUsers()
    } catch (error) {
      setFieldErrors(error?.validationErrors || error?.errors || {})
      setSubmitError(error)
    } finally {
      setSaving(false)
    }
  }

  // Tombol status hanya membuka modal konfirmasi buatan aplikasi.
  function handleToggleStatus(user) {
    setUserToToggle(user)
    setSuccessMessage('')
  }

  // Perubahan status hanya dikirim ke API setelah pengguna mengonfirmasi.
  async function confirmToggleStatus() {
    if (!userToToggle || processingId !== null) return

    const user = userToToggle
    const isActive = user.account_status === 'active'

    setProcessingId(user.id)
    setSuccessMessage('')
    setListError(null)

    try {
      if (isActive) {
        await userApi.deactivate(user.id)
        setSuccessMessage(`Akun ${user.username} berhasil dinonaktifkan.`)
      } else {
        await userApi.activate(user.id)
        setSuccessMessage(`Akun ${user.username} berhasil diaktifkan.`)
      }

      setUserToToggle(null)
      await loadUsers()
    } catch (error) {
      setListError(error)
    } finally {
      setProcessingId(null)
    }
  }

  if (!canView) {
    return (
      <section className="users-page">
        <PageHeader
          title="Manajemen Pengguna"
          description="Pengelolaan akun pengguna SIAKAD."
        />
        <ErrorState message="Kamu tidak memiliki akses untuk melihat data pengguna." />
      </section>
    )
  }

  return (
    <section className="users-page">
      <PageHeader
        title="Manajemen Pengguna"
        description="Kelola akun, role, dan status akses pengguna SIAKAD."
        actions={
          canCreate ? (
            <Button onClick={openCreateModal}>
              + Tambah Pengguna
            </Button>
          ) : null
        }
      />

      {successMessage && (
        <div className="users-page__success" role="status" aria-live="polite">
          {successMessage}
        </div>
      )}

      <Card className="users-page__card">
        <div className="users-page__list-header">
          <div>
            <p className="users-page__eyebrow">Administrasi akun</p>
            <h2>Daftar Pengguna</h2>
            <p>Informasi akun dan hak akses yang terdaftar di sistem.</p>
          </div>

          <span className="users-page__count">
            {meta.total} Pengguna
          </span>
        </div>

        <form className="users-page__filters" onSubmit={handleSearch}>
          <Input
            id="user-search"
            name="search"
            label="Cari pengguna"
            value={searchInput}
            placeholder="Username, nama, atau email"
            onChange={(event) => setSearchInput(event.target.value)}
          />

          <Select
            id="user-role-filter"
            name="role_id"
            label="Role"
            value={roleFilter}
            placeholder="Semua role"
            options={ROLES}
            onChange={(event) => {
              setRoleFilter(event.target.value)
              setPage(1)
            }}
          />

          <Select
            id="user-status-filter"
            name="account_status"
            label="Status akun"
            value={statusFilter}
            placeholder="Semua status"
            options={[
              { value: 'active', label: 'Aktif' },
              { value: 'inactive', label: 'Tidak Aktif' },
            ]}
            onChange={(event) => {
              setStatusFilter(event.target.value)
              setPage(1)
            }}
          />

          <div className="users-page__filter-actions">
            <Button type="submit">Cari</Button>
            <Button type="button" onClick={resetFilters}>
              Reset
            </Button>
          </div>
        </form>

        {loading && <LoadingState message="Memuat data pengguna..." />}

        {!loading && listError && (
          <ErrorState
            message={getApiMessage(
              listError,
              'Data pengguna tidak dapat dimuat.',
            )}
          />
        )}

        {!loading && !listError && users.length === 0 && (
          <EmptyState
            title="Belum ada data pengguna"
            description={
              search || roleFilter || statusFilter
                ? 'Tidak ditemukan pengguna yang sesuai dengan filter.'
                : 'Belum ada akun pengguna yang terdaftar.'
            }
          />
        )}

        {!loading && !listError && users.length > 0 && (
          <>
            <Table
              columns={columns}
              data={users}
              rowKey="id"
              renderCell={(user, column) => {
                switch (column.key) {
                  case 'identity':
                    return (
                      <div className="users-page__identity">
                        <strong>{user.name || '-'}</strong>
                        <span>{user.username || '-'}</span>
                        <small>{user.email || 'Email belum diisi'}</small>
                      </div>
                    )

                  case 'role':
                    return getRoleName(user)

                  case 'status':
                    return user.account_status === 'active' ? (
                      <Badge variant="success">Aktif</Badge>
                    ) : (
                      <Badge variant="neutral">Tidak Aktif</Badge>
                    )

                  case 'google':
                    return user.google_linked ? (
                      <Badge variant="success">Terhubung</Badge>
                    ) : (
                      <Badge variant="neutral">Belum terhubung</Badge>
                    )

                  case 'actions':
                    return (
                      <div className="users-page__actions">
                        {canUpdate && (
                          <Button
                            type="button"
                            disabled={processingId !== null}
                            onClick={() => openEditModal(user)}
                          >
                            Edit
                          </Button>
                        )}

                        {user.account_status === 'active' && canDeactivate && (
                          <Button
                            type="button"
                            disabled={processingId !== null}
                            loading={processingId === user.id}
                            onClick={() => handleToggleStatus(user)}
                          >
                            Nonaktifkan
                          </Button>
                        )}

                        {user.account_status !== 'active' && canActivate && (
                          <Button
                            type="button"
                            disabled={processingId !== null}
                            loading={processingId === user.id}
                            onClick={() => handleToggleStatus(user)}
                          >
                            Aktifkan
                          </Button>
                        )}

                        {!canUpdate && !canActivate && !canDeactivate && (
                          <Badge variant="neutral">Lihat</Badge>
                        )}
                      </div>
                    )

                  default:
                    return '-'
                }
              }}
            />

            <div className="users-page__pagination">
              <span>
                Halaman {meta.current_page} dari {meta.last_page} ·{' '}
                {meta.total} pengguna
              </span>

              <div>
                <Button
                  type="button"
                  disabled={loading || page <= 1}
                  onClick={() => setPage((current) => Math.max(1, current - 1))}
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
        title={editingUser ? 'Edit Pengguna' : 'Tambah Pengguna'}
        description={
          editingUser
            ? 'Perbarui informasi akun yang dipilih.'
            : 'Buat akun baru untuk pengguna SIAKAD.'
        }
        onClose={closeModal}
        size="large"
        footer={
          <>
            <Button type="button" disabled={saving} onClick={closeModal}>
              Batal
            </Button>

            <Button type="submit" form="user-form" loading={saving}>
              {editingUser ? 'Simpan Perubahan' : 'Buat Akun'}
            </Button>
          </>
        }
      >
        {submitError && (
          <div className="users-page__form-error">
            <ErrorState
              message={getApiMessage(
                submitError,
                'Data pengguna gagal disimpan.',
              )}
            />
          </div>
        )}

        <form id="user-form" onSubmit={handleSubmit}>
          <div className="users-page__form-grid">
            <Input
              id="username"
              name="username"
              label="Username"
              value={form.username}
              required
              disabled={saving}
              error={getFieldError(fieldErrors, 'username')}
              onChange={handleChange}
            />

            <Input
              id="name"
              name="name"
              label="Nama lengkap"
              value={form.name}
              required
              disabled={saving}
              error={getFieldError(fieldErrors, 'name')}
              onChange={handleChange}
            />

            <Input
              id="email"
              name="email"
              label="Email"
              type="email"
              value={form.email}
              disabled={saving}
              error={getFieldError(fieldErrors, 'email')}
              onChange={handleChange}
            />

            <Select
              id="role_id"
              name="role_id"
              label="Role pengguna"
              value={form.role_id}
              required
              disabled={saving}
              placeholder="Pilih role"
              options={ROLES}
              error={getFieldError(fieldErrors, 'role_id')}
              onChange={handleChange}
            />

            {!editingUser && (
              <div className="users-page__full-width">
                <Input
                  id="password"
                  name="password"
                  label="Password awal"
                  type="password"
                  value={form.password}
                  required
                  disabled={saving}
                  error={getFieldError(fieldErrors, 'password')}
                  hint="Password disimpan oleh backend menggunakan hashing."
                  onChange={handleChange}
                />
              </div>
            )}
          </div>
        </form>
      </Modal>

      {/* Modal konfirmasi aktivasi/nonaktifkan akun */}
      <Modal
        open={Boolean(userToToggle)}
        title={
          userToToggle?.account_status === 'active'
            ? 'Nonaktifkan Akun?'
            : 'Aktifkan Akun?'
        }
        description="Konfirmasi perubahan status akun pengguna."
        onClose={() => {
          if (processingId === null) setUserToToggle(null)
        }}
        footer={
          <>
            <Button
              type="button"
              disabled={processingId !== null}
              onClick={() => setUserToToggle(null)}
            >
              Batal
            </Button>

            <button
              type="button"
              className={`users-page__status-confirm ${
                userToToggle?.account_status === 'active'
                  ? 'users-page__status-confirm--danger'
                  : 'users-page__status-confirm--success'
              }`}
              disabled={processingId !== null}
              onClick={confirmToggleStatus}
            >
              {processingId !== null
                ? 'Memproses...'
                : userToToggle?.account_status === 'active'
                  ? 'Nonaktifkan Akun'
                  : 'Aktifkan Akun'}
            </button>
          </>
        }
      >
        {userToToggle && (
          <div className="users-page__confirm">
            <div
              className={`users-page__confirm-icon ${
                userToToggle.account_status === 'active'
                  ? 'users-page__confirm-icon--danger'
                  : 'users-page__confirm-icon--success'
              }`}
              aria-hidden="true"
            >
              {userToToggle.account_status === 'active' ? '!' : '✓'}
            </div>

            <p>
              Apakah kamu yakin ingin{' '}
              <strong>
                {userToToggle.account_status === 'active'
                  ? 'menonaktifkan'
                  : 'mengaktifkan'}
              </strong>{' '}
              akun <strong>{userToToggle.name}</strong> (
              <strong>{userToToggle.username}</strong>)?
            </p>

            <p className="users-page__confirm-note">
              {userToToggle.account_status === 'active'
                ? 'Pengguna tidak akan dapat menggunakan akun ini selama statusnya tidak aktif.'
                : 'Pengguna akan dapat menggunakan akun ini kembali setelah statusnya aktif.'}
            </p>
          </div>
        )}
      </Modal>
    </section>
  )
}
