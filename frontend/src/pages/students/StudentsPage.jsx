import { useCallback, useEffect, useRef, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import ToastNotification from '../../components/ui/ToastNotification'
import IdentityDirectoryDetail from '../identity/IdentityDirectoryDetail.jsx'
import WorkspaceModal from '../../components/ui/WorkspaceModal'
import { DirectoryIdentity, DirectoryCompleteness, DirectoryOverview, ImportProgress, ImportPreviewScroll } from '../identity/IdentityDirectoryUI.jsx'
import { placementLabel } from '../identity/identityDirectoryLogic.js'
import { academicYearApi } from '../../services/academicYearApi'
import { classApi } from '../../services/classApi'


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
  registration_mode: 'new_account',
  username: '',
  user_id: '',
  nisn: '',
  nipd: '',
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
  { key: 'nipd', label: 'NIPD' },
  { key: 'gender', label: 'Jenis Kelamin' },
  { key: 'status', label: 'Status Akun' },
  { key: 'completeness', label: 'Biodata' },
  { key: 'placement', label: 'Kelas' },
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
  const [period, setPeriod] = useState('')
  const [periods, setPeriods] = useState([])
  const [classes, setClasses] = useState([])
  const [classFilter, setClassFilter] = useState('')
  const [contextError, setContextError] = useState('')

  const canManage =
    role?.code === 'TU' || permissions.includes('students.create')

  const [students, setStudents] = useState([])
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
  const [modalOpen, setModalOpen] = useState(false)
  const [editingStudent, setEditingStudent] = useState(null)
  const [form, setForm] = useState(initialForm)
  const [fieldErrors, setFieldErrors] = useState({})
  const [submitError, setSubmitError] = useState(null)
  const [overviewRevision, setOverviewRevision] = useState(0)
  const [successMessage, setSuccessMessage] = useState('')
  const [saving, setSaving] = useState(false)
  const [deletingId, setDeletingId] = useState(null)
  const [studentToDelete, setStudentToDelete] = useState(null)

const [importModalOpen, setImportModalOpen] = useState(false)
const [selectedExcel, setSelectedExcel] = useState(null)
const [previewData, setPreviewData] = useState(null)
const [importError, setImportError] = useState('')
const [previewLoading, setPreviewLoading] = useState(false)
const [importing, setImporting] = useState(false)
const [downloadingTemplate, setDownloadingTemplate] = useState(false)
const [excludedRows, setExcludedRows] = useState([])
const [originalPreviewRows, setOriginalPreviewRows] = useState([])

  const loadStudents = useCallback(async () => {
    const requestId = ++listRequest.current
    setLoading(true)
    setListError(null)

    try {
      const response = await studentApi.getAll({
        search,
        page,
        page_size: Number(pageSize),
        account_state: canManage ? accountFilter : '',
        gender: canManage ? genderFilter : '',
        completeness: canManage ? completeFilter : '',
        directory_context: canManage ? 1 : '',
        academic_year_id: canManage ? period : '',
        class_id: canManage && classFilter !== 'unassigned' ? classFilter : '',
        placement_state: canManage && classFilter === 'unassigned' ? 'unassigned' : '',
        sort_order: sortOrder,
      })

      if (requestId !== listRequest.current) return
      setStudents(Array.isArray(response?.data) ? response.data : [])
      setMeta((current) => ({
        ...current,
        ...(response?.meta || {}),
      }))
    } catch (error) {
      if (requestId === listRequest.current) setListError(error)
    } finally {
      if (requestId === listRequest.current) setLoading(false)
    }
  }, [page, search, sortOrder, pageSize, canManage, accountFilter, genderFilter, completeFilter, period, classFilter])

  useEffect(() => {
    loadStudents()
    return () => { listRequest.current += 1 }
  }, [loadStudents])

  async function openDetail(id) {
    const generation = ++detailRequest.current
    setDetailError('')
    try {
      const response = await studentApi.getById(id, {directory_context:1, academic_year_id:period})
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

  useEffect(() => {
    if (!canManage) return
    let live = true
    academicYearApi.getAll().then(r => {
      if (!Array.isArray(r?.data)) throw new Error('Data periode tidak valid.')
      if (live) setPeriods(r.data)
    }).catch(e => {if(live) setContextError(e.message || 'Periode gagal dimuat.')})
    return () => {live=false}
  }, [canManage])
  const activePeriods = periods.filter(y => y.is_active === true || y.is_active === 1)
  const effectivePeriod = period || (activePeriods.length === 1 ? String(activePeriods[0].id) : '')
  useEffect(() => {
    setClasses([])
    if (!canManage || !effectivePeriod) return
    let live = true
    async function loadClasses() {
      try {
        const all = []
        let page = 1
        while (true) {
          const res = await classApi.getAll({academic_year_id:effectivePeriod, per_page:100, page})
          if (!Array.isArray(res?.data) || Number(res?.meta?.current_page)!==page || !Number.isInteger(Number(res?.meta?.last_page)) || res.data.some(c=>String(c.tahun_akademik_id)!==effectivePeriod)) throw new Error('Katalog kelas tidak valid.')
          all.push(...res.data)
          if (page >= Number(res.meta.last_page)) break
          if (++page > 100) throw new Error('Katalog kelas terlalu besar.')
        }
        if(live){setClasses(all);setContextError('')}
      } catch(e){if(live)setContextError(e.message || 'Kelas gagal dimuat.')}
    }
    loadClasses()
    return () => {live=false}
  }, [canManage,effectivePeriod])

  function resetForm() {
    setForm(initialForm)
    setFieldErrors({})
    setSubmitError(null)
    setEditingStudent(null)
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
      const response = await studentApi.getAvailableAccounts()
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

  async function openEditModal(student) {
    formRequest.current += 1
    resetForm()
    setSuccessMessage('')
    setEditingStudent(student)

    setForm({
      registration_mode: 'existing_account',
      username: '',
      user_id: String(student.user_id ?? ''),
      nisn: student.nisn ?? '',
      nipd: student.nipd ?? '',
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

    setAccounts(student.user ? [{id:student.user_id, username:student.user.username, name:student.user.name}] : [])
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

    if ((editingStudent || form.registration_mode === 'existing_account') && !form.user_id) {
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
      ...(editingStudent ? { user_id: Number(form.user_id), profile_version: editingStudent.profile_version } : {
        registration_mode: form.registration_mode,
        ...(form.registration_mode === 'new_account'
          ? (form.username.trim() ? { username: form.username.trim() } : {})
          : { user_id: Number(form.user_id) }),
      }),
      nisn: form.nisn.trim(),
      nipd: form.nipd.trim() || null,
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
        setOverviewRevision(value=>value+1)
        setSuccessMessage('Data siswa berhasil diperbarui.')
      } else {
        await studentApi.create(payload)
        setOverviewRevision(value=>value+1)
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
        setOverviewRevision(value=>value+1)
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

  function openImportModal() {
  setSelectedExcel(null)
  setPreviewData(null)
  setExcludedRows([])
  setOriginalPreviewRows([])
  setImportError('')
  setImportModalOpen(true)
  setSuccessMessage('')
}

function closeImportModal() {
  if (previewLoading || importing) return

  setImportModalOpen(false)
  setSelectedExcel(null)
  setPreviewData(null)
  setExcludedRows([])
  setOriginalPreviewRows([])
  setImportError('')
}

async function toggleExcludedRow(rowNumber) {
  if (previewLoading || importing) return
  const next = excludedRows.includes(rowNumber) ? excludedRows.filter(r=>r!==rowNumber) : [...excludedRows,rowNumber]
  setExcludedRows(next)
  await handlePreviewImport(next)
}

async function handleDownloadTemplate() {
  setDownloadingTemplate(true)
  setImportError('')

  try {
    const blob = await studentApi.downloadTemplate()
    downloadBlob(blob, 'template_data_siswa.xlsx')
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
  setExcludedRows([])
  setOriginalPreviewRows([])
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

async function handlePreviewImport(exclusions = excludedRows) {
  if (!Array.isArray(exclusions)) exclusions = excludedRows
  if (!selectedExcel) {
    setImportError('Pilih file Excel terlebih dahulu.')
    return
  }

  const formData = new FormData()
  formData.append('file', selectedExcel)
  exclusions.forEach(row=>formData.append('excluded_rows[]',String(row)))

  setPreviewLoading(true)
  setImportError('')
  setPreviewData(null)

  try {
    const response = await studentApi.previewImport(formData)

    setPreviewData(response?.data || null)
    if (exclusions.length === 0) setOriginalPreviewRows(response?.data?.rows || [])

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

  if (previewSummary.invalid_rows > 0) {
    setImportError(
      'Masih terdapat baris yang tidak valid. Perbaiki file Excel atau keluarkan baris duplikat terlebih dahulu.',
    )
    return
  }

  if (previewSummary.valid_rows === 0) {
    setImportError('Tidak ada data Siswa valid untuk diimpor.')
    return
  }

  if (importing || previewLoading) return

  const formData = new FormData()
  formData.append('file', selectedExcel)
  formData.append('preview_token', previewData.preview_token)

  excludedRows.forEach((rowNumber) => {
    formData.append('excluded_rows[]', String(rowNumber))
  })

  setImporting(true)
  setImportError('')

  try {
    const response = await studentApi.importExcel(formData)
    setOverviewRevision(value=>value+1)

    const importedRows =
      response?.data?.total_imported

    setImportModalOpen(false)
    setSelectedExcel(null)
    setPreviewData(null)
    setExcludedRows([])

    setSuccessMessage(
      Number.isInteger(importedRows) ? `${importedRows} data Siswa berhasil diimpor.` : 'Import selesai. Muat ulang daftar untuk memeriksa hasil.',
    )

    setPage(1)

    if (page === 1) {
      await loadStudents()
    }
  } catch (error) {
    setImportError(
      getApiMessage(error, 'Import Data Siswa gagal dilakukan.'),
    )

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

const activePreviewRows = previewData?.rows || []
 const previewSummary = previewData?.summary || {total_rows:0, valid_rows:0, invalid_rows:0}
 const displayRows = [...activePreviewRows, ...originalPreviewRows.filter(row=>excludedRows.includes(Number(row.row)))].sort((a,b)=>Number(a.row)-Number(b.row))

  const accountOptions = accounts.map((account) => ({
    value: String(account.id),
    label: `${account.name || account.username} (${account.username})`,
  }))

  return (
    <section className="students-page identity-directory-page">
      <PageHeader
  eyebrow="Administrasi akademik"
  title="Data Siswa"
  description="Kelola identitas dan informasi dasar siswa."
  actions={
    canManage ? (
      <div className="students-page__header-actions">
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
          + Tambah Siswa
        </Button>
      </div>
    ) : null
  }
/>

      <ToastNotification notice={successMessage ? {type:'success', title:'Berhasil', message:successMessage} : null} onClose={() => setSuccessMessage('')}/>
      {detailError && <ErrorState message={detailError}/>}
      {detail && <IdentityDirectoryDetail profile={detail} student={true} onClose={() => setDetail(null)} onDelete={canManage ? () => {const target=detail;setDetail(null);openDeleteConfirmation(target)} : null} onEdit={canManage ? () => {const target=detail;setDetail(null);openEditModal(target)} : null} onAccount={canManage && detail.user_id ? () => navigate(`/app/users?user_id=${detail.user_id}`) : null}/>}
      {contextError && <ErrorState message={contextError}/>}
            {canManage && <DirectoryOverview api={studentApi} student={true} revision={overviewRevision}/>}
      <Card className="students-page__card">
        <div className="students-page__list-header">
          <div>
            <p className="students-page__eyebrow">Data akademik</p>
            <h2>Daftar Siswa</h2>
            <p>Data identitas siswa yang tersimpan di sistem.</p>
          </div>

          <span className="students-page__count" aria-live="polite">{loading ? "Memuat…" : listError ? "Tidak tersedia" : `${meta.total} hasil`}</span>
        </div>

        <form className="students-page__search" onSubmit={handleSearch}>
          <Input
            id="student-search"
            name="search"
            label="Cari siswa"
            value={searchInput}
            placeholder="Cari nama, NISN, NIPD atau username"
            onChange={(event) => setSearchInput(event.target.value)}
          />

          <Select id="student-sort" label="Urutan nama" value={sortOrder}
            options={[{value: 'asc', label: 'A–Z'}, {value: 'desc', label: 'Z–A'}]}
            onChange={event => {setSortOrder(event.target.value || 'asc'); setPage(1)}} />
          {canManage && <>
            <Select id="student-accountFilter" label="Status akun" value={accountFilter} placeholder="Semua status" options={[{value:'ready',label:'Siap digunakan'},{value:'pending',label:'Menunggu aktivasi'},{value:'inactive',label:'Nonaktif'}]} onChange={e=>{setAccountFilter(e.target.value);setPage(1)}}/>
          </>}
          <div className="students-page__search-actions">
            <Button type="submit">Cari</Button>
            <Button
              variant="secondary"
              type="button"
              onClick={() => {
                setSearchInput('')
                setSearch('')
                setSortOrder('asc')
                setAccountFilter(''); setGenderFilter(''); setCompleteFilter(''); setPageSize('15')
                setPeriod(''); setClassFilter('')
                setPage(1)
              }}
            >
              Reset
            </Button>
          </div>
          <details className="identity-directory-advanced">
            <summary>Filter lanjutan & tampilan {(completeFilter || genderFilter || period || classFilter) ? <span className="identity-filter-active">Filter aktif</span> : null}</summary>
            <div className="identity-directory-advanced-grid">
          {canManage && <>
            <Select id="student-completeFilter" label="Kelengkapan biodata" value={completeFilter} placeholder="Semua biodata" options={[{value:'complete',label:'Lengkap'},{value:'incomplete',label:'Belum lengkap'}]} onChange={e=>{setCompleteFilter(e.target.value);setPage(1)}}/>
            <Select id="student-genderFilter" label="Jenis kelamin" value={genderFilter} placeholder="Semua" options={[{value:'L',label:'Laki-laki'},{value:'P',label:'Perempuan'}]} onChange={e=>{setGenderFilter(e.target.value);setPage(1)}}/>
            <Select id="student-period" label="Periode akademik" value={period} placeholder="Periode aktif" options={[...periods.map(y=>({value:String(y.id),label:`${y.tahun_mulai}/${y.tahun_selesai} · ${y.semester}`}))]} onChange={e=>{setPeriod(e.target.value);setClassFilter('');setClasses([]);setPage(1)}}/>
            <Select id="student-classFilter" label="Kelas" value={classFilter} disabled={!effectivePeriod} placeholder="Semua kelas" options={[{value:'unassigned',label:'Tanpa penempatan efektif'},...classes.map(c=>({value:String(c.id),label:c.nama}))]} onChange={e=>{setClassFilter(e.target.value);setPage(1)}}/>
          </>}
          <Select id="student-pageSize" label="Data per halaman" value={pageSize} options={['15','25','50','100'].map(x=>({value:x,label:x}))} onChange={e=>{setPageSize(e.target.value);setPage(1)}}/>
            </div>
          </details>
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
            title={search || accountFilter || completeFilter || genderFilter || classFilter ? "Siswa tidak ditemukan" : "Belum ada data Siswa"}
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
              scrollLabel="Tabel Data Siswa"
              columns={canManage ? columns : columns.filter(c => !['status','completeness','placement'].includes(c.key))}
              data={students}
              rowKey="id"
              renderCell={(student, column) => {
                switch (column.key) {
                  case 'identity':
                    return (
                      <DirectoryIdentity name={getStudentName(student)} username={student.user?.username} email={student.user?.email} showAccount={canManage}/>
                    )

                  case 'nisn':
                    return student.nisn || '-'

                  case 'nipd':
                    return student.nipd || '-'

                  case 'gender':
                    return student.jenis_kelamin === 'L' ? 'Laki-laki' : student.jenis_kelamin === 'P' ? 'Perempuan' : student.jenis_kelamin || '-'

                  case 'status':
                    return student.user?.account_status === 'active' ? (
                      <Badge variant={student.user.requires_activation === true ? 'warning' : student.user.requires_activation === false ? 'success' : 'neutral'}>{student.user.requires_activation === true ? 'Menunggu aktivasi' : student.user.requires_activation === false ? 'Siap digunakan' : 'Status belum diketahui'}</Badge>
                    ) : <Badge variant="neutral">{student.user?.account_status === 'inactive' ? 'Nonaktif' : 'Tidak diketahui'}</Badge>
                  case 'completeness':
                    return <DirectoryCompleteness value={student.completeness}/>
                  case 'placement':
                    return placementLabel(student.placement)
                  case 'actions':
                    return <Button variant="secondary" onClick={() => openDetail(student.id)}>Lihat detail <span aria-hidden="true">›</span></Button>

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

      {modalOpen && <WorkspaceModal className="identity-form-workspace" busy={saving} eyebrow="IDENTITAS AKADEMIK"
        title={editingStudent ? 'Edit Data Siswa' : 'Tambah Siswa'}
        description={
          editingStudent
            ? 'Perbarui informasi siswa yang dipilih.'
            : 'Daftarkan identitas resmi dan akun, atau hubungkan akun yang sudah ada.'
        }
        onClose={closeModal}
        footer={
          <>
            <Button variant="secondary" type="button" disabled={saving} onClick={closeModal}>
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
            <div className="identity-form-section"><strong>Akun pengguna</strong><span>Atur hubungan akun dan identitas resmi.</span></div>
            {!editingStudent && (
              <Select id="registration_mode" name="registration_mode" label="Cara pendaftaran"
                value={form.registration_mode} required disabled={saving} onChange={handleChange}
                options={[{ value: 'new_account', label: 'Buat profil dan akun baru' }, { value: 'existing_account', label: 'Hubungkan akun yang sudah ada' }]} />
            )}
            {(editingStudent || form.registration_mode === 'existing_account') ? (
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
              hint={editingStudent ? "Akun terhubung tidak dapat diganti." : "Akun aktif dengan role Siswa yang belum memiliki profil."}
              onChange={handleChange}
            />
            ) : (
              <Input id="username" name="username" label="Username (opsional)"
                value={form.username} disabled={saving} maxLength={100} onChange={handleChange}
                placeholder={form.nisn || 'Otomatis mengikuti NISN'}
                error={getFieldError(fieldErrors, 'username')}
                hint="Akun baru membutuhkan aktivasi. Password dibuat sendiri oleh pemilik akun." />
            )}


            <div className="identity-form-section"><strong>Identitas resmi</strong><span>Gunakan data yang tercatat di sekolah.</span></div>
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
              id="nipd"
              name="nipd"
              label="NIPD"
              type="text"
              inputMode="numeric"
              maxLength={50}
              value={form.nipd}
              disabled={saving}
              error={getFieldError(fieldErrors, 'nipd')}
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
      </WorkspaceModal>}

            {importModalOpen && <WorkspaceModal className="identity-import-workspace" busy={previewLoading || importing} eyebrow="IMPORT EXCEL"
        title="Import Data Siswa"
        description="Unggah file Excel asli hasil ekspor Data Peserta Didik dari sekolah."
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

            {previewData && <Button type="button" variant="secondary" disabled={previewLoading || importing} onClick={()=>handlePreviewImport()}>Periksa ulang</Button>}
            {previewData && (
              <Button
                type="button"
                loading={importing}
                disabled={
                  importing ||
                  previewLoading ||
                  previewSummary.invalid_rows > 0 ||
                  previewSummary.valid_rows === 0 || !previewData.preview_token
                }
                onClick={handleConfirmImport}
              >
                Konfirmasi Import
              </Button>
            )}
          </>
        }
      >
        <div className="students-page__import-card">
          <ImportProgress preview={Boolean(previewData)} busy={previewLoading || importing} importing={importing}/>
          {excludedRows.length > 0 && <div className="identity-directory-context"><span>{excludedRows.length} baris dikecualikan</span><Button variant="secondary" disabled={previewLoading || importing} onClick={()=>{setExcludedRows([]);handlePreviewImport([])}}>Pulihkan semua baris</Button></div>}
          <details className="identity-import-settings" open={!previewData && !previewLoading}>
            <summary><span>File &amp; pengaturan</span><span className="identity-import-filename">{selectedExcel?.name || 'Pilih file'}</span></summary>
            <div className="identity-import-settings-body">
          <details className="identity-import-guide"><summary>Ketentuan file dan template</summary>          <div className="students-page__import-heading">
            <h3>Petunjuk Import</h3>
            <p>
              Gunakan file Excel asli hasil ekspor Data Peserta Didik
              dari sekolah. Pastikan data siswa telah sesuai dengan
              identitas resmi sekolah.
            </p>
          </div>

          <div className="students-page__import-info">
            <strong>Ketentuan file:</strong>
            <br />
            Format: .xlsx
            <br />
            Ukuran maksimal: 2 MB
            <br />
            Maksimal data: 1.000 baris
            <br />
            Header: Nama, NIPD, JK, NISN, dan kolom template lainnya. Nama dan NISN wajib terisi.
            <br />
            NISN wajib tersedia dan tidak boleh duplikat.
          </div>

<Button variant="secondary" disabled={previewLoading || importing || downloadingTemplate} loading={downloadingTemplate} onClick={handleDownloadTemplate}>Unduh template Siswa</Button></details>
          <div className="students-page__file-control">
            <label
              htmlFor="student-excel-file"
              className="students-page__file-label"
            >
              Pilih File Excel
            </label>

            <input
              id="student-excel-file"
              className="students-page__file-input"
              type="file"
              accept=".xlsx"
              onChange={handleExcelChange}
              disabled={previewLoading || importing}
            />

            {selectedExcel && (
              <p className="students-page__selected-file">
                File dipilih: <strong>{selectedExcel.name}</strong>
              </p>
            )}
          </div>

            </div>
          </details>

          {importError && (
            <div
              className="students-page__import-error"
              role="alert"
            >
              {importError}
            </div>
          )}

          {previewData && (
            <div className="students-page__preview">
              <div className="students-page__preview-heading">
                <h3>Hasil Preview Import</h3>
                <p>
                  Periksa hasil validasi sebelum mengimpor data.
                </p>
              </div>

              <div className="students-page__preview-summary">
                <div className="students-page__preview-stat">
                  <span>Baris disertakan</span>
                  <strong>
                    {previewSummary.total_rows }
                  </strong>
                </div>

                <div className="students-page__preview-stat students-page__preview-stat--valid">
                  <span>Data Valid</span>
                  <strong>
                    {previewSummary.valid_rows}
                  </strong>
                </div>

                <div className="students-page__preview-stat students-page__preview-stat--invalid">
                  <span>Data Tidak Valid</span>
                  <strong>
                    {previewSummary.invalid_rows}
                  </strong>
                </div>
              </div>

              <ImportPreviewScroll className="students-page__preview-table-wrapper" label="Preview import Siswa">
  <table className="students-page__preview-table">
    <thead>
      <tr>
        <th>Baris</th>
        <th>Aksi</th>
        <th>NISN</th>
        <th>NIPD</th>
        <th>Nama Lengkap</th>
        <th>Status / Tindakan</th>
        <th>Keterangan</th>
      </tr>
    </thead>

   <tbody>
  {displayRows.map((row) => {
    const isExcluded = excludedRows.includes(Number(row.row))

    const activeRow = activePreviewRows.find(
      (item) => Number(item.row) === Number(row.row),
    )

    const isValid = isExcluded ? false : activeRow?.valid

    const rowErrors = isExcluded
      ? row.errors || []
      : activeRow?.errors || []

    return (
      <tr key={row.row}>
        <td>{row.row}</td>
        <td>
          {(isExcluded || !isValid) && (
            <Button
              variant="secondary"
              type="button"
              disabled={previewLoading || importing}
              onClick={() => toggleExcludedRow(Number(row.row))}
            >
              {isExcluded ? 'Batalkan' : 'Keluarkan'}
            </Button>
          )}
        </td>
        <td>{row.nisn || '-'}</td>
        <td>{row.nipd || '-'}</td>
        <td>{row.nama_lengkap || '-'}</td>

        <td>
          <span
            className={`students-page__preview-status ${
              isExcluded
                ? 'students-page__preview-status--excluded'
                : isValid
                  ? 'students-page__preview-status--valid'
                  : 'students-page__preview-status--invalid'
            }`}
          >
            {isExcluded
              ? 'Dikecualikan'
              : isValid
                ? (row.status === 'new' ? 'Daftar baru' : 'Pembaruan')
                : 'Tidak Valid'}
          </span>
        </td>

        <td className="students-page__preview-error">
          {rowErrors.length > 0
            ? rowErrors.join(' ')
            : isExcluded
              ? 'Baris dikeluarkan dari proses import.'
              : row.status === 'updated' ? (Object.entries(row.changes || {}).map(([field, change]) => `${field}: ${change.before ?? 'kosong'} → ${change.after}`).join('; ') || 'Tidak ada perubahan; kolom kosong dipertahankan.') : 'Buat akun dan profil.'}
        </td>


      </tr>
    )
  })}
</tbody>
  </table>
              </ImportPreviewScroll>

              {previewSummary.invalid_rows > 0 && (
                <div className="students-page__preview-note">
                  Import belum dapat dilakukan karena masih ada{' '}
                  {previewSummary.invalid_rows} baris yang tidak
                  valid. Gunakan Keluarkan untuk mengecualikan baris, atau perbaiki file lalu unggah ulang.
                </div>
              )}

              {previewSummary.invalid_rows === 0 &&
                previewSummary.valid_rows > 0 && (
                  <div className="students-page__import-info">
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
              Profil hanya dapat dihapus jika belum memiliki histori akademik. Akun terkait akan dinonaktifkan, bukan dihapus.
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
