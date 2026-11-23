import { useCallback, useEffect, useMemo, useState, useRef } from 'react'
import { useAuth } from '../../../context/useAuth'
import { collectPages, teacherId, sameId } from '../../../utils/teachingScope'
import { loadAssignmentWorkspace, ownComponents, deadlineToUtc, deadlineToWib } from './assignmentScope'
import ToastNotification from '../../../components/ui/ToastNotification'
import ConfirmDialog from '../../../components/ui/ConfirmDialog'
import { assignmentApi } from '../../../services/assignmentApi'
import { scheduleApi } from '../../../services/scheduleApi'
import { academicYearApi } from '../../../services/academicYearApi'
import '../styles/GuruAssignmentsPage.css'
import { gradeApi } from '../../../services/gradeApi'
import SearchableSelect from '../../../components/ui/SearchableSelect'
import FileDropzone from '../../../components/ui/FileDropzone'
import FileTypeIcon from '../../../components/ui/FileTypeIcon'
import WorkspaceModal from '../../../components/ui/WorkspaceModal'
import GuruAssignmentSubmissions from './GuruAssignmentSubmissions'

const MAX_FILE_SIZE = 10 * 1024 * 1024
const ACCEPTED_FILE_TYPES =
  '.jpg,.jpeg,.png,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx'

const EMPTY_FORM = {
  jadwal_pelajaran_id: '',
  komponen_nilai_id: '',
  judul: '',
  deskripsi: '',
  deadline: '',
  files: [],
  remove_attachment_ids: [],
  remove_file: false,
}

function getMessage(error) {
  const errors = error?.payload?.errors || error?.errors

  if (errors && typeof errors === 'object') {
    const first = Object.values(errors).flat()[0]
    if (first) return first
  }

  return (
    error?.payload?.message ||
    error?.message ||
    'Terjadi kesalahan. Silakan coba kembali.'
  )
}

function scheduleDetails(schedule = {}) {
  const kelas =
    schedule?.kelas?.nama ||
    schedule?.kelas?.nama_kelas ||
    'Kelas'

  const mapel =
    schedule?.mapel?.nama ||
    schedule?.mapel?.nama_mapel ||
    'Mata pelajaran'

  const tingkat = schedule?.kelas?.tingkat

  const time = [
    schedule?.jam_mulai,
    schedule?.jam_selesai,
  ]
    .filter(Boolean)
    .map((value) => String(value).slice(0, 5))
    .join('–')

  return [
    mapel,
    tingkat != null ? `Tingkat ${tingkat}` : '',
    kelas,
    schedule?.hari,
    time,
  ]
    .filter(Boolean)
    .join(' • ')
}

function getSubjectName(item, schedules) {
  const linkedSchedule = item?.jadwal_pelajaran

  if (linkedSchedule?.mapel?.nama) {
    return linkedSchedule.mapel.nama
  }

  if (linkedSchedule?.mapel?.nama_mapel) {
    return linkedSchedule.mapel.nama_mapel
  }

  const schedule = schedules.find(
    (entry) =>
      String(entry.id) ===
      String(
        item?.jadwal_pelajaran_id ??
          linkedSchedule?.id ??
          '',
      ),
  )

  return (
    schedule?.mapel?.nama ||
    schedule?.mapel?.nama_mapel ||
    'Mata Pelajaran Lainnya'
  )
}

function getAssignmentAttachments(item) {
  if (Array.isArray(item?.files) && item.files.length) return item.files
  if (item?.file?.name) {
    return [{
      id: null,
      name: item.file.name,
      mime_type: item.file.mime_type || null,
      size: item.file.size || null,
      legacy: true,
    }]
  }
  return []
}

function formatFileSize(bytes) {
  if (bytes == null || Number.isNaN(Number(bytes))) return ''
  const size = Number(bytes)
  if (size < 1024) return `${size} B`
  if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} KB`
  return `${(size / (1024 * 1024)).toFixed(1)} MB`
}

function localDateTime(value) {
  if (!value) return 'Belum ditentukan'

  const date = new Date(value)

  if (Number.isNaN(date.getTime())) return '—'

  return (
    new Intl.DateTimeFormat('id-ID', {
      dateStyle: 'medium',
      timeStyle: 'short',
      timeZone: 'Asia/Jakarta',
    }).format(date) + ' WIB'
  )
}

function assignmentScheduleLabel(item, schedules) {
  const linked = item?.jadwal_pelajaran

  if (linked) return scheduleDetails(linked)

  const schedule = schedules.find(
    (entry) =>
      String(entry.id) ===
      String(item?.jadwal_pelajaran_id),
  )

  return schedule
    ? scheduleDetails(schedule)
    : `Jadwal #${item?.jadwal_pelajaran_id ?? '—'}`
}

function isOverdue(item, now = Date.now()) {
  if (!item?.deadline) return false

  const deadline = new Date(item.deadline).getTime()

  return !Number.isNaN(deadline) && deadline < now
}

export default function GuruAssignmentsPage() {
  const { user } = useAuth()
  const guruId = teacherId(user)
  const generation = useRef(0)
  const [now,setNow] = useState(Date.now)
  useEffect(()=>{const timer=setInterval(()=>setNow(Date.now()),1000);return()=>clearInterval(timer)},[])
  const [assignments, setAssignments] = useState([])
  const [schedules, setSchedules] = useState([])
  const [activeAcademicYear, setActiveAcademicYear] =
    useState(null)

  const [search, setSearch] = useState('')
  const [scheduleFilter, setScheduleFilter] = useState('')
  const [statusFilter, setStatusFilter] = useState('')
  const [view, setView] = useState('grid')
  const [expandedFiles, setExpandedFiles] = useState([])
  const [gradeChoices, setGradeChoices] = useState([])
  const [gradeLoading, setGradeLoading] = useState(false)
  const [gradeError, setGradeError] = useState('')

  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [downloading, setDownloading] = useState(null)
  const [deleting, setDeleting] = useState(false)

  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const toastNotice=useMemo(()=>notice?{type:'success',title:notice}:null,[notice])
  const clearNotice = useCallback(()=>setNotice(''),[])

  const [modalOpen, setModalOpen] = useState(false)
  const [editing, setEditing] = useState(null)
  const [deleteTarget, setDeleteTarget] = useState(null)
  const [submissionTarget, setSubmissionTarget] = useState(null)
  const [form, setForm] = useState({ ...EMPTY_FORM })

  const loadData = useCallback(async () => {
    const requestId = ++generation.current
    setLoading(true)
    setError('')
    setAssignments([])
    setSchedules([])
    setActiveAcademicYear(null)

    try {
      const academicYears = await collectPages(page => academicYearApi.getAll({is_active:1,per_page:100,page}))
      if (requestId !== generation.current) return

      const activeYears = academicYears.filter(
        (year) =>
          year.is_active === true ||
          year.is_active === 1 ||
          year.is_active === '1',
      )

      if (activeYears.length > 1) throw new Error('Lebih dari satu periode akademik aktif. Hubungi TU.')
      const activeYear = activeYears[0]
      if (!activeYear) {
        setActiveAcademicYear(null)
        setAssignments([])
        setSchedules([])
        setSearch('')
        setScheduleFilter('')
        setStatusFilter('')
        return
      }

      const data = await loadAssignmentWorkspace(assignmentApi,scheduleApi,guruId,activeYear.id)
      if (requestId !== generation.current) return
      setActiveAcademicYear(activeYear)
      setAssignments(data.assignments)
      setSchedules(data.schedules)
      setScheduleFilter(old=>data.schedules.some(s=>sameId(s.id,old))?old:'')
    } catch (err) {
      if (requestId === generation.current) setError(getMessage(err))
    } finally {
      if (requestId === generation.current) setLoading(false)
    }
  }, [guruId])

  useEffect(() => {
    setModalOpen(false)
    setDeleteTarget(null)
    setSubmissionTarget(null)
    loadData()
    return ()=>{generation.current++}
  }, [loadData])

  const filtered = useMemo(() => {
    const query = search
      .trim()
      .toLocaleLowerCase('id-ID')

    return assignments
      .filter((item) => {
        const scheduleId = String(
          item?.jadwal_pelajaran_id ??
            item?.jadwal_pelajaran?.id ??
            '',
        )

        const matchesSchedule =
          !scheduleFilter || scheduleId === scheduleFilter

        const text = [
          item?.judul,
          item?.deskripsi,
          getSubjectName(item, schedules),
          assignmentScheduleLabel(item, schedules),
          ...getAssignmentAttachments(item).map((attachment) => attachment.name),
        ]
          .filter(Boolean)
          .join(' ')
          .toLocaleLowerCase('id-ID')

        return (
          matchesSchedule &&
          (!statusFilter || (statusFilter === 'overdue' ? isOverdue(item, now) : item.deadline && Number.isFinite(new Date(item.deadline).getTime()) && !isOverdue(item, now))) &&
          (!query || text.includes(query))
        )
      })
      .sort(
        (a, b) =>
          new Date(a?.deadline || 0) -
          new Date(b?.deadline || 0),
      )
  }, [assignments, schedules, search, scheduleFilter, statusFilter, now])

  const summary = useMemo(() => {

    return {
      total: assignments.length,
      upcoming: assignments.filter(
        (item) =>
          item?.deadline &&
          new Date(item.deadline).getTime() >= now,
      ).length,
      overdue: assignments.filter(item=>isOverdue(item,now)).length,
      withAttachment: assignments.filter(
        (item) => getAssignmentAttachments(item).length > 0,
      ).length,
    }
  }, [assignments, now])

  useEffect(() => {
    let cancelled = false
    setGradeChoices([])
    setGradeError('')
    if (!modalOpen || !form.jadwal_pelajaran_id) { setGradeLoading(false); return }
    setGradeLoading(true)
    collectPages(page=>gradeApi.getComponents({ schedule_id: form.jadwal_pelajaran_id, academic_year_id: activeAcademicYear?.id, per_page: 100, page }))
      .then(rows => { if (!cancelled) setGradeChoices(ownComponents(rows,form.jadwal_pelajaran_id)) })
      .catch(err => { if (!cancelled) setGradeError(getMessage(err)) })
      .finally(() => { if (!cancelled) setGradeLoading(false) })
    return () => { cancelled = true }
  }, [modalOpen, form.jadwal_pelajaran_id, activeAcademicYear?.id])

  function chooseSchedule(value) {
    setGradeChoices([])
    setGradeLoading(Boolean(value))
    setForm(current => ({ ...current, jadwal_pelajaran_id: value, komponen_nilai_id: current.jadwal_pelajaran_id === value ? current.komponen_nilai_id : '' }))
  }

  function addFiles(files) {
    const invalid = files.find(file => !ACCEPTED_FILE_TYPES.split(',').includes('.' + file.name.split('.').pop().toLowerCase()) || file.size > MAX_FILE_SIZE)
    if (invalid) { setError(`File ${invalid.name} tidak didukung atau melebihi 10 MB.`); return }
    const next = [...form.files]
    for (const file of files) if (!next.some(f => f.name === file.name && f.size === file.size && f.lastModified === file.lastModified)) next.push(file)
    if (next.length + retainedCount > 10) { setError('Maksimal 10 lampiran termasuk file yang dipertahankan.'); return }
    setError('')
    setForm(current => ({ ...current, files: next }))
  }

  function resetFilters() {
    setSearch('')
    setScheduleFilter('')
    setStatusFilter('')
  }

  function openCreate() {
    setEditing(null)
    setForm({ ...EMPTY_FORM })
    setError('')
    setNotice('')
    setModalOpen(true)
  }

  function openEdit(item) {
    setEditing(item)

    setForm({
      jadwal_pelajaran_id: String(
        item?.jadwal_pelajaran_id ?? item?.jadwal_pelajaran?.id ?? '',
      ),
      komponen_nilai_id:
        item?.komponen_nilai_id == null
          ? ''
          : String(item.komponen_nilai_id),
      judul: item?.judul || '',
      deskripsi: item?.deskripsi || '',
      deadline: deadlineToWib(item?.deadline),
      files: [],
      remove_attachment_ids: [],
      remove_file: false,
    })

    setError('')
    setNotice('')
    setModalOpen(true)
  }

  function updateField(event) {
    const { name, value, checked, type } = event.target

    if (name === 'remove_attachment_ids') {
      const id = Number(value)
      setForm((current) => ({
        ...current,
        remove_attachment_ids: checked
          ? [...new Set([...current.remove_attachment_ids, id])]
          : current.remove_attachment_ids.filter((currentId) => currentId !== id),
      }))
      return
    }

    setForm((current) => ({
      ...current,
      [name]: type === 'checkbox' ? checked : value,
    }))
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setError('')
    setNotice('')

    if (
      !form.jadwal_pelajaran_id ||
      !form.judul.trim() ||
      form.judul.trim().length > 255 ||
      !form.deadline
    ) {
      setError(
        'Jadwal, judul (maksimal 255 karakter), dan tenggat wajib diisi.',
      )
      return
    }

    if (form.files.length + retainedCount > 10) {
      setError('Maksimal 10 lampiran termasuk file yang dipertahankan.')
      return
    }

    if (form.files.some((file) => file.size > MAX_FILE_SIZE)) {
      setError('Setiap file lampiran maksimal 10 MB.')
      return
    }

    if (!schedules.some(s=>sameId(s.id,form.jadwal_pelajaran_id)) || (editing && !assignments.some(a=>sameId(a.id,editing.id)))) {
      setError('Penugasan tidak tersedia dalam konteks mengajar Anda.'); return
    }
    const deadline = deadlineToUtc(form.deadline)
    if (!deadline) {setError('Format tenggat WIB tidak valid.');return}
    if (gradeLoading || gradeError || (form.komponen_nilai_id && !gradeChoices.some(c=>sameId(c.id,form.komponen_nilai_id)))) {
      setError('Pilih komponen nilai yang valid dari jadwal yang sama.'); return
    }
    setSaving(true)

    try {
      const payload = {
        jadwal_pelajaran_id: Number(
          form.jadwal_pelajaran_id,
        ),
        komponen_nilai_id: form.komponen_nilai_id
          ? Number(form.komponen_nilai_id)
          : null,
        judul: form.judul.trim(),
        deskripsi: form.deskripsi.trim() || null,
        deadline,
      }

      if (form.files.length || form.remove_file || form.remove_attachment_ids.length) {
        const data = new FormData()

        Object.entries(payload).forEach(([key, value]) => {
          if (value !== null) data.append(key, String(value))
          else if (editing) data.append(key, '')
        })

        form.files.forEach((file) => data.append('files[]', file))

        form.remove_attachment_ids.forEach((id) =>
          data.append('remove_attachment_ids[]', String(id)),
        )

        if (form.remove_file) {
          data.append('remove_file', '1')
        }

        if (editing) {
          await assignmentApi.updateWithFile(
            editing.id,
            data,
          )
        } else {
          await assignmentApi.createWithFile(data)
        }
      } else if (editing) {
        await assignmentApi.update(editing.id, payload)
      } else {
        await assignmentApi.create(payload)
      }

      setModalOpen(false)
      setNotice(
        editing
          ? 'Tugas berhasil diperbarui.'
          : 'Tugas berhasil ditambahkan.',
      )

      await loadData()
    } catch (err) {
      setError(getMessage(err))
    } finally {
      setSaving(false)
    }
  }

  async function handleDelete() {
    if (!deleteTarget || !assignments.some(a=>sameId(a.id,deleteTarget.id))) return

    setDeleting(true)
    setError('')

    try {
      await assignmentApi.delete(deleteTarget.id)

      setDeleteTarget(null)
      setNotice('Tugas berhasil dihapus.')

      await loadData()
    } catch (err) {
      setError(getMessage(err))
    } finally {
      setDeleting(false)
    }
  }

  async function handleDownload(item, attachment = null) {
    if (!assignments.some(a=>sameId(a.id,item.id))) return
    const downloadKey = attachment?.id ?? `assignment-${item.id}`
    setDownloading(downloadKey)
    setError('')

    try {
      if (attachment?.id) {
        await assignmentApi.downloadAssignmentAttachment(
          attachment.id,
          attachment.name || 'lampiran-tugas',
        )
      } else {
        await assignmentApi.download(
          item.id,
          attachment?.name || item?.file?.name || 'lampiran-tugas',
        )
      }
    } catch (err) {
      setError(getMessage(err))
    } finally {
      setDownloading(null)
    }
  }

  const academicPeriod = activeAcademicYear
    ? `${activeAcademicYear.tahun_mulai}/${activeAcademicYear.tahun_selesai} — ${
        activeAcademicYear.semester === 'GANJIL'
          ? 'Ganjil'
          : activeAcademicYear.semester === 'GENAP'
            ? 'Genap'
            : activeAcademicYear.semester
      }`
    : 'Belum ada tahun akademik aktif'

  const retainedCount = editing ? getAssignmentAttachments(editing).filter(a => a.id ? !form.remove_attachment_ids.includes(Number(a.id)) : !form.remove_file).length : 0
  const scheduleOptions = schedules.map(schedule => ({ value: String(schedule.id), label: `${schedule.kelas?.nama || schedule.kelas?.nama_kelas || 'Kelas'} · ${schedule.kelas?.tingkat == null ? 'Tingkat belum tersedia' : 'Tingkat ' + schedule.kelas.tingkat}`, description: [schedule.mapel?.nama || schedule.mapel?.nama_mapel, schedule.hari, [schedule.jam_mulai,schedule.jam_selesai].filter(Boolean).map(t=>String(t).slice(0,5)).join('–')].filter(Boolean).join(' · ') }))
  const gradeOptions = [{value:'',label:'Tanpa komponen nilai'}, ...gradeChoices.map(c=>({value:String(c.id),label:c.nama || `Komponen ${c.id}`,description:c.bobot == null ? '' : `Bobot ${c.bobot}`}))]
  // Jangan menambahkan komponen yang tidak tercantum dalam daftar jadwal terverifikasi.
  const upcoming = assignments.filter(a=>a.deadline && Number.isFinite(new Date(a.deadline).getTime()) && !isOverdue(a, now)).sort((a,b)=>new Date(a.deadline)-new Date(b.deadline)).slice(0,4)
  const dismissForm = () => { if (!saving) {setModalOpen(false);setError('')} }
  const dismissDelete = () => { if (!deleting) {setDeleteTarget(null);setError('')} }
  return <main className="ga-page">
    <header className="ga-page-head"><div><p className="ga-eyebrow">KEGIATAN MENGAJAR</p><h1>Tugas Pembelajaran<span>.</span></h1><p>Kelola instruksi, tenggat, dan pengumpulan tugas siswa.</p></div><div className="ga-actions"><button type="button" className="ga-btn" onClick={loadData} disabled={loading}>↻ Muat ulang</button><button type="button" className="ga-btn primary" onClick={openCreate} disabled={!activeAcademicYear || loading || !schedules.length}>+ Buat tugas</button></div></header>
    {error && !modalOpen && !deleteTarget && <div className="ga-alert error" role="alert">{error}</div>}
    <ToastNotification notice={toastNotice} onClose={clearNotice}/>
    <section className="ga-band" aria-label="Ringkasan tugas"><div className="ga-band-intro"><p className="ga-eyebrow">RUANG KERJA PENUGASAN</p><h2>Kelola aktivitas pembelajaran</h2><p>Periode aktif · {academicPeriod}</p><p>Ringkasan tugas yang dimuat.</p></div><div className="ga-metrics">{[['Total tugas',summary.total],['Tenggat mendatang',summary.upcoming],['Tenggat terlewati',summary.overdue],['Dengan lampiran',summary.withAttachment]].map(([label,n])=><div key={label}><span>{label}</span><strong>{loading?'—':n}</strong></div>)}</div></section>
    <section className="ga-filter"><div className="ga-section-head"><div><h2>Temukan tugas</h2><p>Telusuri judul atau instruksi, lalu persempit berdasarkan jadwal.</p></div><button className="ga-link" type="button" disabled={!search&&!scheduleFilter&&!statusFilter} onClick={resetFilters}>↻ Reset filter</button></div><div className="ga-filter-grid"><label className="ga-field">Cari tugas<input type="search" value={search} onChange={e=>setSearch(e.target.value)} placeholder="Cari judul, instruksi, atau mapel..."/></label><SearchableSelect label="Jadwal mengajar" value={scheduleFilter} options={[{value:'',label:'Semua jadwal'},...scheduleOptions]} onChange={setScheduleFilter} disabled={loading}/><SearchableSelect label="Status tenggat" value={statusFilter} options={[{value:'',label:'Semua status'},{value:'upcoming',label:'Tenggat mendatang'},{value:'overdue',label:'Tenggat terlewati'}]} onChange={setStatusFilter}/></div></section>
    <div className="ga-layout"><section className="ga-main-list"><div className="ga-section-head"><div><h2>Daftar tugas</h2><p>{loading?'Memuat tugas...':`${filtered.length} dari ${assignments.length} tugas dimuat`}</p></div><div className="ga-toggle" aria-label="Tampilan daftar">{[['grid','Grid'],['list','List']].map(([v,l])=><button key={v} type="button" aria-pressed={view===v} onClick={()=>setView(v)}>{l}</button>)}</div></div>
    {loading ? <div className="ga-empty" role="status">Memuat tugas...</div> : !filtered.length ? <div className="ga-empty"><h3>{assignments.length?'Tidak ada hasil yang cocok':'Belum ada tugas'}</h3><p>{assignments.length?'Coba ubah filter pencarian.':'Tugas yang dibuat akan tampil di sini.'}</p></div> : <div className={`ga-cards ${view==='list'?'ga-list':''}`}>{filtered.map(item=>{const files=getAssignmentAttachments(item); const schedule=schedules.find(s=>String(s.id)===String(item.jadwal_pelajaran_id ?? item.jadwal_pelajaran?.id)) || item.jadwal_pelajaran;const late=isOverdue(item, now);const known=item.deadline && Number.isFinite(new Date(item.deadline).getTime());const expanded=expandedFiles.includes(item.id);return <article className="ga-card" key={item.id}><div className="ga-card-top"><span className="ga-task-icon" aria-hidden="true">▤</span><span className={`ga-state ${!known?'neutral':late?'late':'active'}`}>{!known?'Tanpa tenggat':late?'Terlewati':'Aktif'}</span></div><h3>{item.judul}</h3><p className="ga-subject">{getSubjectName(item,schedules)}</p><div className="ga-class">{schedule?.kelas?.nama || schedule?.kelas?.nama_kelas || 'Kelas belum tersedia'} · {schedule?.kelas?.tingkat == null?'Tingkat belum tersedia':`Tingkat ${schedule.kelas.tingkat}`}</div>{item.deskripsi && <p className="ga-description">{item.deskripsi}</p>}<div className="ga-card-meta"><span>Tenggat pengumpulan</span><strong>{localDateTime(item.deadline)}</strong></div><button type="button" className="ga-attachment-toggle" aria-expanded={expanded} onClick={()=>setExpandedFiles(old=>expanded?old.filter(id=>id!==item.id):[...old,item.id])} disabled={!files.length}>Lampiran ({files.length}) <span>{expanded?'−':'+'}</span></button>{expanded && <div className="ga-file-list">{files.map((file,i)=><div className="ga-file" key={file.id??i}><FileTypeIcon name={file.name}/><div><strong>{file.name}</strong><small>{formatFileSize(file.size)}</small></div><button type="button" className="ga-btn" disabled={downloading!=null} onClick={()=>handleDownload(item,file)}>{downloading===(file.id??`assignment-${item.id}`)?'Memuat…':'Unduh'}</button></div>)}</div>}<button type="button" className="ga-btn review" onClick={()=>setSubmissionTarget(item)}>Lihat pengumpulan <span>→</span></button><div className="ga-card-actions"><button className="ga-link" type="button" onClick={()=>openEdit(item)}>Edit</button><button className="ga-link danger" type="button" onClick={()=>{setError('');setDeleteTarget(item)}}>Hapus</button></div></article>})}</div>}
    </section><aside className="ga-aside"><section className="ga-deadlines"><p className="ga-eyebrow">AGENDA PENUGASAN</p><h2>Tenggat terdekat</h2><p>Diurutkan dari tugas yang dimuat.</p>{loading?<p>Memuat...</p>:!upcoming.length?<p>Belum ada tenggat mendatang.</p>:upcoming.map(item=><div className="ga-deadline" key={item.id}><div className="ga-date-tile">{new Intl.DateTimeFormat('id-ID',{day:'numeric',timeZone:'Asia/Jakarta'}).format(new Date(item.deadline))}<small>{new Intl.DateTimeFormat('id-ID',{month:'short',timeZone:'Asia/Jakarta'}).format(new Date(item.deadline))}</small></div><div><strong>{item.judul}</strong><small>{localDateTime(item.deadline)}</small></div></div>)}</section><section className="ga-guide"><h3>Ruang kerja tugas</h3><p>Buka pengumpulan pada kartu tugas untuk memeriksa jawaban dan lampiran siswa.</p></section></aside></div>
    {modalOpen && <WorkspaceModal className="ga-editor" eyebrow="PENGELOLAAN TUGAS" title={editing?'Edit tugas':'Buat tugas baru'} description="Lengkapi informasi tugas dan ketentuan pengumpulan." onClose={dismissForm} busy={saving} footer={<><button className="ga-btn" type="button" onClick={dismissForm} disabled={saving}>Batal</button><button className="ga-btn primary" type="submit" form="ga-editor-form" disabled={saving||gradeLoading||!schedules.length}>{saving?'Menyimpan…':editing?'Simpan perubahan':'Buat tugas'}</button></>}>
    <form id="ga-editor-form" className="ga-form" onSubmit={handleSubmit} noValidate>{error&&<div className="ga-alert error" role="alert">{error}</div>}<fieldset disabled={saving}><legend>01 / INFORMASI TUGAS</legend><div className="ga-form-grid"><SearchableSelect label="Jadwal mengajar" required value={form.jadwal_pelajaran_id} options={scheduleOptions} onChange={chooseSchedule} placeholder="Pilih jadwal mengajar" disabled={saving}/><label className="ga-field">Judul tugas *<input name="judul" required maxLength={255} value={form.judul} onChange={updateField} placeholder="Contoh: Latihan Bab 1"/></label><label className="ga-field ga-full">Deskripsi / instruksi<textarea name="deskripsi" rows={4} value={form.deskripsi} onChange={updateField} placeholder="Tuliskan instruksi pengerjaan tugas..."/></label></div></fieldset>
    <fieldset disabled={saving}><legend>02 / KETENTUAN PENGUMPULAN</legend><div className="ga-form-grid"><label className="ga-field">Batas waktu pengumpulan *<input name="deadline" type="datetime-local" required value={form.deadline} onChange={updateField}/><small>Waktu sekolah (WIB / Asia Jakarta).</small></label><div><SearchableSelect label="Komponen nilai (opsional)" value={form.komponen_nilai_id} options={gradeOptions} onChange={v=>setForm(old=>({...old,komponen_nilai_id:v}))} disabled={saving||gradeLoading||!form.jadwal_pelajaran_id} placeholder={gradeLoading?'Memuat komponen...':'Pilih komponen nilai'}/><p className="ga-help">Komponen harus berasal dari jadwal yang dipilih.</p>{gradeError&&<p className="ga-help" role="alert">Daftar komponen gagal dimuat: {gradeError}. Pilih ulang jadwal atau buka kembali editor.</p>}</div></div></fieldset>
    <fieldset disabled={saving}><legend>03 / LAMPIRAN TUGAS</legend>{editing&&getAssignmentAttachments(editing).length>0&&<><h3>Lampiran tersimpan</h3><div className="ga-file-list">{getAssignmentAttachments(editing).map((file,i)=>{const marked=file.id?form.remove_attachment_ids.includes(Number(file.id)):form.remove_file;return <div className={`ga-file ${marked?'marked':''}`} key={file.id??i}><FileTypeIcon name={file.name}/><div><strong>{file.name}</strong><small>{formatFileSize(file.size)}{marked?' · Akan dihapus saat disimpan':''}</small></div><label className="ga-remove"><input type="checkbox" name={file.id?'remove_attachment_ids':'remove_file'} value={file.id??'legacy'} checked={marked} onChange={updateField}/> Hapus</label></div>})}</div><p className="ga-help">Lampiran ditandai untuk dihapus ketika perubahan disimpan.</p></>}<FileDropzone onFiles={addFiles} disabled={saving}/><p className="ga-help">Maksimal 10 file, 10 MB per file. PDF, JPG, PNG, DOC(X), XLS(X), PPT(X). {retainedCount+form.files.length}/10 lampiran.</p>{form.files.length>0&&<div className="ga-file-list">{form.files.map((file,i)=><div key={`${file.name}-${i}`} className="ga-file"><FileTypeIcon name={file.name}/><div><strong>{file.name}</strong><small>{formatFileSize(file.size)} · File baru</small></div><button className="ga-link danger" type="button" onClick={()=>setForm(old=>({...old,files:old.files.filter((_,j)=>j!==i)}))}>Hapus</button></div>)}</div>}</fieldset></form></WorkspaceModal>}
    {deleteTarget&&<ConfirmDialog title="Hapus tugas?" description="Tindakan ini tidak dapat dibatalkan." danger confirmLabel="Ya, hapus tugas" busy={deleting} error={error} onClose={dismissDelete} onConfirm={handleDelete}><p>Tugas <strong>{deleteTarget.judul}</strong> akan dihapus. Server dapat menolak penghapusan jika sudah memiliki pengumpulan siswa.</p></ConfirmDialog>}
    {submissionTarget&&<GuruAssignmentSubmissions assignment={submissionTarget} onClose={()=>setSubmissionTarget(null)}/>}
  </main>
}
