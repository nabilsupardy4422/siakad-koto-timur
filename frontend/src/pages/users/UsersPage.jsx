
import { useCallback, useEffect, useRef, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import ToastNotification from '../../components/ui/ToastNotification'
import { academicProfileRoute } from '../identity/identityDirectoryLogic.js'
import { teacherApi } from '../../services/teacherApi'
import { studentApi } from '../../services/studentApi'
import WorkspaceModal from '../../components/ui/WorkspaceModal'
import '../identity/IdentityDirectory.css'

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
import UserActivationSlipModal from './UserActivationSlipModal.jsx'
import {codeMonitor,codeLabel,monitorKeys,wibDate} from './activationMonitorLogic.js'

import BulkAccountModal from './BulkAccountModal.jsx'
import { BATCH_LIMIT, isAcademic, mergeSelection, accountTotals, filteredSnapshot } from './bulkAccountLogic.js'
import { identityInitials } from '../identity/identityDirectoryPresentation.js'
import { academicYearApi } from '../../services/academicYearApi.js'
import { classApi } from '../../services/classApi.js'
import './UsersPage.css'
import {isStrongPassword, PASSWORD_POLICY_MESSAGE} from '../../components/security/passwordPolicy.js'
import PasswordRequirements from '../../components/security/PasswordRequirements.jsx'

const initialForm = {
  username: '',
  name: '',
  email: '',
  role_id: '',
  password: '',
  password_confirmation: '',
}

const initialResetForm = {
  password: '',
  password_confirmation: '',
}

const columns = [
  { key: 'identity', label: 'Identitas Pengguna' },
  { key: 'role', label: 'Role' },
  { key: 'status', label: 'Status Akun' },
  { key: 'profile', label: 'Profil akademik' },
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

function getRoleName(user, roles = []) {
  return (
    user?.role?.name ||
    roles.find(
      (item) => item.value === String(user?.role_id)
    )?.label ||
    '-'
  )
}

function getUserInitial(user) {
  const name = user?.name || user?.username || 'U'
  return identityInitials(name)
}

export default function UsersPage() {
  const { role, permissions = [] } = useAuth()
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const [roles, setRoles] = useState([])
  const [rolesError, setRolesError] = useState('')
  const [entry, setEntry] = useState(null)
  const [templateBusy, setTemplateBusy] = useState(false)
  const [templateError, setTemplateError] = useState('')
  async function downloadAcademicTemplate(code) {
    if (templateBusy || !canCreate) return
    setTemplateBusy(true); setTemplateError('')
    try {
      const blob = await (code === 'GURU' ? teacherApi : studentApi).downloadTemplate()
      if (!(blob instanceof Blob) || !blob.size) throw new Error('Template kosong.')
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a'); a.href = url; a.download = code === 'GURU' ? 'template_data_guru.xlsx' : 'template_data_siswa.xlsx'
      document.body.appendChild(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(url), 1000)
    } catch (e) {setTemplateError(e.message || 'Template gagal diunduh.')}
    finally {setTemplateBusy(false)}
  }
  const exactUserId = params.get('user_id') || ''

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
  const ROLES = roles.map(r => ({value: String(r.id), label: r.name, code: r.code}))
  useEffect(() => {
    if (!canView) return
    let live = true
    userApi.getRoles().then(r => {if (live) setRoles(Array.isArray(r?.data) ? r.data : [])}).catch(e => {if (live) setRolesError(e.message || 'Katalog role gagal dimuat.')})
    return () => {live = false}
  }, [canView])
  const [users, setUsers] = useState([])
  const [searchInput, setSearchInput] = useState('')
  const [search, setSearch] = useState('')
  const [roleFilter, setRoleFilter] = useState('')
  const [statusFilter, setStatusFilter] = useState('')
  const [page, setPage] = useState(1)
  const [sortOrder, setSortOrder] = useState('asc')
  const [pageSize, setPageSize] = useState('15')
  const [profileFilter, setProfileFilter] = useState('')
  const [codeFilter,setCodeFilter]=useState('')
  const [monitor,setMonitor]=useState(null)
  const [period, setPeriod] = useState('')
  const [periods, setPeriods] = useState([])
  const [classes, setClasses] = useState([])
  const [classFilter, setClassFilter] = useState('')
  const [contextError, setContextError] = useState('')
  const [summary, setSummary] = useState(null)
  const [summaryError, setSummaryError] = useState('')
  const [selected, setSelected] = useState([])
  const [bulk, setBulk] = useState(null)
  const [selectionNotice, setSelectionNotice] = useState('')
  const [selectingAll, setSelectingAll] = useState(false)
  const allRequest = useRef(0)
  const canBulk = roleCode === 'TU' && (canResetPassword || canDeactivate)
  const query = useCallback(() => ({search, role_id:roleFilter, account_state:statusFilter, profile_state:profileFilter,code_state:codeFilter,
    academic_year_id:period, class_id:classFilter==='unassigned'?'':classFilter,
    placement_state:classFilter==='unassigned'?'unassigned':'', sort_order:sortOrder,user_id:exactUserId}),
    [search,roleFilter,statusFilter,profileFilter,codeFilter,period,classFilter,sortOrder,exactUserId])
  const filterKey = JSON.stringify([query(),pageSize])
  const filterRef = useRef(filterKey)
  filterRef.current = filterKey
  useEffect(()=>{allRequest.current++;setSelected([]);setSelectionNotice('');setSelectingAll(false)},[filterKey])
  useEffect(()=>{
    if(!canView)return
    let live=true
    academicYearApi.getAll().then(r=>{if(!Array.isArray(r?.data))throw new Error('Katalog periode tidak valid.');if(live)setPeriods(r.data)})
      .catch(e=>{if(live)setContextError(e.message||'Periode tidak dapat dimuat.')})
    return()=>{live=false}
  },[canView])
  const activePeriods=periods.filter(y=>y.is_active===true||y.is_active===1)
  const effectivePeriod=period||(activePeriods.length===1?String(activePeriods[0].id):'')
  useEffect(()=>{
    setClasses([])
    if(!canView||!effectivePeriod)return
    let live=true
    async function load(){
      try{
        const rows=[];const ids=new Set();let last=1
        for(let page=1;page<=last;page++){
          const r=await classApi.getAll({academic_year_id:effectivePeriod,per_page:100,page})
          const next=Number(r?.meta?.last_page)
          if(!Array.isArray(r?.data)||Number(r?.meta?.current_page)!==page||!Number.isSafeInteger(next)||next<page||next>100||(page>1&&last!==next))throw new Error('Katalog kelas tidak valid.')
          last=next
          for(const c of r.data){if(String(c.tahun_akademik_id)!==effectivePeriod||ids.has(c.id))throw new Error('Identitas kelas tidak valid.');ids.add(c.id);rows.push(c)}
        }
        if(live){setClasses(rows);setContextError('')}
      }catch(e){if(live)setContextError(e.message||'Kelas gagal dimuat.')}
    }
    load();return()=>{live=false}
  },[canView,effectivePeriod])
  function addSelection(ids){
    try{setSelected(mergeSelection(selected,ids));setSelectionNotice('')}catch(e){setSelectionNotice(e.message)}
  }
  async function selectFiltered(){
    if(loading||listError||selectingAll||meta.total>BATCH_LIMIT)return
    const generation=++allRequest.current;const key=filterRef.current
    setSelectingAll(true);setSelectionNotice('')
    try{
      const response=await userApi.getAll({...query(),page:1,page_size:BATCH_LIMIT})
      if(generation!==allRequest.current||key!==filterRef.current)return
      const snapshot=filteredSnapshot(response,meta.total)
      setSelected(snapshot.ids);setSelectionNotice(snapshot.excluded?`${snapshot.excluded} akun administratif tidak disertakan.`:'Semua hasil filter telah dipilih.')
    }catch(e){if(generation===allRequest.current)setSelectionNotice(e.message)}
    finally{if(generation===allRequest.current)setSelectingAll(false)}
  }


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
  useEffect(()=>{
    if(!canView)return
    let live=true;setSummary(null);setMonitor(null);setSummaryError('')
    userApi.getActivationSummary().then(r=>{if(!live)return;const d=accountTotals(r);if(!d)throw new Error('Ringkasan tidak valid.');setSummary(d);setMonitor(codeMonitor(r))})
      .catch(e=>{if(live)setSummaryError(e.message||'Ringkasan gagal dimuat.')})
    return()=>{live=false}
  },[canView,reloadKey])


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
        ...query(), page, page_size:Number(pageSize),
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
  }, [canView, page, query, pageSize])

  useEffect(() => {
    loadUsers()

    return () => {
      requestIdRef.current += 1
    }
  }, [loadUsers, reloadKey])

  function refreshUsers() {
    setReloadKey((current) => current + 1)
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
    setEntry('register')
  }

  function chooseRegistration(code) {
    setEntry(null)
    if (code === 'GURU' || code === 'SISWA') {
      navigate(`/app/${code === 'GURU' ? 'teachers' : 'students'}?register=1`)
      return
    }
    const selected = roles.find(r => r.code === code)
    if (!selected) return
    clearUserForm()
    setForm({...initialForm, role_id: String(selected.id)})
    setSuccessMessage('')
    setModalOpen(true)
  }

  function openAcademicProfile(user) {
    const target = academicProfileRoute(user)
    if (target) navigate(target)
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
      password_confirmation: '',
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
    setParams({})
    setSearchInput('')
    setSearch('')
    setRoleFilter('')
    setStatusFilter('')
    setSortOrder('asc')
    setProfileFilter('');setCodeFilter('');setPeriod('');setClassFilter('');setPageSize('15')
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

    if (!editingUser) {
      if (!isStrongPassword(form.password)) localErrors.password = PASSWORD_POLICY_MESSAGE
      if (!form.password_confirmation || form.password_confirmation !== form.password) localErrors.password_confirmation = 'Konfirmasi password tidak sesuai.'
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
      payload.password_confirmation = form.password_confirmation
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
        setCodeFilter('')
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
    } else if (!isStrongPassword(resetForm.password)) {
      errors.password = PASSWORD_POLICY_MESSAGE
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
      <section className="users-page identity-directory-page">
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
    <section className="users-page identity-directory-page">
      <PageHeader
        eyebrow="Administrasi akun"
        title="Manajemen Pengguna"
        description="Kelola akun, role, dan status akses pengguna SIAKAD."
        actions={
          canCreate ? (
            <div className="users-page__header-actions">
              <Button variant="secondary" onClick={() => setEntry('import')}>Import Excel</Button>

              <Button onClick={openCreateModal}>
                + Tambah Pengguna
              </Button>
            </div>
          ) : null
        }
      />

      <ToastNotification notice={successMessage ? {type:'success', title:'Berhasil', message:successMessage} : null} onClose={() => setSuccessMessage('')}/>
      {rolesError && <ErrorState message={rolesError}/>}
      {exactUserId && <div className="users-page__exact-context"><span>Menampilkan akun terkait profil (ID {exactUserId}).</span><Button variant="secondary" onClick={resetFilters}>Lihat semua pengguna</Button></div>}
      {entry && <WorkspaceModal className="identity-entry-workspace" busy={templateBusy} title={entry === 'import' ? 'Import identitas akademik' : 'Pilih jenis pengguna'} eyebrow="MANAJEMEN AKUN" description={entry === 'import' ? 'Gunakan template resmi untuk membuat profil dan akun sekaligus.' : 'Pilih jenis identitas yang akan didaftarkan.'} onClose={() => {setEntry(null);setTemplateError('')}} footer={<Button variant="secondary" disabled={templateBusy} onClick={() => {setEntry(null);setTemplateError('')}}>Tutup</Button>}>
        <div className="identity-entry-grid">
          {['GURU','SISWA',...(entry === 'register' ? ['TU','KEPALA_SEKOLAH'] : [])].map(code => <article key={code} className="identity-entry-card">
            <span className="identity-entry-mark" aria-hidden="true">{({GURU:'G',SISWA:'S',TU:'TU',KEPALA_SEKOLAH:'KS'})[code]}</span>
            <h3>{({GURU:'Guru',SISWA:'Siswa',TU:'Tata Usaha',KEPALA_SEKOLAH:'Kepala Sekolah'})[code]}</h3>
            <p>{['GURU','SISWA'].includes(code) ? 'Identitas resmi dan akun dalam satu proses.' : 'Akun administratif tanpa profil akademik terpisah.'}</p>
            <div className="identity-entry-actions">{entry === 'import' && <Button variant="secondary" disabled={templateBusy} onClick={() => downloadAcademicTemplate(code)}>Unduh template</Button>}
            <Button disabled={templateBusy || (entry === 'register' && !['GURU','SISWA'].includes(code) && !roles.some(r => r.code === code))} onClick={() => entry === 'import' ? navigate(`/app/${code === 'GURU' ? 'teachers' : 'students'}?import=1`) : chooseRegistration(code)}>{entry==='import'?'Import':'Daftarkan'}</Button></div>
          </article>)}
        </div>
        {templateError && <ErrorState message={templateError}/>}
        <p className="identity-entry-note">Akun Guru dan Siswa baru memerlukan aktivasi mandiri. Akun existing dapat dilengkapi melalui profil akademik.</p>
      </WorkspaceModal>}

      <section className="account-overview" aria-label="Ringkasan seluruh akun">
        <div className="account-overview-head"><strong>Ringkasan akses pengguna</strong><span>Seluruh akun · di luar filter</span></div>
        <div className="account-overview-grid">{[['total','Total pengguna','Seluruh akun terdaftar'],['ready','Siap digunakan','Aktif dan dapat login'],['pending','Menunggu aktivasi','Perlu aktivasi mandiri'],['inactive','Nonaktif','Akses dinonaktifkan']].map(([key,label,note])=><article key={key} className={`account-metric account-metric-${key}`}><span>{label}</span><strong>{summary?summary[key].toLocaleString('id-ID'):'—'}</strong><small>{note}</small></article>)}</div>
        {summaryError&&<div className="account-summary-note" role="status">{summaryError} <Button size="small" variant="secondary" onClick={refreshUsers}>Coba lagi</Button></div>}
        <section className="account-code-monitor" aria-label="Monitoring kode aktivasi">
          <div className="account-code-monitor-head"><div><strong>Monitoring kode aktivasi</strong><small>Guru/Siswa aktif yang masih perlu aktivasi · seluruh akun</small></div><Button size="small" variant="secondary" onClick={refreshUsers}>Perbarui</Button></div>
          <div className="account-code-monitor-grid">{monitorKeys.map(key=><button key={key} type="button" aria-pressed={codeFilter===key} className={`account-code-monitor-item account-code-${key}`} disabled={!monitor} onClick={()=>{setParams({});setCodeFilter(codeFilter===key?'':key);setStatusFilter('');setRoleFilter('');setProfileFilter('');setPeriod('');setClassFilter('');setSearch('');setSearchInput('');setPage(1)}}><span>{codeLabel(key)}</span><strong>{monitor?monitor[key].toLocaleString('id-ID'):'—'}</strong></button>)}</div>
          {!monitor&&summary&&<p role="status">Data monitoring belum tersedia. Perbarui ringkasan.</p>}
        </section>
        {!!summary?.unknown&&<p className="account-summary-note">{summary.unknown} akun memiliki status belum diketahui.</p>}
      </section>
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

        <div className="account-role-tabs" role="group" aria-label="Filter role pengguna">
          {[{value:'',label:'Semua akun'},...ROLES].map(r=><button type="button" key={r.value} aria-pressed={roleFilter===r.value} onClick={()=>{setRoleFilter(r.value);setPeriod('');setClassFilter('');setPage(1)}}>{r.label}</button>)}
        </div>
        <form
          className="users-page__filters account-filter-panel"
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
            id="user-status-filter"
            name="account_status"
            label="Status akun"
            value={statusFilter}
            placeholder="Semua status"
            options={[
              { value: 'ready', label: 'Siap digunakan' },
              { value: 'pending', label: 'Menunggu aktivasi' },
              { value: 'inactive', label: 'Nonaktif' },
            ]}
            onChange={(event) => {
              setStatusFilter(event.target.value)
              setPage(1)
            }}
          />

          <Select id="user-sort" label="Urutan nama" value={sortOrder}
            options={[{value: 'asc', label: 'A–Z'}, {value: 'desc', label: 'Z–A'}]}
            onChange={event => {setSortOrder(event.target.value || 'asc'); setPage(1)}} />
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
          <details className="identity-directory-advanced"><summary>Filter lanjutan & tampilan {(profileFilter||period||classFilter||codeFilter)&&<span className="identity-filter-active">Aktif</span>}</summary>
            <div className="identity-directory-advanced-grid">
              <Select id="user-code-state" label="Status kode aktivasi" value={codeFilter} placeholder="Semua kode" options={monitorKeys.map(key=>({value:key,label:codeLabel(key)}))} onChange={e=>{setCodeFilter(e.target.value);setPage(1)}}/>
              <Select id="user-profile-state" label="Profil akademik" value={profileFilter} placeholder="Semua" options={[{value:'linked',label:'Terhubung'},{value:'unlinked',label:'Belum terhubung'}]} onChange={e=>{setProfileFilter(e.target.value);setPage(1)}}/>
              <Select id="user-period" label="Periode siswa" value={period} placeholder="Semua periode" options={periods.map(y=>({value:String(y.id),label:`${y.tahun_mulai}/${y.tahun_selesai} · ${y.semester}`}))} onChange={e=>{setPeriod(e.target.value);setClassFilter('');setRoleFilter(String(roles.find(r=>r.code==='SISWA')?.id||''));setPage(1)}}/>
              <Select id="user-class" label="Kelas siswa" value={classFilter} disabled={!effectivePeriod||!!contextError} placeholder={period?'Semua kelas periode':'Semua kelas (filter kelas: periode aktif)'} options={[{value:'unassigned',label:'Belum ditempatkan'},...classes.map(c=>({value:String(c.id),label:`${({10:"X",11:"XI",12:"XII"})[Number(c.tingkat)]||`Tingkat ${c.tingkat}`} — ${c.nama}`}))]} onChange={e=>{setClassFilter(e.target.value);setRoleFilter(String(roles.find(r=>r.code==='SISWA')?.id||''));setPage(1)}}/>
              <Select id="user-size" label="Data per halaman" value={pageSize} options={[15,25,50,100].map(n=>({value:String(n),label:String(n)}))} onChange={e=>{setPageSize(e.target.value||'15');setPage(1)}}/>
            </div>{contextError&&<ErrorState message={contextError}/>}
          </details>
        </form>

        {canBulk&&!loading&&!listError&&users.length>0&&<div className="account-selection" aria-busy={selectingAll}>
          <div><strong>{selected.length} akun dipilih</strong><small>Maksimal 100 per batch · hanya Guru/Siswa</small></div>
          <div className="account-selection-actions">
            <Button size="small" variant="secondary" disabled={selectingAll} onClick={()=>addSelection(users.filter(isAcademic).map(u=>u.id))}>Pilih halaman</Button>
            <Button size="small" variant="secondary" disabled={selectingAll||meta.total>BATCH_LIMIT} onClick={selectFiltered}>Pilih semua hasil ({meta.total})</Button>
            <Button size="small" variant="secondary" disabled={selectingAll||!selected.length} onClick={()=>{setSelected([]);setSelectionNotice('')}}>Kosongkan</Button>
          </div>
          {selected.length>0&&<div className="account-bulk-actions">{canResetPassword&&<Button disabled={selectingAll} onClick={()=>setBulk({action:'issue',ids:[...selected]})}>Buat kode & slip PDF</Button>}{canDeactivate&&<Button variant="secondary" disabled={selectingAll} onClick={()=>setBulk({action:'deactivate',ids:[...selected]})}>Nonaktifkan terpilih</Button>}</div>}
          {meta.total>BATCH_LIMIT&&<small className="account-selection-note">Hasil lebih dari 100. Persempit filter atau pilih per halaman untuk setiap batch.</small>}
          {selectionNotice&&<small className="account-selection-note" role="status">{selectionNotice}</small>}
        </div>}

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
                scrollLabel="Tabel Manajemen Pengguna"
                columns={canBulk?[{key:'selection',label:'Pilih',className:'account-select-cell'},...columns]:columns}
                data={users}
                rowKey="id"
                renderCell={(user, column) => {
                  switch (column.key) {
                    case 'selection':
                      return <input type="checkbox" aria-label={`Pilih ${user.name||user.username}`} checked={selected.includes(user.id)} disabled={!isAcademic(user)||selectingAll} onChange={e=>e.target.checked?addSelection([user.id]):setSelected(old=>old.filter(id=>id!==user.id))}/>
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
                      return getRoleName(user, ROLES)

                    case 'status':
                      if (user.account_status !== 'active') {
                        return (
                          <Badge variant="neutral">
                            {user.account_status === 'inactive' ? 'Nonaktif' : 'Status belum diketahui'}
                          </Badge>
                        )
                      }

                      if (user.requires_activation === true) {
                        return (
                          <div className="account-status-stack"><span className="users-page__activation-pending">Belum Aktivasi</span><small>{codeLabel(user.activation_status?.state)}</small>{user.activation_status?.expires_at&&<small>{wibDate(user.activation_status.expires_at)}</small>}</div>
                        )
                      }

                      return (
                        <Badge variant={user.requires_activation === false ? "success" : "neutral"}>
                          {user.requires_activation === false ? "Siap digunakan" : "Status belum diketahui"}
                        </Badge>
                      )

                    case 'profile': {
                      const status = user.academic_profile?.status
                      if (status === 'linked' || status === 'unlinked') {
                        return <div className="users-page__profile-link">
                          <Badge variant={status === 'linked' ? 'success' : 'warning'}>{status === 'linked' ? 'Terhubung' : 'Belum terhubung'}</Badge>
                          {canCreate && academicProfileRoute(user) && <Button size="small" variant="secondary" onClick={() => openAcademicProfile(user)}>{status === 'linked' ? 'Lihat Profil' : 'Lengkapi Profil'}</Button>}
                          {status === 'unlinked' && user.account_status !== 'active' && <small>Aktifkan administratif dahulu.</small>}
                        </div>
                      }
                      return <Badge variant={status === 'conflict' ? 'danger' : 'neutral'}>{status === 'not_required' ? 'Tidak diperlukan' : status === 'conflict' ? 'Konflik relasi' : 'Belum diketahui'}</Badge>
                    }

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

                      return (
                        <div className="users-page__actions">
                          {canUpdate ||
                          canToggle ||
                          canResetPassword ||
                          canView ? (
                            <UserActionsMenu
                              user={user}
                              canUpdate={canUpdate}
                              canToggle={canToggle}
                              canReset={
                                canResetPassword &&
                                user.requires_activation !== true
                              }
                              canIssueActivation={canView}
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
              disabled={saving || editingUser?.academic_profile?.status === 'linked'}
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
              disabled={saving || editingUser?.academic_profile?.status === 'linked'}
              placeholder="Pilih role"
              options={ROLES.filter(r => ['TU','KEPALA_SEKOLAH'].includes(r.code) || (editingUser && String(editingUser.role_id) === r.value))}
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
                  autoComplete="new-password"
                  onChange={handleChange}
                />
                <Input id="password-confirmation" name="password_confirmation" label="Konfirmasi password awal" type="password" autoComplete="new-password" required disabled={saving} value={form.password_confirmation} error={getFieldError(fieldErrors, 'password_confirmation')} onChange={handleChange}/>
                <PasswordRequirements password={form.password} confirmation={form.password_confirmation}/>
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
                  autoComplete="new-password"
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
                  autoComplete="new-password"
                />
                <PasswordRequirements password={resetForm.password} confirmation={resetForm.password_confirmation}/>
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

      {bulk&&<BulkAccountModal action={bulk.action} ids={bulk.ids} onClose={()=>setBulk(null)} onCompleted={()=>{setSelected([]);refreshUsers()}}/>}
      {/* SLIP AKTIVASI GURU / SISWA */}

      {activationUser && (
        <UserActivationSlipModal
          key={activationUser.id}
          user={activationUser}
          canManageActivation={roleCode==='TU'&&canResetPassword}
          onIssue={()=>{const id=activationUser.id;setActivationUser(null);setBulk({action:'issue',ids:[id]})}}
          onChanged={refreshUsers}
          onClose={() => {setActivationUser(null);refreshUsers()}}
        />
      )}
    </section>
  )
}
