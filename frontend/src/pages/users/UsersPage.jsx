
import { useCallback, useEffect, useRef, useState } from 'react'

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
import UserActionsMenu from './UserActionsMenu'
import UserImportButton from './UserImportButton'
import UserActivationSlipModal from './UserActivationSlipModal'

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

const initialResetForm = {
  password: '',
  password_confirmation: '',
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
  return (
    user?.role?.name ||
    ROLES.find(
      (item) => item.value === String(user?.role_id)
    )?.label ||
    '-'
  )
}

function getUserInitial(user) {
  const name = user?.name || user?.username || 'U'
  return name.trim().charAt(0).toUpperCase() || 'U'
}

export default function UsersPage() {
  const { role, permissions = [] } = useAuth()

  const roleCode =
    typeof role === 'string'
      ? role
      : role?.code || null

  const canView =
    roleCode === 'TU' ||
    permissions.includes('users.view')

  const canCreate =
    roleCode === 'TU' ||
    permissions.includes('users.create')

  const canUpdate =
    roleCode === 'TU' ||
    permissions.includes('users.update')

  const canActivate =
    roleCode === 'TU' ||
    permissions.includes('users.activate')

  const canDeactivate =
    roleCode === 'TU' ||
    permissions.includes('users.deactivate')

  const canResetPassword =
    roleCode === 'TU' ||
    permissions.includes('users.reset_password')

  // Daftar, filter, dan pagination
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
  const [successMessage, setSuccessMessage] = useState('')
  const [reloadKey, setReloadKey] = useState(0)

  // Melindungi hasil request terbaru dari respons lama.
  const requestIdRef = useRef(0)

  // Form tambah/edit
  const [modalOpen, setModalOpen] = useState(false)
  const [editingUser, setEditingUser] = useState(null)
  const [form, setForm] = useState(initialForm)
  const [fieldErrors, setFieldErrors] = useState({})
  const [submitError, setSubmitError] = useState(null)
  const [saving, setSaving] = useState(false)

  // Status akun
  const [processingId, setProcessingId] = useState(null)
  const [userToToggle, setUserToToggle] = useState(null)

  // Reset password
  const [resetUser, setResetUser] = useState(null)
  const [resetForm, setResetForm] = useState(initialResetForm)
  const [resetFieldErrors, setResetFieldErrors] = useState({})
  const [resetError, setResetError] = useState(null)
  const [resetting, setResetting] = useState(false)

  // Slip aktivasi
  const [activationUser, setActivationUser] = useState(null)

  // ==========================================
  // LOAD DATA PENGGUNA
  // ==========================================

  const loadUsers = useCallback(async () => {
    const requestId = ++requestIdRef.current

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

      // Abaikan respons yang sudah tidak relevan.
      if (requestId !== requestIdRef.current) return

      setUsers(
        Array.isArray(response?.data)
          ? response.data
          : []
      )

      setMeta((current) => ({
        ...current,
        ...(response?.meta || {}),
      }))
    } catch (error) {
      if (requestId === requestIdRef.current) {
        setListError(error)
      }
    } finally {
      if (requestId === requestIdRef.current) {
        setLoading(false)
      }
    }
  }, [canView, page, roleFilter, search, statusFilter])

  useEffect(() => {
    loadUsers()

    return () => {
      requestIdRef.current += 1
    }
  }, [loadUsers, reloadKey])

  function refreshUsers() {
    setReloadKey((current) => current + 1)
  }

  // Dipanggil oleh UserImportButton setelah
  // Laravel berhasil menyimpan akun.
  function handleUsersImported(result) {
    setSearchInput('')
    setSearch('')
    setRoleFilter('')
    setStatusFilter('')
    setPage(1)

    setSuccessMessage(
      `${result.created_count} akun berhasil diimpor. Terbitkan slip aktivasi melalui menu Aksi pengguna.`
    )

    refreshUsers()
  }

  // ==========================================
  // TAMBAH / EDIT PENGGUNA
  // ==========================================

  function clearUserForm() {
    setForm(initialForm)
    setFieldErrors({})
    setSubmitError(null)
    setEditingUser(null)
  }

  function closeModal() {
    if (saving) return

    setModalOpen(false)
    clearUserForm()
  }

  function openCreateModal() {
    clearUserForm()
    setSuccessMessage('')
    setModalOpen(true)
  }

  function openEditModal(user) {
    clearUserForm()
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
    if (saving) return

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
        setSuccessMessage(
          'Data pengguna berhasil diperbarui.'
        )
      } else {
        await userApi.create(payload)
        setSuccessMessage(
          'Akun pengguna berhasil dibuat.'
        )

        setPage(1)
        setSearch('')
        setSearchInput('')
        setRoleFilter('')
        setStatusFilter('')
      }

      setModalOpen(false)
      clearUserForm()
      refreshUsers()
    } catch (error) {
      setFieldErrors(
        error?.validationErrors || error?.errors || {}
      )
      setSubmitError(error)
    } finally {
      setSaving(false)
    }
  }

  // ==========================================
  // AKTIFKAN / NONAKTIFKAN
  // ==========================================

  function handleToggleStatus(user) {
    setUserToToggle(user)
    setSuccessMessage('')
  }

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
        setSuccessMessage(
          `Akun ${user.username} berhasil dinonaktifkan.`
        )
      } else {
        await userApi.activate(user.id)
        setSuccessMessage(
          `Akun ${user.username} berhasil diaktifkan.`
        )
      }

      setUserToToggle(null)
      refreshUsers()
    } catch (error) {
      setListError(error)
    } finally {
      setProcessingId(null)
    }
  }

  // ==========================================
  // RESET PASSWORD
  // ==========================================

  function openResetPasswordModal(user) {
    setResetUser(user)
    setResetForm(initialResetForm)
    setResetFieldErrors({})
    setResetError(null)
    setSuccessMessage('')
  }

  function closeResetPasswordModal() {
    if (resetting) return

    setResetUser(null)
    setResetForm(initialResetForm)
    setResetFieldErrors({})
    setResetError(null)
  }

  function handleResetFieldChange(event) {
    const { name, value } = event.target

    setResetForm((current) => ({
      ...current,
      [name]: value,
    }))

    setResetFieldErrors((current) => ({
      ...current,
      [name]: '',
    }))

    setResetError(null)
  }

  async function handleResetPassword(event) {
    event.preventDefault()

    if (!resetUser || resetting) return

    setResetFieldErrors({})
    setResetError(null)

    const errors = {}

    if (!resetForm.password) {
      errors.password = 'Password baru wajib diisi.'
    }

    if (!resetForm.password_confirmation) {
      errors.password_confirmation =
        'Konfirmasi password wajib diisi.'
    } else if (
      resetForm.password !==
      resetForm.password_confirmation
    ) {
      errors.password_confirmation =
        'Konfirmasi password tidak sesuai.'
    }

    if (Object.keys(errors).length > 0) {
      setResetFieldErrors(errors)
      return
    }

    setResetting(true)

    try {
      const targetUsername = resetUser.username

      await userApi.resetPassword(resetUser.id, {
        password: resetForm.password,
        password_confirmation:
          resetForm.password_confirmation,
      })

      setResetUser(null)
      setResetForm(initialResetForm)
      setResetFieldErrors({})
      setResetError(null)

      setSuccessMessage(
        `Password akun ${targetUsername} berhasil direset.`
      )

      refreshUsers()
    } catch (error) {
      setResetFieldErrors(
        error?.validationErrors || error?.errors || {}
      )
      setResetError(error)
    } finally {
      setResetting(false)
    }
  }

  // ==========================================
  // HAK AKSES
  // ==========================================

  if (!canView) {
    return (
      <section className="users-page">
        <PageHeader
          title="Manajemen Pengguna"
          description="Pengelolaan akun pengguna SIAKAD."
        />

        <ErrorState
          message="Kamu tidak memiliki akses untuk melihat data pengguna."
        />
      </section>
    )
  }

  // ==========================================
  // TAMPILAN HALAMAN
  // ==========================================

  return (
    <section className="users-page">
      <PageHeader
        title="Manajemen Pengguna"
        description="Kelola akun, role, dan status akses pengguna SIAKAD."
        actions={
          canCreate ? (
            <div className="users-page__header-actions">
              <UserImportButton
                onImported={handleUsersImported}
              />

              <Button onClick={openCreateModal}>
                + Tambah Pengguna
              </Button>
            </div>
          ) : null
        }
      />

      {successMessage && (
        <div
          className="users-page__success"
          role="status"
          aria-live="polite"
        >
          {successMessage}
        </div>
      )}

      <Card className="users-page__card">
        <div className="users-page__list-header">
          <div>
            <p className="users-page__eyebrow">
              Administrasi akun
            </p>

            <h2>Daftar Pengguna</h2>

            <p>
              Informasi akun dan hak akses yang
              terdaftar di sistem.
            </p>
          </div>

          <span className="users-page__count">
            {loading
              ? 'Memuat pengguna...'
              : listError
                ? 'Data gagal dimuat'
                : `${meta.total} Pengguna`}
          </span>
        </div>

        <form
          className="users-page__filters"
          onSubmit={handleSearch}
        >
          <Input
            id="user-search"
            name="search"
            label="Cari pengguna"
            value={searchInput}
            placeholder="Username, nama, atau email"
            onChange={(event) =>
              setSearchInput(event.target.value)
            }
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

            <Button
              type="button"
              variant="secondary"
              onClick={resetFilters}
            >
              Reset
            </Button>
          </div>
        </form>

        {loading && (
          <LoadingState
            message="Memuat data pengguna..."
          />
        )}

        {!loading && listError && (
          <ErrorState
            message={getApiMessage(
              listError,
              'Data pengguna tidak dapat dimuat.'
            )}
          />
        )}

        {!loading &&
          !listError &&
          users.length === 0 && (
            <EmptyState
              title="Belum ada data pengguna"
              description={
                search || roleFilter || statusFilter
                  ? 'Tidak ditemukan pengguna yang sesuai dengan filter.'
                  : 'Belum ada akun pengguna yang terdaftar.'
              }
            />
          )}

        {!loading &&
          !listError &&
          users.length > 0 && (
            <>
              <Table
                columns={columns}
                data={users}
                rowKey="id"
                renderCell={(user, column) => {
                  switch (column.key) {
                    case 'identity':
                      return (
                        <div className="users-page__identity-row">
                          <div
                            className="users-page__avatar"
                            aria-hidden="true"
                          >
                            {getUserInitial(user)}
                          </div>

                          <div className="users-page__identity">
                            <strong>
                              {user.name || '-'}
                            </strong>

                            <span>
                              @{user.username || '-'}
                            </span>

                            <small>
                              {user.email ||
                                'Email belum diisi'}
                            </small>
                          </div>
                        </div>
                      )

                    case 'role':
                      return getRoleName(user)

                    case 'status':
                      if (user.account_status !== 'active') {
                        return (
                          <Badge variant="neutral">
                            Tidak Aktif
                          </Badge>
                        )
                      }

                      if (user.requires_activation === true) {
                        return (
                          <span className="users-page__activation-pending">
                            Belum Aktivasi
                          </span>
                        )
                      }

                      return (
                        <Badge variant="success">
                          Aktif
                        </Badge>
                      )

                    case 'google':
                      return user.google_linked ? (
                        <Badge variant="success">
                          Terhubung
                        </Badge>
                      ) : (
                        <Badge variant="neutral">
                          Belum terhubung
                        </Badge>
                      )

                    case 'actions': {
                      const canToggle =
                        user.account_status === 'active'
                          ? canDeactivate
                          : canActivate

                      const canIssueActivation =
                        roleCode === 'TU' &&
                        canResetPassword &&
                        user.account_status === 'active' &&
                        user.requires_activation === true &&
                        ['GURU', 'SISWA'].includes(
                          user.role?.code
                        )

                      return (
                        <div className="users-page__actions">
                          {canUpdate ||
                          canToggle ||
                          canResetPassword ||
                          canIssueActivation ? (
                            <UserActionsMenu
                              user={user}
                              canUpdate={canUpdate}
                              canToggle={canToggle}
                              canReset={
                                canResetPassword &&
                                user.requires_activation !== true
                              }
                              canIssueActivation={
                                canIssueActivation
                              }
                              disabled={
                                processingId !== null ||
                                resetting
                              }
                              onEdit={() =>
                                openEditModal(user)
                              }
                              onToggle={() =>
                                handleToggleStatus(user)
                              }
                              onReset={() =>
                                openResetPasswordModal(user)
                              }
                              onIssueActivation={() => {
                                setSuccessMessage('')
                                setActivationUser(user)
                              }}
                            />
                          ) : (
                            <Badge variant="neutral">
                              Lihat
                            </Badge>
                          )}
                        </div>
                      )
                    }

                    default:
                      return '-'
                  }
                }}
              />

              <div className="users-page__pagination">
                <span>
                  Halaman {meta.current_page} dari{' '}
                  {meta.last_page} · {meta.total} pengguna
                </span>

                <div>
                  <Button
                    type="button"
                    variant="secondary"
                    disabled={loading || page <= 1}
                    onClick={() =>
                      setPage((current) =>
                        Math.max(1, current - 1)
                      )
                    }
                  >
                    Sebelumnya
                  </Button>

                  <Button
                    type="button"
                    variant="secondary"
                    disabled={
                      loading || page >= meta.last_page
                    }
                    onClick={() =>
                      setPage((current) =>
                        Math.min(
                          meta.last_page,
                          current + 1
                        )
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

      {/* MODAL TAMBAH DAN EDIT */}

      <Modal
        open={modalOpen}
        title={
          editingUser
            ? 'Edit Pengguna'
            : 'Tambah Pengguna'
        }
        description={
          editingUser
            ? 'Perbarui informasi akun yang dipilih.'
            : 'Buat akun baru untuk pengguna SIAKAD.'
        }
        onClose={closeModal}
        size="large"
        footer={
          <>
            <Button
              type="button"
              variant="secondary"
              disabled={saving}
              onClick={closeModal}
            >
              Batal
            </Button>

            <Button
              type="submit"
              form="user-form"
              loading={saving}
            >
              {editingUser
                ? 'Simpan Perubahan'
                : 'Buat Akun'}
            </Button>
          </>
        }
      >
        {submitError && (
          <div className="users-page__form-error">
            <ErrorState
              message={getApiMessage(
                submitError,
                'Data pengguna gagal disimpan.'
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
              error={getFieldError(
                fieldErrors,
                'username'
              )}
              onChange={handleChange}
            />

            <Input
              id="name"
              name="name"
              label="Nama lengkap"
              value={form.name}
              required
              disabled={saving}
              error={getFieldError(
                fieldErrors,
                'name'
              )}
              onChange={handleChange}
            />

            <Input
              id="email"
              name="email"
              label="Email"
              type="email"
              value={form.email}
              disabled={saving}
              error={getFieldError(
                fieldErrors,
                'email'
              )}
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
              error={getFieldError(
                fieldErrors,
                'role_id'
              )}
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
                  error={getFieldError(
                    fieldErrors,
                    'password'
                  )}
                  hint="Password disimpan oleh backend menggunakan hashing."
                  onChange={handleChange}
                />
              </div>
            )}
          </div>
        </form>
      </Modal>

      {/* KONFIRMASI STATUS AKUN */}

      <Modal
        open={Boolean(userToToggle)}
        title={
          userToToggle?.account_status === 'active'
            ? 'Nonaktifkan Akun?'
            : 'Aktifkan Akun?'
        }
        description="Konfirmasi perubahan status akun pengguna."
        onClose={() => {
          if (processingId === null) {
            setUserToToggle(null)
          }
        }}
        footer={
          <>
            <Button
              type="button"
              variant="secondary"
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
              {userToToggle.account_status === 'active'
                ? '!'
                : '✓'}
            </div>

            <p>
              Apakah kamu yakin ingin{' '}
              <strong>
                {userToToggle.account_status === 'active'
                  ? 'menonaktifkan'
                  : 'mengaktifkan'}
              </strong>{' '}
              akun <strong>{userToToggle.name}</strong>{' '}
              (<strong>{userToToggle.username}</strong>)?
            </p>

            <p className="users-page__confirm-note">
              {userToToggle.account_status === 'active'
                ? 'Pengguna tidak akan dapat menggunakan akun ini selama statusnya tidak aktif.'
                : 'Pengguna akan dapat menggunakan akun ini kembali setelah statusnya aktif.'}
            </p>
          </div>
        )}
      </Modal>

      {/* MODAL RESET PASSWORD */}

      <Modal
        open={Boolean(resetUser)}
        title="Reset Password Pengguna"
        description="Tetapkan password baru untuk akun yang dipilih."
        onClose={closeResetPasswordModal}
        size="medium"
        footer={
          <>
            <Button
              type="button"
              variant="secondary"
              disabled={resetting}
              onClick={closeResetPasswordModal}
            >
              Batal
            </Button>

            <Button
              type="submit"
              form="reset-user-password-form"
              loading={resetting}
            >
              Reset Password
            </Button>
          </>
        }
      >
        {resetUser && (
          <>
            <div className="users-page__reset-target">
              <div
                className="users-page__avatar"
                aria-hidden="true"
              >
                {getUserInitial(resetUser)}
              </div>

              <div className="users-page__identity">
                <strong>
                  {resetUser.name || resetUser.username}
                </strong>

                <span>
                  @{resetUser.username}
                </span>

                <small>
                  {getRoleName(resetUser)}
                </small>
              </div>
            </div>

            {resetError && (
              <div className="users-page__form-error">
                <ErrorState
                  message={getApiMessage(
                    resetError,
                    'Password gagal direset.'
                  )}
                />
              </div>
            )}

            <form
              id="reset-user-password-form"
              onSubmit={handleResetPassword}
            >
              <div className="users-page__reset-fields">
                <Input
                  id="reset-password"
                  name="password"
                  label="Password baru"
                  type="password"
                  value={resetForm.password}
                  required
                  disabled={resetting}
                  error={getFieldError(
                    resetFieldErrors,
                    'password'
                  )}
                  hint="Gunakan password yang kuat dan tidak mudah ditebak."
                  onChange={handleResetFieldChange}
                />

                <Input
                  id="reset-password-confirmation"
                  name="password_confirmation"
                  label="Konfirmasi password baru"
                  type="password"
                  value={resetForm.password_confirmation}
                  required
                  disabled={resetting}
                  error={getFieldError(
                    resetFieldErrors,
                    'password_confirmation'
                  )}
                  onChange={handleResetFieldChange}
                />
              </div>
            </form>

            <p className="users-page__reset-note">
              Setelah berhasil, password lama tidak dapat
              digunakan lagi. Token akses API pengguna
              tersebut juga akan dicabut. Pastikan
              kredensial baru diberikan melalui saluran
              yang aman.
            </p>
          </>
        )}
      </Modal>

      {/* SLIP AKTIVASI GURU / SISWA */}

      {activationUser && (
        <UserActivationSlipModal
          key={activationUser.id}
          user={activationUser}
          onClose={() => setActivationUser(null)}
        />
      )}
    </section>
  )
}
