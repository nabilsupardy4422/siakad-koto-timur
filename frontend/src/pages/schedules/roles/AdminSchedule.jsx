import { readClassTarget } from '../../classes/classDirectoryLogic.js'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'

import PageHeader from '../../../components/ui/PageHeader'
import Card from '../../../components/ui/Card'
import Table from '../../../components/ui/Table'
import Select from '../components/SchedulePicker.jsx'
import ScheduleContinuationDialog from '../components/ScheduleContinuationDialog.jsx'
import {loadScheduleSnapshot, filterRows, scheduleSummary, teacherDistribution, isActive} from './adminScheduleWorkspace.js'
import {loadClassCatalog} from '../../classes/classDirectoryLogic.js'
import Input from '../../../components/ui/Input'
import Button from '../../../components/ui/Button'
import Modal from '../../../components/ui/Modal'
import EmptyState from '../../../components/ui/EmptyState'
import ErrorState from '../../../components/ui/ErrorState'
import LoadingState from '../../../components/ui/LoadingState'

import { scheduleApi } from '../../../services/scheduleApi'
import { academicYearApi } from '../../../services/academicYearApi'
import { classApi } from '../../../services/classApi'
import { teacherApi } from '../../../services/teacherApi'
import { subjectApi } from '../../../services/subjectApi'

import '../styles/admin-schedule.css'

const EMPTY_ROWS = Object.freeze([])

const initialForm = {
  tahun_akademik_id: '',
  semester: '',
  kelas_id: '',
  mapel_id: '',
  guru_id: '',
  hari: '',
  jam_mulai: '',
  jam_selesai: '',
}

const initialFilters = {
  academic_year_id: '',
  semester: '',
  academic_period: '',
  class_id: '',
  subject_id: '',
  teacher_id: '',
  day: '',
}

const DAYS = [
  { value: 'SENIN', label: 'Senin' },
  { value: 'SELASA', label: 'Selasa' },
  { value: 'RABU', label: 'Rabu' },
  { value: 'KAMIS', label: 'Kamis' },
  { value: 'JUMAT', label: 'Jumat' },
  { value: 'SABTU', label: 'Sabtu' },
  { value: 'MINGGU', label: 'Minggu' },
]

const columns = [
  { key: 'day', label: 'Hari' },
  { key: 'time', label: 'Waktu' },
  { key: 'class', label: 'Kelas' },
  { key: 'subject', label: 'Mata Pelajaran' },
  { key: 'teacher', label: 'Guru' },
  { key: 'semester', label: 'Semester' },
  { key: 'actions', label: 'Aksi' },
]

function fieldError(errors, field) {
  const value = errors?.[field]
  return Array.isArray(value) ? value[0] || '' : value || ''
}

function apiMessage(error, fallback) {
  return (
    error?.payload?.message ||
    error?.response?.data?.message ||
    error?.data?.message ||
    error?.message ||
    fallback
  )
}

function relationId(item, field, relation) {
  return item?.[field] ?? item?.[relation]?.id ?? '-'
}

function yearLabel(year) {
  return `${year.tahun_mulai}/${year.tahun_selesai}`
}

function teacherLabel(teacher) {
  return (
    teacher?.nama_lengkap ||
    teacher?.user?.name ||
    teacher?.nip ||
    `Guru #${teacher?.id}`
  )
}

function subjectLabel(subject) {
  if (!subject) return '-'

  return subject.kode
    ? `${subject.kode} - ${subject.nama}`
    : subject.nama || `Mapel #${subject.id}`
}

function classLabel(item) {
  if (!item) return 'Kelas tidak diketahui'

  const name = item.nama || item.nama_kelas || `Kelas #${item.id}`
  const level = item.tingkat

  return level !== null && level !== undefined && level !== ''
    ? `${name} — Tingkat ${level}`
    : name
}

function displayTime(value) {
  return value ? String(value).slice(0, 5) : '-'
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

function extractValidationErrors(error) {
  // apiClient menormalisasi error dan menyimpan detail validasi
  // pada error.validationErrors.
  return (
    error?.validationErrors ||
    error?.payload?.data?.errors ||
    error?.payload?.errors ||
    error?.response?.data?.data?.errors ||
    error?.response?.data?.errors ||
    error?.data?.data?.errors ||
    error?.data?.errors ||
    error?.errors ||
    {}
  )
}

function validationFieldLabel(field) {
  const rowMatch = String(field).match(/^baris_(\d+)$/i)

  if (rowMatch) return `Baris ${rowMatch[1]}`

  return String(field)
    .replaceAll('_', ' ')
    .replace(/\b\w/g, (character) => character.toUpperCase())
}

function validationMessages(errors) {
  if (!errors || typeof errors !== 'object') return []

  return Object.entries(errors).flatMap(([field, messages]) => {
    const values = Array.isArray(messages) ? messages : [messages]

    return values
      .filter((message) => message !== null && message !== undefined && message !== '')
      .map((message) => ({
        field: validationFieldLabel(field),
        message: String(message),
      }))
  })
}


async function loadTeacherCatalog() {
  return loadClassCatalog(page => teacherApi.getAll({per_page:100,page}))
}

function StatIcon({kind}) {
  const paths={total:'M4 6h16v14H4z M8 3v6 M16 3v6 M4 11h16',teachers:'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8 M22 21v-2a4 4 0 0 0-3-4 M16 3a4 4 0 0 1 0 8',classes:'M3 21h18 M5 21V9l7-6 7 6v12 M10 21v-6h4v6',subjects:'M12 5c-3-2-6-2-9-1v15c3-1 6-1 9 1 3-2 6-2 9-1V4c-3-1-6-1-9 1z M12 5v15',empty:'M4 6h16v14H4z M8 3v6 M16 3v6 M4 11h16 M9 16h6',conflicts:'M12 3l9 5v5c0 5-4 7-9 9-5-2-9-4-9-9V8z M9 12l2 2 4-4'}
  return <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round"><path d={paths[kind]}/></svg>
}

export default function AdminSchedule() {
  const canManage = true

  const [snapshot, setSnapshot] = useState(null)
  const [view,setView] = useState('list')
  const [excelOpen,setExcelOpen] = useState(false)
  const [copyOpen,setCopyOpen] = useState(false)
  const [years, setYears] = useState([])
  const [classes, setClasses] = useState([])
  const [teachers, setTeachers] = useState([])
  const [subjects, setSubjects] = useState([])

  const [filters, setFilters] = useState(() => ({...initialFilters,...readClassTarget(window.location.search)}))
  const [page, setPage] = useState(1)

  const [loading, setLoading] = useState(true)
  const [optionsLoading, setOptionsLoading] = useState(true)
  const [listError, setListError] = useState(null)
  const [optionsError, setOptionsError] = useState(null)

  const [modalOpen, setModalOpen] = useState(false)
  const [editingSchedule, setEditingSchedule] = useState(null)
  const [scheduleToDelete, setScheduleToDelete] = useState(null)

  const [form, setForm] = useState(initialForm)
  const [fieldErrors, setFieldErrors] = useState({})
  const [submitError, setSubmitError] = useState(null)
  const [saving, setSaving] = useState(false)
  const [deletingId, setDeletingId] = useState(null)
  const [successMessage, setSuccessMessage] = useState('')
  const [selectedExcel, setSelectedExcel] = useState(null)
  const [preview, setPreview] = useState(null)
  const [includeInactive, setIncludeInactive] = useState(false)
  const listRequest = useRef(0)
  const [importing, setImporting] = useState(false)
  const [exporting, setExporting] = useState(false)
  const [downloadingTemplate, setDownloadingTemplate] = useState(false)
  const [excelError, setExcelError] = useState('')
  const [excelValidationErrors, setExcelValidationErrors] = useState([])
  const fileInputRef = useRef(null)

  const loadOptions = useCallback(async () => {
    setOptionsLoading(true)
    setOptionsError(null)

    try {
      const [
        yearResponse,
        classResponse,
        teacherResponse,
        subjectResponse,
      ] = await Promise.all([
        academicYearApi.getAll({ per_page: 100 }),
        classApi.getCatalog(),
        loadTeacherCatalog(),
        subjectApi.getCatalog(),
      ])

      const yearRows = Array.isArray(yearResponse?.data) ? yearResponse.data : []
      setYears(yearRows)
      setFilters(current => {
        if (!current.academic_year_id) {
          const active = yearRows.filter(isActive)
          return active.length === 1 ? {...current, academic_year_id:String(active[0].id), semester:String(active[0].semester).toUpperCase()} : current
        }
        if (current.semester) return current
        const year = yearRows.find(item => String(item.id) === current.academic_year_id)
        return year ? {...current, semester: String(year.semester).toUpperCase()} : current
      })
      setClasses(Array.isArray(classResponse?.data) ? classResponse.data : [])
      setTeachers(
        Array.isArray(teacherResponse?.data) ? teacherResponse.data : [],
      )
      setSubjects(
        Array.isArray(subjectResponse?.data) ? subjectResponse.data : [],
      )
    } catch (error) {
      setOptionsError(error)
    } finally {
      setOptionsLoading(false)
    }
  }, [])

  const period = filters.academic_year_id
  const semester = filters.semester
  const selectedPeriod = years.find(y=>String(y.id)===period && String(y.semester).toUpperCase()===semester)
  const periodClasses = useMemo(()=>classes.filter(c=>String(c.tahun_akademik_id)===period),[classes,period])
  const loadSchedules = useCallback(async () => {
    const requestId = ++listRequest.current
    setLoading(true);setListError(null);setSnapshot(null)
    if (!period || !semester) {setLoading(false);return}
    try {
      const rows=await loadScheduleSnapshot(params=>scheduleApi.getAll(params),period,semester)
      if(requestId===listRequest.current)setSnapshot({period,semester,rows})
    } catch(error) {
      if(requestId===listRequest.current)setListError(error)
    } finally {if(requestId===listRequest.current)setLoading(false)}
  },[period,semester])
  useEffect(()=>{loadOptions()},[loadOptions])
  useEffect(()=>{loadSchedules();return()=>{listRequest.current++}},[loadSchedules])
  const ready=Boolean(selectedPeriod && !optionsLoading && !optionsError && !loading && !listError && snapshot?.period===period && snapshot?.semester===semester)
  const rows=ready?snapshot.rows:EMPTY_ROWS
  const filtered=useMemo(()=>filterRows(rows,filters),[rows,filters])
  const summary=useMemo(()=>ready?scheduleSummary(rows,periodClasses):null,[ready,rows,periodClasses])
  const distribution=useMemo(()=>teacherDistribution(filtered),[filtered])
  const lastPage=Math.max(1,Math.ceil(filtered.length/20))
  const currentPage=Math.min(page,lastPage)
  const schedules=filtered.slice((currentPage-1)*20,currentPage*20)
  const meta={total:filtered.length,current_page:currentPage,last_page:lastPage}

  function resetForm() {
    setForm(initialForm)
    setFieldErrors({})
    setSubmitError(null)
    setEditingSchedule(null)
  }

  function openCreateModal() {
    if (!selectedPeriod || importing) return
    resetForm()
    setSuccessMessage('')

    setForm({
      ...initialForm,
      tahun_akademik_id: filters.academic_year_id,
      semester: filters.semester,
      kelas_id: filters.class_id,
      mapel_id: filters.subject_id,
      guru_id: filters.teacher_id && teachers.some(t=>String(t.id)===filters.teacher_id&&t.user?.account_status==='active')?filters.teacher_id:'',
      hari: filters.day,
    })

    setModalOpen(true)
  }

  function openEditModal(item) {
    resetForm()
    setSuccessMessage('')
    setEditingSchedule(item)

    const yearId = relationId(item, 'tahun_akademik_id', 'tahun_akademik')
    const classId = relationId(item, 'kelas_id', 'kelas')
    const subjectId = relationId(item, 'mapel_id', 'mapel')
    const teacherId = relationId(item, 'guru_id', 'guru')

    setForm({
      tahun_akademik_id: yearId === '-' ? '' : String(yearId),
      semester: item.tahun_akademik?.semester || item.semester || '',
      kelas_id: classId === '-' ? '' : String(classId),
      mapel_id: subjectId === '-' ? '' : String(subjectId),
      guru_id: teacherId === '-' ? '' : String(teacherId),
      hari: String(item.hari || 'SENIN').toUpperCase(),
      jam_mulai:
        displayTime(item.jam_mulai) === '-'
          ? ''
          : displayTime(item.jam_mulai),
      jam_selesai:
        displayTime(item.jam_selesai) === '-'
          ? ''
          : displayTime(item.jam_selesai),
    })

    setModalOpen(true)
  }

  function closeModal() {
    if (saving) return

    setModalOpen(false)
    resetForm()
  }

  function handleFormChange(event) {
    if (saving) return
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

  function setFormField(name, value) {
    handleFormChange({ target: { name, value } })
  }

  function handleFilterChange(name, value) {
    if (importing) return
    setPreview(null)
    if (name === 'academic_period') {
      if (!value) {
        setFilters((current) => ({
          ...current,
          academic_year_id: '',
          semester: '',
          class_id: '',
        }))
      } else {
        const [academicYearId, semester] = value.split('::')
        setFilters((current) => ({
          ...current,
          academic_year_id: academicYearId || '',
          semester: semester || '',
          class_id: '',
        }))
      }
    } else {
      setFilters((current) => ({
        ...current,
        [name]: value,
      }))
    }

    setPage(1)
    setSuccessMessage('')
  }

  function resetFilters() {
    if (importing) return
    setPreview(null)
    const active = years.filter(isActive)
    setFilters(active.length === 1 ? {...initialFilters, academic_year_id:String(active[0].id), semester:String(active[0].semester).toUpperCase()} : initialFilters)
    setPage(1)
    setSuccessMessage('')
  }

  function getExportFilters() {
    return {
      academic_year_id: filters.academic_year_id,
      semester: filters.semester,
      class_id: filters.class_id,
      subject_id: filters.subject_id,
      teacher_id: filters.teacher_id,
      day: filters.day,
    }
  }

  function closeExcel() {
    if (importing || exporting || downloadingTemplate) return
    setExcelOpen(false); setPreview(null); setSelectedExcel(null); setExcelError(''); setExcelValidationErrors([])
  }

  async function handleDownloadTemplate() {
    setDownloadingTemplate(true)
    setExcelError('')
    setExcelValidationErrors([])

    try {
      const blob = await scheduleApi.downloadTemplate({academic_year_id:filters.academic_year_id})
      downloadBlob(blob, 'template-jadwal-pelajaran.xlsx')
      setSuccessMessage('Template Excel berhasil diunduh.')
    } catch (error) {
      setExcelError(apiMessage(error, 'Template Excel gagal diunduh.'))
    } finally {
      setDownloadingTemplate(false)
    }
  }

  async function handleImportExcel(event, commit = false) {
    event.preventDefault()
    setExcelError('')
    setExcelValidationErrors([])
    setSuccessMessage('')

    if (!selectedPeriod) { setExcelError('Pilih periode akademik pada filter terlebih dahulu.'); return }
    if (!selectedExcel) {
      setExcelError('Pilih file Excel yang akan diimpor terlebih dahulu.')
      return
    }

    const extension = selectedExcel.name.split('.').pop()?.toLowerCase()

    if (!['xlsx', 'xls'].includes(extension)) {
      setExcelError('Format file harus .xlsx atau .xls.')
      return
    }

    if (importing || (commit && !preview?.token)) return
    setImporting(true)

    try {
      const response = await scheduleApi.importExcel(selectedExcel, {academic_year_id:filters.academic_year_id, mode:commit?'commit':'preview', token:commit?preview?.token:null})
      if (!commit) { setPreview(response.data); return }
      setPreview(null)
      setSuccessMessage(
        response?.message || 'Data jadwal berhasil diimpor.',
      )
      setSelectedExcel(null)

      if (fileInputRef.current) {
        fileInputRef.current.value = ''
      }

      setPage(1)
      await loadSchedules()
    } catch (error) {
      setPreview(null)
      const errors = extractValidationErrors(error)

      setExcelError(apiMessage(error, 'Import Excel gagal.'))
      setExcelValidationErrors(validationMessages(errors))
    } finally {
      setImporting(false)
    }
  }

  async function handleExportExcel(format = "data") {
    setExporting(true)
    setExcelError('')
    setExcelValidationErrors([])

    try {
      const params = format === 'data' ? getExportFilters() : {academic_year_id:filters.academic_year_id, format, teacher_id:format==='teacher'?filters.teacher_id:null, class_id:format==='class'?filters.class_id:null, include_inactive:includeInactive?1:0}
      const blob = await scheduleApi.exportExcel(params)
      downloadBlob(blob, format==='batch'?'jadwal-mengajar-guru.zip':format==='teacher'?'jadwal-guru-'+filters.teacher_id+'.xlsx':format==='class'?'jadwal-rombel-'+filters.class_id+'.xlsx':'jadwal-pelajaran.xlsx')
      setSuccessMessage('Data jadwal berhasil diekspor ke Excel.')
    } catch (error) {
      setExcelError(apiMessage(error, 'Export Excel gagal.'))
    } finally {
      setExporting(false)
    }
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setFieldErrors({})
    setSubmitError(null)

    const errors = {}

    if (saving) return
    if (!form.tahun_akademik_id) {
      errors.tahun_akademik_id = 'Tahun akademik wajib dipilih.'
    }

    if (!form.semester) {
      errors.semester = 'Semester wajib dipilih.'
    }

    if (!form.kelas_id) {
      errors.kelas_id = 'Kelas wajib dipilih.'
    }

    if (!form.mapel_id) {
      errors.mapel_id = 'Mata pelajaran wajib dipilih.'
    }

    if (!form.guru_id) {
      errors.guru_id = 'Guru wajib dipilih.'
    }


    if (!form.hari) {
      errors.hari = 'Hari wajib dipilih.'
    }

    if (!form.jam_mulai) {
      errors.jam_mulai = 'Jam mulai wajib diisi.'
    }

    if (!form.jam_selesai) {
      errors.jam_selesai = 'Jam selesai wajib diisi.'
    }

    if (
      form.jam_mulai &&
      form.jam_selesai &&
      form.jam_mulai >= form.jam_selesai
    ) {
      errors.jam_selesai =
        'Jam selesai harus lebih besar dari jam mulai.'
    }

    if (Object.keys(errors).length > 0) {
      setFieldErrors(errors)
      return
    }

    const payload = {
      tahun_akademik_id: Number(form.tahun_akademik_id),
      semester: form.semester,
      kelas_id: Number(form.kelas_id),
      mapel_id: Number(form.mapel_id),
      guru_id: Number(form.guru_id),
      hari: form.hari,
      jam_mulai: form.jam_mulai,
      jam_selesai: form.jam_selesai,
    }

    setSaving(true)

    try {
      if (editingSchedule) {
        await scheduleApi.update(editingSchedule.id, {...payload, version:editingSchedule.version})
        setSuccessMessage('Jadwal berhasil diperbarui.')
      } else {
        await scheduleApi.create(payload)
        setSuccessMessage('Jadwal berhasil ditambahkan.')
      }

      setModalOpen(false)
      resetForm()
      await loadSchedules()
    } catch (error) {
      const responseErrors =
        extractValidationErrors(error)

      setFieldErrors(responseErrors)
      setSubmitError(error)
    } finally {
      setSaving(false)
    }
  }

  async function handleDelete() {
    if (!scheduleToDelete) return

    const target = scheduleToDelete

    setDeletingId(target.id)
    setListError(null)

    try {
      await scheduleApi.delete(target.id, target.version)

      setSuccessMessage('Jadwal berhasil dihapus.')
      setScheduleToDelete(null)

      setPage(Math.max(1,currentPage-(schedules.length===1?1:0)))
      await loadSchedules()
    } catch (error) {
      setListError(error)
      setScheduleToDelete(null)
    } finally {
      setDeletingId(null)
    }
  }

  return (
    <div className="admin-schedule">
      <PageHeader
        eyebrow="KEGIATAN AKADEMIK"
        title="Manajemen Jadwal"
        description="Susun jadwal, pantau distribusi mengajar, dan siapkan dokumen akademik."
        actions={
          canManage ? (
            <div className="sch-header-actions"><Button type="button" variant="secondary" onClick={()=>setExcelOpen(true)} disabled={importing}>Kelola Excel</Button><Button type="button" variant="secondary" disabled={!ready || semester!=='GANJIL' || !rows.length || importing || rows.length>1000} onClick={()=>setCopyOpen(true)}>Salin Semester</Button><Button
              type="button"
              onClick={openCreateModal}
              disabled={!selectedPeriod || optionsLoading || Boolean(optionsError) || importing}
            >
              + Tambah Jadwal
            </Button></div>
          ) : null
        }
      />

      {successMessage && (
        <div className="admin-schedule__success" role="status">
          <span aria-hidden="true">✓</span>
          {successMessage}
          <button
            type="button"
            aria-label="Tutup pesan"
            onClick={() => setSuccessMessage('')}
          >
            ×
          </button>
        </div>
      )}

      {optionsError && (
        <ErrorState
          message={apiMessage(
            optionsError,
            'Pilihan data jadwal gagal dimuat.',
          )}
          onRetry={loadOptions}
        />
      )}

      <section className="sch-period" aria-label="Konteks akademik">
        <div><span className="admin-schedule__eyebrow">PERIODE AKADEMIK</span><h2>{selectedPeriod?`${yearLabel(selectedPeriod)} · ${semester==='GANJIL'?'Ganjil':'Genap'}`:'Pilih periode akademik'}</h2><p>Ringkasan seluruh periode, tidak berubah mengikuti filter guru atau rombel.</p></div>
        {selectedPeriod&&<span className="sch-status">{isActive(selectedPeriod)?'Periode aktif':'Periode tidak aktif'}</span>}
      </section>
      <section className="sch-summary" aria-label="Ringkasan jadwal">
        {[["Total jadwal","total","Entri pembelajaran"],["Guru terjadwal","teachers","Guru unik"],["Rombel terjadwal","classes","Rombel unik"],["Mata pelajaran","subjects","Mapel unik"],["Rombel kosong","empty","Belum ada jadwal"],["Jadwal konflik","conflicts","Entri terlibat bentrok"]].map(([label,key,hint])=><article key={key} className="sch-stat"><span className="sch-stat-icon" aria-hidden="true"><StatIcon kind={key}/></span><span>{label}</span><strong>{summary?.[key]??'—'}</strong><small>{hint}</small></article>)}
      </section>
      <Card>
        <div className="admin-schedule__section-heading">
          <div>
            <span className="admin-schedule__eyebrow">
              PENCARIAN DATA
            </span>
            <h2>Filter Jadwal</h2>
            <p>
              Saring jadwal berdasarkan konteks akademik yang dibutuhkan.
            </p>
          </div>
        </div>

        <fieldset disabled={importing || optionsLoading || Boolean(optionsError)} className="admin-schedule__filters sch-fieldset">
          <Select
            label="Tahun Akademik"
            name="academic_period"
            value={
              filters.academic_year_id && filters.semester
                ? `${filters.academic_year_id}::${filters.semester}`
                : ''
            }
            placeholder="Pilih periode"
            onChange={(event) =>
              handleFilterChange('academic_period', event.target.value)
            }
            options={years.map((year) => {
              const semesterValue = String(
                year.semester ?? year.semester_name ?? year.nama_semester ?? '',
              ).toUpperCase()

              return {
                value: `${year.id}::${semesterValue}`,
                label: `${yearLabel(year)} • ${semesterValue}${isActive(year)?" · Aktif":""}`,
              }
            })}
          />

          <Select
            label="Kelas"
            key={`filter-class-${period}`}
            name="class_id"
            value={filters.class_id}
            placeholder="Semua kelas"
            onChange={(event) =>
              handleFilterChange('class_id', event.target.value)
            }
            options={classes
              .filter((item) => {
                if (!filters.academic_year_id) return false
                const yearId = relationId(
                  item,
                  'tahun_akademik_id',
                  'tahun_akademik',
                )
                return String(yearId) === String(filters.academic_year_id)
              })
              .map((item) => ({
                value: String(item.id),
                label: classLabel(item),
              }))}
          />

          <Select
            label="Mata Pelajaran"
            name="subject_id"
            value={filters.subject_id}
            placeholder="Semua mata pelajaran"
            onChange={(event) =>
              handleFilterChange('subject_id', event.target.value)
            }
            options={subjects.map((item) => ({
                value: String(item.id),
                label: subjectLabel(item),
              }))}
          />

          <Select
            label="Guru"
            name="teacher_id"
            value={filters.teacher_id}
            placeholder="Semua guru"
            onChange={(event) =>
              handleFilterChange('teacher_id', event.target.value)
            }
            options={teachers.map((item) => ({
                value: String(item.id),
                label: teacherLabel(item), description: item.nip ? `NIP ${item.nip}` : "",
              }))}
          />

          <Select
            label="Hari"
            name="day"
            value={filters.day}
            placeholder="Semua hari"
            onChange={(event) =>
              handleFilterChange('day', event.target.value)
            }
            options={DAYS}
          />
        </fieldset>

        <div className="admin-schedule__filter-actions">
          <Button type="button" onClick={resetFilters} disabled={importing}>
            Reset Filter
          </Button>

          <Button
            type="button"
            onClick={()=>{loadOptions();loadSchedules()}}
            disabled={loading || importing || optionsLoading || !selectedPeriod}
          >
            {loading ? 'Memuat...' : 'Muat Ulang'}
          </Button>
        </div>
      </Card>

      <div className="sch-excel-overlay" hidden={!excelOpen}><Modal open={excelOpen} title="Kelola Excel" description="Import data dan distribusi jadwal pada konteks yang dipilih." size="large" onClose={closeExcel} footer={<Button variant="secondary" disabled={importing||exporting||downloadingTemplate} onClick={closeExcel}>Tutup Excel</Button>}><Card className="sch-excel-card">
        <div className="sch-excel-context"><strong>{selectedPeriod?`${yearLabel(selectedPeriod)} · ${semester}`:'Pilih periode terlebih dahulu'}</strong><br/>Guru: {filters.teacher_id?teacherLabel(teachers.find(t=>String(t.id)===filters.teacher_id)):'Semua guru'} · Rombel: {filters.class_id?classLabel(classes.find(c=>String(c.id)===filters.class_id)):'Semua rombel'}</div>
        <div className="admin-schedule__excel-heading">
          <div>
            <span className="admin-schedule__eyebrow">IMPORT &amp; EXPORT</span>
            <h2>Pengelolaan Jadwal melalui Excel</h2>
            <p>Unduh template, ekspor jadwal, atau impor data menggunakan file Excel.</p>
          </div>
        </div>

        <div className="admin-schedule__excel-layout">
          <div className="admin-schedule__excel-actions">
            <div className="sch-excel-label">TEMPLATE &amp; DATA</div>
            <Button type="button" variant="secondary" onClick={handleDownloadTemplate}
              loading={downloadingTemplate}
              disabled={!selectedPeriod || downloadingTemplate || importing || exporting}>
              Download Template
            </Button>
            <Button type="button" variant="secondary" onClick={() => handleExportExcel()}
              loading={exporting}
              disabled={!ready || exporting || importing || downloadingTemplate}>
              Export Data Excel
            </Button>
            <div className="sch-excel-label">DISTRIBUSI JADWAL</div>
            <Button type="button" onClick={() => handleExportExcel('teacher')} disabled={exporting || importing || !filters.academic_year_id || !filters.teacher_id}>Jadwal Guru (.xlsx)</Button>
            <Button type="button" onClick={() => handleExportExcel('class')} disabled={exporting || importing || !filters.academic_year_id || !filters.class_id}>Jadwal Rombel (.xlsx)</Button>
            <Button type="button" onClick={() => handleExportExcel('batch')} disabled={exporting || importing || !filters.academic_year_id}>Seluruh Guru (.zip)</Button>
            <label className="admin-schedule__batch-option"><input type="checkbox" checked={includeInactive} onChange={e=>setIncludeInactive(e.target.checked)} disabled={exporting || importing}/> Sertakan guru nonaktif dalam ZIP (untuk audit)</label>
            <p className="admin-schedule__excel-hint">Pilih periode dan guru/rombel di filter. Dokumen distribusi berisi seluruh jadwal pilihan tersebut, bukan hanya filter hari atau mapel.</p>
          </div>

          <form className="admin-schedule__import-form" onSubmit={event => handleImportExcel(event)}>
            <div className="admin-schedule__file-control">
              <label htmlFor="schedule-excel-file" className="admin-schedule__file-label">
                File Excel Jadwal
              </label>
              <input ref={fileInputRef} id="schedule-excel-file"
                className="admin-schedule__file-input" type="file" accept=".xlsx,.xls"
                onChange={(event) => {
                  setPreview(null)
                  setSelectedExcel(event.target.files?.[0] || null)
                  setExcelError('')
                  setExcelValidationErrors([])
                }}
                disabled={importing || exporting || downloadingTemplate}
              />
              {selectedExcel && (
                <p className="admin-schedule__selected-file">
                  File dipilih: <strong>{selectedExcel.name}</strong>
                </p>
              )}
            </div>
            <div className="admin-schedule__import-actions">
              <Button type="submit" loading={importing}
                disabled={!selectedExcel || !filters.academic_year_id || importing || exporting || downloadingTemplate}>
                Preview Import
              </Button>
            </div>
          </form>
        </div>

        {preview && <section className="admin-schedule__preview" aria-label="Preview import jadwal">
          <h3>{preview.count} jadwal siap ditambahkan</h3>
          <p>Belum tersimpan. Periksa data lalu konfirmasi dalam 5 menit. Jadwal lama tidak ditimpa.</p>
          <div className="admin-schedule__preview-scroll" tabIndex={0} role="region" aria-label="Tabel preview import"><table><thead><tr>{['Baris','Guru','Rombel','Mapel','Hari','Jam','Status'].map(h=><th key={h}>{h}</th>)}</tr></thead><tbody>{preview.rows.map(r=><tr key={r.row_number}><td>{r.row_number}</td><td>{r.teacher}</td><td>{r.class}</td><td>{r.subject}</td><td>{r.data.hari}</td><td>{displayTime(r.data.jam_mulai)}–{displayTime(r.data.jam_selesai)}</td><td>{r.account_state==='pending'?'Menunggu aktivasi':'Siap'}</td></tr>)}</tbody></table></div>
          <div className="admin-schedule__excel-actions"><Button type="button" disabled={importing} onClick={()=>setPreview(null)}>Batal</Button><Button type="button" loading={importing} disabled={importing} onClick={event=>handleImportExcel(event,true)}>Konfirmasi Tambah Jadwal</Button></div>
        </section>}
        {excelError && (
          <div className="admin-schedule__import-result" role="alert">
            <div className="admin-schedule__form-error">
              <ErrorState message={excelError} />

              {excelValidationErrors.length > 0 && (
                <div className="admin-schedule__error-details">
                  <h3>Rincian Kesalahan</h3>
                  <ul>
                    {excelValidationErrors.map((item, index) => (
                      <li key={`${index}-${item.field}-${item.message}`}>
                        <strong>{item.field}:</strong> {item.message}
                      </li>
                    ))}
                  </ul>
                </div>
              )}
            </div>
          </div>
        )}
      </Card></Modal></div>
      {copyOpen && selectedPeriod && <ScheduleContinuationDialog source={selectedPeriod} years={years} classes={classes} rows={rows} onClose={()=>setCopyOpen(false)} onDone={count=>{setCopyOpen(false);setSuccessMessage(`${count} jadwal berhasil disalin ke Genap. Periode aktif tidak berubah.`);loadSchedules()}}/>}
      <Card className="sch-workspace">
        <div className="sch-tabs" aria-label="Tampilan jadwal">{[['list','Daftar Jadwal'],['week','Jadwal Mingguan'],['teachers','Distribusi Guru']].map(([id,label])=><button type="button" key={id} aria-pressed={view===id} onClick={()=>setView(id)}>{label}</button>)}</div>
        <div className="admin-schedule__list-heading">
          <div>
            <span className="admin-schedule__eyebrow">
              DATA PEMBELAJARAN
            </span>
            <h2>{view==='list'?'Daftar Jadwal':view==='week'?'Jadwal Mingguan':'Distribusi Guru'}</h2>
            <p>
              {loading
                ? 'Memuat data jadwal...'
                : ready ? `${meta.total} jadwal sesuai filter` : 'Pilih periode atau muat ulang data.'}
            </p>
          </div>
        </div>

        {listError && (
          <ErrorState
            message={apiMessage(
              listError,
              'Data jadwal gagal dimuat.',
            )}
            onRetry={loadSchedules}
          />
        )}

        {loading && (
          <LoadingState message="Memuat jadwal pelajaran..." />
        )}

        {ready && filtered.length === 0 && (
          <EmptyState
            title="Belum ada jadwal"
            message="Tidak ada jadwal yang cocok dengan filter saat ini. Coba ubah filter atau tambahkan jadwal baru."
          />
        )}

        {ready && filtered.length>0 && view==='week' && <div className="sch-week" aria-label="Jadwal dikelompokkan per hari">{DAYS.map(day=>{const entries=filtered.filter(r=>r.hari===day.value);return <section className="sch-day" key={day.value}><header><strong>{day.label}</strong><small>{entries.length} jadwal</small></header>{entries.length?entries.map(item=><article className="sch-lesson" key={item.id}><span>{displayTime(item.jam_mulai)}–{displayTime(item.jam_selesai)}</span><strong>{item.mapel.nama}</strong><small>{classLabel(item.kelas)}</small><small>{teacherLabel(item.guru)}</small></article>):<p>Belum ada jadwal</p>}</section>})}</div>}
        {ready && filtered.length>0 && view==='teachers' && <div className="sch-distribution">{distribution.map(g=><article key={g.id}><div><strong>{g.name}</strong><small>{g.rows} jadwal · {g.classes} rombel · {g.subjects} mapel</small></div><strong>{Number(g.minutes.toFixed(2))} menit/minggu</strong><Button variant="secondary" disabled={importing} onClick={()=>{handleFilterChange('teacher_id',g.id);setView('list')}}>Lihat jadwal</Button></article>)}</div>}
        {ready && schedules.length > 0 && view==='list' && (
          <>
            <div className="sch-mobile-list">{schedules.map(item=><article key={item.id}><div className="sch-mobile-time"><strong>{DAYS.find(d=>d.value===item.hari)?.label}</strong><span>{displayTime(item.jam_mulai)}–{displayTime(item.jam_selesai)}</span></div><h3>{item.mapel.nama}</h3><p>{classLabel(item.kelas)} · {teacherLabel(item.guru)}</p><div className="admin-schedule__row-actions"><Button variant="secondary" disabled={deletingId!==null||importing} onClick={()=>openEditModal(item)}>Edit</Button><Button variant="danger" disabled={deletingId!==null||importing} onClick={()=>setScheduleToDelete(item)}>Hapus</Button></div></article>)}</div>
            <div className="admin-schedule__table-wrapper" tabIndex={0} role="region" aria-label="Tabel jadwal">
              <Table
                columns={columns}
              data={schedules}
              rowKey="id"
              renderCell={(item, column) => {
                switch (column.key) {
                  case 'day':
                    return (
                      <span className="admin-schedule__day">
                        {DAYS.find((day) => day.value === item.hari)
                          ?.label || item.hari || '-'}
                      </span>
                    )

                  case 'time':
                    return (
                      <span className="admin-schedule__time">
                        {displayTime(item.jam_mulai)} –{' '}
                        {displayTime(item.jam_selesai)}
                      </span>
                    )

                  case 'class': {
                    const classItem = item.kelas || item.class

                    if (!classItem) {
                      return `Kelas #${relationId(item, 'kelas_id', 'kelas')}`
                    }

                    return (
                      <div className="admin-schedule__class">
                        <span className="admin-schedule__class-name">
                          {classItem.nama || classItem.nama_kelas || 'Kelas'}
                        </span>

                        {classItem.tingkat !== null &&
                          classItem.tingkat !== undefined &&
                          classItem.tingkat !== '' && (
                            <span className="admin-schedule__class-level">
                              Tingkat {classItem.tingkat}
                            </span>
                          )}
                      </div>
                    )
                  }

                  case 'subject': {
                    const subject = item.mapel || item.subject

                    return subject
                      ? subjectLabel(subject)
                      : `Mapel #${relationId(item, 'mapel_id', 'mapel')}`
                  }

                  case 'teacher':
                    return teacherLabel(item.guru || item.teacher)

                  case 'semester': {
                    const semester =
                      item.tahun_akademik?.semester ||
                      item.tahunAkademik?.semester ||
                      '-'

                    return (
                      <span className="admin-schedule__semester">
                        {semester === 'GENAP'
                          ? 'Genap'
                          : semester === 'GANJIL'
                            ? 'Ganjil'
                            : semester}
                      </span>
                    )
                  }

                  case 'actions':
                    return canManage ? (
                      <div className="admin-schedule__row-actions">
                        <Button
                          type="button"
                          variant="secondary"
                          onClick={() => openEditModal(item)}
                          disabled={deletingId !== null || importing}
                        >
                          Edit
                        </Button>

                        <Button
                          type="button"
                          disabled={deletingId !== null || importing}
                          loading={
                            String(deletingId) === String(item.id)
                          }
                          variant="danger"
                          onClick={() => setScheduleToDelete(item)}
                        >
                          Hapus
                        </Button>
                      </div>
                    ) : (
                      '-'
                    )

                  default:
                    return '-'
                }
                }}
              />
            </div>

            <div className="admin-schedule__pagination">
              <span>
                Halaman {meta.current_page || page} dari{' '}
                {meta.last_page || 1}
              </span>

              <div>
                <Button
                  type="button"
                  disabled={currentPage <= 1 || loading}
                  onClick={() =>
                    setPage(Math.max(1,currentPage-1))
                  }
                >
                  Sebelumnya
                </Button>

                <Button
                  type="button"
                  disabled={
                    currentPage >= (meta.last_page || 1) || loading
                  }
                  onClick={() => setPage(currentPage+1)}
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
        title={editingSchedule ? 'Edit Jadwal' : 'Tambah Jadwal'}
        description="Lengkapi informasi akademik dan waktu pembelajaran."
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
              form="schedule-form"
              loading={saving}
            >
              {editingSchedule ? 'Simpan Perubahan' : 'Simpan Jadwal'}
            </Button>
          </>
        }
      >
        {submitError && (
          <div className="admin-schedule__form-error">
            <ErrorState
              message={apiMessage(
                submitError,
                'Jadwal gagal disimpan. Periksa data dan kemungkinan bentrok jadwal.',
              )}
            />
          </div>
        )}

        {optionsLoading && (
          <LoadingState message="Memuat pilihan data..." />
        )}

        {!optionsLoading && !optionsError && (
          <form
            id="schedule-form"
            className="admin-schedule__form"
            onSubmit={handleSubmit}
          >
            <Select
              label="Tahun Akademik"
              disabled={saving}
              name="academic_period"
              value={
                form.tahun_akademik_id && form.semester
                  ? `${form.tahun_akademik_id}::${form.semester}`
                  : ''
              }
              placeholder="Pilih tahun akademik dan semester"
              onChange={(event) => {
                if(saving)return
                const value = event.target.value
                const [yearId, semester] = value ? value.split('::') : ['', '']
                setForm((current) => ({
                  ...current,
                  tahun_akademik_id: yearId,
                  semester,
                  kelas_id: '',
                }))
                setFieldErrors((current) => ({
                  ...current,
                  tahun_akademik_id: '',
                  semester: '',
                  kelas_id: '',
                }))
              }}
              options={years.map((year) => {
                const semesterValue = String(
                  year.semester ?? year.semester_name ?? year.nama_semester ?? '',
                ).toUpperCase()

                return {
                  value: `${year.id}::${semesterValue}`,
                  label: `${yearLabel(year)} • ${semesterValue}${isActive(year)?" · Aktif":""}`,
                }
              })}
              error={
                fieldError(fieldErrors, 'tahun_akademik_id') ||
                fieldError(fieldErrors, 'semester')
              }
            />

            <Select
              label="Kelas"
              key={`form-class-${form.tahun_akademik_id}`}
              disabled={saving || !form.tahun_akademik_id}
              name="kelas_id"
              value={form.kelas_id}
              placeholder="Pilih kelas"
              onChange={(event) => setFormField('kelas_id', event.target.value)}
              options={classes
                  .filter(
                    (item) => {
                      if (!form.tahun_akademik_id) return false
                      const yearId = relationId(
                        item,
                        'tahun_akademik_id',
                        'tahun_akademik',
                      )
                      return String(yearId) === String(form.tahun_akademik_id)
                    },
                  )
                  .map((item) => ({
                    value: String(item.id),
                    label: classLabel(item),
                  }))}
              error={fieldError(fieldErrors, 'kelas_id')}
            />

            <Select
              label="Mata Pelajaran"
              disabled={saving}
              name="mapel_id"
              value={form.mapel_id}
              placeholder="Pilih mata pelajaran"
              onChange={(event) => setFormField('mapel_id', event.target.value)}
              options={subjects.map((item) => ({
                  value: String(item.id),
                  label: subjectLabel(item),
                }))}
              error={fieldError(fieldErrors, 'mapel_id')}
            />

            <Select
              label="Guru Pengajar"
              disabled={saving}
              name="guru_id"
              value={form.guru_id}
              placeholder="Pilih guru"
              onChange={(event) => setFormField('guru_id', event.target.value)}
              options={teachers.filter(item=>item.user?.account_status === "active").map((item) => ({
                  value: String(item.id),
                  label: teacherLabel(item)+(item.user?.requires_activation?" · Menunggu aktivasi":""), description:item.nip?`NIP ${item.nip}`:"",
                }))}
              error={fieldError(fieldErrors, 'guru_id')}
            />

            <Select
              label="Hari"
              disabled={saving}
              name="hari"
              value={form.hari}
              placeholder="Pilih hari"
              onChange={(event) => setFormField('hari', event.target.value)}
              options={DAYS}
              error={fieldError(fieldErrors, 'hari')}
            />

            <Input
              label="Jam Mulai"
              id="schedule-start"
              disabled={saving}
              name="jam_mulai"
              type="time"
              value={form.jam_mulai}
              onChange={handleFormChange}
              error={fieldError(fieldErrors, 'jam_mulai')}
            />

            <Input
              label="Jam Selesai"
              id="schedule-end"
              disabled={saving}
              name="jam_selesai"
              type="time"
              value={form.jam_selesai}
              onChange={handleFormChange}
              error={fieldError(fieldErrors, 'jam_selesai')}
            />
          </form>
        )}
      </Modal>

      <Modal
        open={Boolean(scheduleToDelete)}
        title="Konfirmasi Hapus Jadwal"
        description="Jadwal yang dihapus tidak dapat ditampilkan lagi."
        onClose={() => {
          if (deletingId === null) setScheduleToDelete(null)
        }}
        size="small"
        footer={
          <>
            <Button
              type="button"
              disabled={deletingId !== null || importing}
              onClick={() => setScheduleToDelete(null)}
            >
              Batal
            </Button>

            <Button
              type="button"
              loading={deletingId !== null}
              onClick={handleDelete}
            >
              Ya, Hapus
            </Button>
          </>
        }
      >
        {scheduleToDelete && (
          <p>
            Yakin ingin menghapus jadwal{' '}
            <strong>
              {scheduleToDelete.mapel?.nama ||
                scheduleToDelete.subject?.nama ||
                `Mapel #${relationId(
                  scheduleToDelete,
                  'mapel_id',
                  'mapel',
                )}`}
            </strong>{' '}
            pada hari <strong>{scheduleToDelete.hari || '-'}</strong>,
            pukul{' '}
            <strong>
              {displayTime(scheduleToDelete.jam_mulai)} –{' '}
              {displayTime(scheduleToDelete.jam_selesai)}
            </strong>
            ?
          </p>
        )}
      </Modal>
    </div>
  )
}
