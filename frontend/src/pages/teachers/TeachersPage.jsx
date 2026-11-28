
import { useCallback, useEffect, useRef, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import ToastNotification from '../../components/ui/ToastNotification'
import IdentityDirectoryDetail from '../identity/IdentityDirectoryDetail.jsx'
import WorkspaceModal from '../../components/ui/WorkspaceModal'
import { DirectoryIdentity, DirectoryCompleteness, DirectoryOverview, ImportProgress, ImportPreviewScroll } from '../identity/IdentityDirectoryUI.jsx'


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
  registration_mode: 'new_account',
  username: '',
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
  { key: 'completeness', label: 'Biodata' },
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
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const entryHandled = useRef('')
  const formRequest = useRef(0)
  const detailRequest = useRef(0)
  const [detail, setDetail] = useState(null)
  const [detailError, setDetailError] = useState('')
  const [accountFilter, setAccountFilter] = useState('')
  const [genderFilter, setGenderFilter] = useState('')
  const [completeFilter, setCompleteFilter] = useState('')
  const [pageSize, setPageSize] = useState('15')

  const canManage =
    role?.code === 'TU' || permissions.includes('teachers.create')

  const [teachers, setTeachers] = useState([])
  const [accounts, setAccounts] = useState([])
  const [searchInput, setSearchInput] = useState('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [sortOrder, setSortOrder] = useState('asc')
  const listRequest = useRef(0)

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
  const [overviewRevision, setOverviewRevision] = useState(0)
  const [successMessage, setSuccessMessage] = useState('')
  const [saving, setSaving] = useState(false)
  const [deletingId, setDeletingId] = useState(null)
  const [deleteTarget, setDeleteTarget] = useState(null)
  // State Import Excel
const [importModalOpen, setImportModalOpen] = useState(false)
const [importMode, setImportMode] = useState('new_account')
const [selectedExcel, setSelectedExcel] = useState(null)
const [previewData, setPreviewData] = useState(null)
const [importError, setImportError] = useState('')
const [previewLoading, setPreviewLoading] = useState(false)
const [importing, setImporting] = useState(false)
const [downloadingTemplate, setDownloadingTemplate] = useState(false)

  const loadTeachers = useCallback(async () => {
    const requestId = ++listRequest.current
    setLoading(true)
    setListError(null)

    try {
      const response = await teacherApi.getAll({
        search,
        page,
        page_size: Number(pageSize),
        account_state: canManage ? accountFilter : '',
        gender: canManage ? genderFilter : '',
        completeness: canManage ? completeFilter : '',

        sort_order: sortOrder,
      })

      if (requestId !== listRequest.current) return
      setTeachers(Array.isArray(response?.data) ? response.data : [])
      setMeta((current) => ({
        ...current,
        ...(response?.meta || {}),
      }))
    } catch (error) {
      if (requestId === listRequest.current) setListError(error)
    } finally {
      if (requestId === listRequest.current) setLoading(false)
    }
  }, [page, search, sortOrder, pageSize, canManage, accountFilter, genderFilter, completeFilter])

  useEffect(() => {
    loadTeachers()
    return () => { listRequest.current += 1 }
  }, [loadTeachers])

  async function openDetail(id) {
    const generation = ++detailRequest.current
    setDetailError('')
    try {
      const response = await teacherApi.getById(id)
      if (!response?.data || String(response.data.id) !== String(id)) throw new Error('Identitas profil tidak sesuai.')
      if (generation === detailRequest.current) setDetail(response.data)
    } catch (error) {if (generation === detailRequest.current) setDetailError(getApiMessage(error,'Detail gagal dimuat.'))}
  }

  useEffect(() => {
    if (!canManage) return
    const key = params.toString()
    if (!key) {entryHandled.current = ''; return}
    if (entryHandled.current === key) return
    entryHandled.current = key
    const accountId = params.get('link_user')
    const profileId = params.get('profile_id')
    if (accountId && /^[1-9]\d*$/.test(accountId)) openCreateModal(accountId)
    else if (profileId && /^[1-9]\d*$/.test(profileId)) openDetail(profileId)
    else if (params.get('register') === '1') openCreateModal()
    else if (params.get('import') === '1') openImportModal()
    setParams({}, {replace:true})
  }, [params, canManage])

  function resetForm() {
    setForm(initialForm)
    setFieldErrors({})
    setSubmitError(null)
    setEditingTeacher(null)
  }

  function closeModal() {
    if (saving) return
    formRequest.current += 1
    setModalOpen(false)
    resetForm()
  }

  async function openCreateModal(linkUserId = null) {
    const generation = ++formRequest.current
    const requestedId = typeof linkUserId === 'string' ? linkUserId : null
    resetForm()
    setSuccessMessage('')
    setAccounts([])
    if (!requestedId) setModalOpen(true)

    try {
      const response = await teacherApi.getAvailableAccounts()
      if (generation !== formRequest.current) return
      const available = Array.isArray(response?.data) ? response.data : []
      setAccounts(available)
      if (requestedId) {
        const account = available.find(a => String(a.id) === requestedId)
        if (!account) {
          setModalOpen(false)
          setDetailError('Akun tidak lagi tersedia untuk dihubungkan. Periksa status dan profilnya di Manajemen Akun.')
          return
        }
        setForm({...initialForm, registration_mode:'existing_account', user_id:String(account.id), nama_lengkap:account.name || ''})
        setModalOpen(true)
      }
    } catch (error) {
      if (generation !== formRequest.current) return
      setAccounts([])
      if (requestedId) setDetailError(getApiMessage(error,'Akun gagal dimuat.'))
      else setSubmitError(error)
    }
  }

  async function openEditModal(teacher) {
    formRequest.current += 1
    resetForm()
    setSuccessMessage('')
    setEditingTeacher(teacher)

    setForm({
      registration_mode: 'existing_account',
      username: '',
      user_id: String(teacher.user_id ?? ''),
      nip: teacher.nip ?? '',
      nama_lengkap: teacher.nama_lengkap ?? '',
      jenis_kelamin: teacher.jenis_kelamin ?? '',
      no_telepon: teacher.no_telepon ?? '',
      alamat: teacher.alamat ?? '',
    })

    setModalOpen(true)

    setAccounts(teacher.user ? [{id:teacher.user_id, username:teacher.user.username, name:teacher.user.name}] : [])
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
    if (saving) return
    setFieldErrors({})
    setSubmitError(null)

    const localErrors = {}

    if ((editingTeacher || form.registration_mode === 'existing_account') && !form.user_id) {
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
      ...(editingTeacher ? { user_id: Number(form.user_id), profile_version: editingTeacher.profile_version } : {
        registration_mode: form.registration_mode,
        ...(form.registration_mode === 'new_account'
          ? (form.username.trim() ? { username: form.username.trim() } : {})
          : { user_id: Number(form.user_id) }),
      }),
      nip: form.nip.trim(),
      nama_lengkap: form.nama_lengkap.trim(),
      jenis_kelamin: form.jenis_kelamin || null,
      no_telepon: form.no_telepon.trim() || null,
      alamat: form.alamat.trim() || null,
    }

    try {
      if (editingTeacher) {
        await teacherApi.update(editingTeacher.id, payload)
        setOverviewRevision(value=>value+1)
        setSuccessMessage('Data Guru berhasil diperbarui.')
      } else {
        await teacherApi.create(payload)
        setOverviewRevision(value=>value+1)
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
        setOverviewRevision(value=>value+1)
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
  formData.append('registration_mode', importMode)

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
  if (!selectedExcel || !previewData?.preview_token) return

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

  if (importing || previewLoading) return
  const formData = new FormData()
  formData.append('file', selectedExcel)
  formData.append('registration_mode', importMode)
  if (previewData.preview_token) formData.append('preview_token', previewData.preview_token)

  setImporting(true)
  setImportError('')

  try {
    const response = await teacherApi.importExcel(formData)
    setOverviewRevision(value=>value+1)

    const importedRows =
      response?.data?.imported_rows

    setImportModalOpen(false)
    setSelectedExcel(null)
    setPreviewData(null)

    setSuccessMessage(
      Number.isInteger(importedRows) ? `${importedRows} data Guru berhasil diimpor.` : 'Import selesai. Muat ulang daftar untuk memeriksa hasil.',
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
    <section className="teachers-page identity-directory-page">
      <PageHeader
  eyebrow="Administrasi akademik"
  title="Data Guru"
  description="Kelola identitas dan informasi dasar Guru."
  actions={
    canManage ? (
      <div className="teachers-page__header-actions">
        <Button
          type="button"
          variant="secondary"
          onClick={handleDownloadTemplate}
          loading={downloadingTemplate}
          disabled={downloadingTemplate}
        >
          Unduh Template
        </Button>

        <Button
          type="button"
          variant="secondary"
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

      <ToastNotification notice={successMessage ? {type:'success', title:'Berhasil', message:successMessage} : null} onClose={() => setSuccessMessage('')}/>
      {detailError && <ErrorState message={detailError}/>}
      {detail && <IdentityDirectoryDetail profile={detail} student={false} onClose={() => setDetail(null)} onDelete={canManage ? () => {const target=detail;setDetail(null);handleDelete(target)} : null} onEdit={canManage ? () => {const target=detail;setDetail(null);openEditModal(target)} : null} onAccount={canManage && detail.user_id ? () => navigate(`/app/users?user_id=${detail.user_id}`) : null}/>}
            {canManage && <DirectoryOverview api={teacherApi} student={false} revision={overviewRevision}/>}
      <Card className="teachers-page__card">
        <div className="teachers-page__list-header">
          <div>
            <p className="teachers-page__eyebrow">Data akademik</p>
            <h2>Daftar Guru</h2>
            <p>Data identitas Guru yang tersimpan di sistem.</p>
          </div>

          <span className="teachers-page__count" aria-live="polite">{loading ? "Memuat…" : listError ? "Tidak tersedia" : `${meta.total} hasil`}</span>
        </div>

        <form className="teachers-page__search" onSubmit={handleSearch}>
          <Input
            id="teacher-search"
            name="search"
            label="Cari Guru"
            value={searchInput}
            placeholder="Cari nama, NIP atau username"
            onChange={(event) => setSearchInput(event.target.value)}
          />

          <Select id="teacher-sort" label="Urutan nama" value={sortOrder}
            options={[{value: 'asc', label: 'A–Z'}, {value: 'desc', label: 'Z–A'}]}
            onChange={event => {setSortOrder(event.target.value || 'asc'); setPage(1)}} />
          {canManage && <>
            <Select id="teacher-accountFilter" label="Status akun" value={accountFilter} placeholder="Semua status" options={[{value:'ready',label:'Siap digunakan'},{value:'pending',label:'Menunggu aktivasi'},{value:'inactive',label:'Nonaktif'}]} onChange={e=>{setAccountFilter(e.target.value);setPage(1)}}/>
          </>}
          <div className="teachers-page__search-actions">
            <Button type="submit">Cari</Button>
            <Button
              variant="secondary"
              type="button"
              onClick={() => {
                setSearchInput('')
                setSearch('')
                setSortOrder('asc')
                setAccountFilter(''); setGenderFilter(''); setCompleteFilter(''); setPageSize('15')
                setPage(1)
              }}
            >
              Reset
            </Button>
          </div>
          <details className="identity-directory-advanced">
            <summary>Filter lanjutan & tampilan {(completeFilter || genderFilter) ? <span className="identity-filter-active">Filter aktif</span> : null}</summary>
            <div className="identity-directory-advanced-grid">
          {canManage && <>
            <Select id="teacher-completeFilter" label="Kelengkapan biodata" value={completeFilter} placeholder="Semua biodata" options={[{value:'complete',label:'Lengkap'},{value:'incomplete',label:'Belum lengkap'}]} onChange={e=>{setCompleteFilter(e.target.value);setPage(1)}}/>
            <Select id="teacher-genderFilter" label="Jenis kelamin" value={genderFilter} placeholder="Semua" options={[{value:'L',label:'Laki-laki'},{value:'P',label:'Perempuan'}]} onChange={e=>{setGenderFilter(e.target.value);setPage(1)}}/>
          </>}
          <Select id="teacher-pageSize" label="Data per halaman" value={pageSize} options={['15','25','50','100'].map(x=>({value:x,label:x}))} onChange={e=>{setPageSize(e.target.value);setPage(1)}}/>
            </div>
          </details>
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
            title={search || accountFilter || completeFilter || genderFilter ? "Guru tidak ditemukan" : "Belum ada data Guru"}
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
              scrollLabel="Tabel Data Guru"
              columns={canManage ? columns : columns.filter(c => !['status','completeness','placement'].includes(c.key))}
              data={teachers}
              rowKey="id"
              renderCell={(teacher, column) => {
                switch (column.key) {
                  case 'identity':
                    return (
                      <DirectoryIdentity name={getTeacherName(teacher)} username={teacher.user?.username} email={teacher.user?.email} showAccount={canManage}/>
                    )

                  case 'nip':
                    return teacher.nip || '-'

                  case 'gender':
                    if (teacher.jenis_kelamin === 'L') return 'Laki-laki'
                    if (teacher.jenis_kelamin === 'P') return 'Perempuan'
                    return teacher.jenis_kelamin || '-'

                  case 'status':
                    return teacher.user?.account_status === 'active' ? (
                      <Badge variant={teacher.user.requires_activation === true ? "warning" : teacher.user.requires_activation === false ? "success" : "neutral"}>{teacher.user.requires_activation === true ? "Menunggu aktivasi" : teacher.user.requires_activation === false ? "Siap digunakan" : "Status belum diketahui"}</Badge>
                    ) : (
                      <Badge variant="neutral">
                        {teacher.user?.account_status === 'inactive' ? 'Nonaktif' : 'Status belum diketahui'}
                      </Badge>
                    )

                  case 'completeness':
                    return <DirectoryCompleteness value={teacher.completeness}/>
                  case 'actions':
                    return <Button variant="secondary" onClick={() => openDetail(teacher.id)}>Lihat detail <span aria-hidden="true">›</span></Button>

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

      {importModalOpen && <WorkspaceModal className="identity-import-workspace" busy={previewLoading || importing} eyebrow="IMPORT EXCEL"
        title="Import Data Guru"
        description="Unggah file Excel untuk menambahkan data Guru secara massal."
        onClose={closeImportModal}
        footer={
          <>
            <Button
              type="button"
              variant="secondary"
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
                Periksa Data
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
                  previewData.summary?.valid_rows === 0 || !previewData.preview_token
                }
                onClick={handleConfirmImport}
              >
                Konfirmasi Import {previewData.summary?.valid_rows ?? 0} Guru
              </Button>
            )}
          </>
        }
      >
        <div className="teachers-page__import-card">
          <ImportProgress preview={Boolean(previewData)} busy={previewLoading || importing} importing={importing}/>
          <details className="identity-import-settings" open={!previewData && !previewLoading}>
            <summary><span>File &amp; pengaturan</span><span className="identity-import-filename">{selectedExcel?.name || 'Pilih file'}</span></summary>
            <div className="identity-import-settings-body">
          <details className="identity-import-guide"><summary>Ketentuan file dan template</summary>          <div className="teachers-page__import-heading">
            <h3>Petunjuk Import</h3>
            <p>
              Gunakan template resmi. Pilih pendaftaran baru untuk membuat akun dan profil sekaligus, atau penghubungan jika akun sudah terdaftar.
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
            Kolom wajib: nip, nama_lengkap. Username wajib untuk penghubungan akun existing.
          </div>

<Button variant="secondary" disabled={previewLoading || importing || downloadingTemplate} loading={downloadingTemplate} onClick={handleDownloadTemplate}>Unduh template Guru</Button></details>
          <Select id="teacher-import-mode" label="Mode import" value={importMode} disabled={previewLoading || importing}
            options={[{value:'new_account',label:'Pendaftaran akun dan profil baru'},{value:'existing_account',label:'Hubungkan akun existing'}]}
            onChange={event => {setImportMode(event.target.value || 'new_account'); setPreviewData(null); setImportError('')}} />
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

            </div>
          </details>

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

              <ImportPreviewScroll className="teachers-page__preview-table-wrapper" label="Preview import Guru">
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
                        <td>{row.username || '-'}{row.registration_mode === 'existing_account' && <small className="identity-directory-completeness-cell"> · Akun: {row.existing_name || 'Belum diketahui'}</small>}</td>
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
              </ImportPreviewScroll>

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
      </WorkspaceModal>}

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
            Profil hanya dapat dihapus jika belum memiliki histori akademik. Akun terkait akan dinonaktifkan, bukan dihapus.
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

      {modalOpen && <WorkspaceModal className="identity-form-workspace" busy={saving} eyebrow="IDENTITAS AKADEMIK"
        title={editingTeacher ? 'Edit Data Guru' : 'Tambah Guru'}
        description={
          editingTeacher
            ? 'Perbarui informasi Guru yang dipilih.'
            : 'Daftarkan identitas resmi dan akun, atau hubungkan akun yang sudah ada.'
        }
        onClose={closeModal}
        footer={
          <>
            <Button variant="secondary" type="button" disabled={saving} onClick={closeModal}>
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
            <div className="identity-form-section"><strong>Akun pengguna</strong><span>Atur hubungan akun dan identitas resmi.</span></div>
            {!editingTeacher && (
              <Select id="registration_mode" name="registration_mode" label="Cara pendaftaran"
                value={form.registration_mode} required disabled={saving} onChange={handleChange}
                options={[{ value: 'new_account', label: 'Buat profil dan akun baru' }, { value: 'existing_account', label: 'Hubungkan akun yang sudah ada' }]} />
            )}
            {(editingTeacher || form.registration_mode === 'existing_account') ? (
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
              hint={editingTeacher ? "Akun terhubung tidak dapat diganti." : "Akun aktif dengan role Guru yang belum memiliki profil."}
              onChange={handleChange}
            />
            ) : (
              <Input id="username" name="username" label="Username (opsional)"
                value={form.username} disabled={saving} maxLength={100} onChange={handleChange}
                placeholder={form.nip || 'Otomatis mengikuti NIP'}
                error={getFieldError(fieldErrors, 'username')}
                hint="Akun baru membutuhkan aktivasi. Password dibuat sendiri oleh pemilik akun." />
            )}


            <div className="identity-form-section"><strong>Identitas resmi</strong><span>Gunakan data yang tercatat di sekolah.</span></div>
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

            <div className="identity-form-section"><strong>Informasi kontak</strong><span>Opsional, dapat dilengkapi kemudian.</span></div>
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
      </WorkspaceModal>}
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
