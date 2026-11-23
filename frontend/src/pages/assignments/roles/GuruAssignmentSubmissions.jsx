import { useCallback, useEffect, useMemo, useState, useRef } from 'react'
import { submissionRows } from './assignmentScope'
import { assignmentApi } from '../../../services/assignmentApi'
import { parseSubmissionClassOverview } from './submissionClassOverview'
import WorkspaceModal from '../../../components/ui/WorkspaceModal'
import FileTypeIcon from '../../../components/ui/FileTypeIcon'
import '../styles/GuruAssignmentSubmissions.css'

function formatDate(value) {
  if (!value) return 'Belum tersedia'

  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return 'Belum tersedia'

  return (
    new Intl.DateTimeFormat('id-ID', {
      dateStyle: 'medium',
      timeStyle: 'short',
      timeZone: 'Asia/Jakarta',
    }).format(date) + ' WIB'
  )
}

function getStatus(submission, deadline) {
  if (!submission) return { label: 'Belum mengumpulkan', type: 'pending' }

  const status = String(submission.status || '').toLowerCase()

  if (status === 'dinilai') return { label: 'Sudah dinilai', type: 'graded' }
  if (status === 'perlu_revisi') return { label: 'Perlu revisi', type: 'revision' }

  if (status === 'terlambat') return { label: 'Terlambat', type: 'late' }
  if (status !== 'dikumpulkan') return { label: 'Status belum dikenal', type: 'pending' }

  return { label: 'Sudah mengumpulkan', type: 'submitted' }
}

function getFiles(submission) {
  if (Array.isArray(submission?.files) && submission.files.length) {
    return submission.files
  }

  if (submission?.file?.name) return [{ ...submission.file, id: null }]
  return []
}

function formatFileSize(bytes) {
  const size = Number(bytes)
  if (bytes == null || !Number.isFinite(size) || size < 0) return ''
  if (size < 1024) return `${size} B`
  if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} KB`
  return `${(size / (1024 * 1024)).toFixed(1)} MB`
}

function getErrorMessage(error) {
  return (
    error?.response?.data?.message ||
    error?.payload?.message ||
    error?.message ||
    'Terjadi kesalahan. Silakan coba kembali.'
  )
}

export default function GuruAssignmentSubmissions({ assignment, onClose }) {
  const generation = useRef(0)
  const [submissions, setSubmissions] = useState([])
  const [pagination, setPagination] = useState(null)
  const [classOverview, setClassOverview] = useState(null)
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [downloading, setDownloading] = useState(null)
  const [expandedId, setExpandedId] = useState(null)

  const [selectedId,setSelectedId] = useState(null)
  const [query,setQuery] = useState('')
  const [mobileDetail,setMobileDetail] = useState(false)

  const loadSubmissions = useCallback(async () => {
    const requestId=++generation.current
    setLoading(true)
    setSubmissions([])
    setClassOverview(null)
    setError('')
    setMobileDetail(false)
    setExpandedId(null)

    try {
      const response = await assignmentApi.getSubmissions(assignment.id, { page })
      if (requestId !== generation.current) return
      const rows = submissionRows(response,assignment.id)
      const overview = parseSubmissionClassOverview(response?.meta?.class_overview, assignment)
      setSubmissions(rows)
      setClassOverview(overview)
      setSelectedId(old => rows.some(row=>row.id===old)?old:rows[0]?.id??null)
      setPagination(response?.meta ?? null)
    } catch (err) {
      if (requestId !== generation.current) return
      setError(getErrorMessage(err))
      setSubmissions([])
      setClassOverview(null)
      setPagination(null)
    } finally {
      if (requestId === generation.current) setLoading(false)
    }
  }, [assignment.id, page])

  useEffect(() => {
    loadSubmissions()
    return ()=>{generation.current++}
  }, [loadSubmissions])

  const summary = useMemo(() => {
    const result = { total: submissions.length, submitted: 0, late: 0, revision: 0, graded: 0 }

    submissions.forEach((submission) => {
      const status = getStatus(submission, assignment.deadline)
      if (status.type === 'submitted') result.submitted += 1
      if (status.type === 'late') result.late += 1
      if (status.type === 'revision') result.revision += 1
      if (status.type === 'graded') result.graded += 1
    })

    return result
  }, [submissions, assignment.deadline])

  async function downloadFile(file) {
    if (!file?.id) {
      setError('Lampiran ini menggunakan format lama dan tidak memiliki ID unduhan.')
      return
    }

    setDownloading(file.id)
    setError('')

    try {
      await assignmentApi.downloadSubmissionAttachment(file.id, file.name || 'lampiran-jawaban')
    } catch (err) {
      setError(getErrorMessage(err))
    } finally {
      setDownloading(null)
    }
  }

  const currentPage = Number(pagination?.current_page || page)
  const lastPage = Number(pagination?.last_page || 1)
  const totalRows = pagination?.total == null ? null : Number(pagination.total)
  const completionPercent = classOverview?.total_students
    ? Math.round(classOverview.submitted_students * 100 / classOverview.total_students)
    : 0

  const shown = submissions.filter(s => `${s.siswa?.nama_lengkap || ''} ${s.siswa?.nis || ''} ${getStatus(s,assignment.deadline).label}`.toLocaleLowerCase('id').includes(query.trim().toLocaleLowerCase('id')))
  const selected = shown.find(s=>s.id===selectedId) || shown[0]
  const nameOf = s=>s?.siswa?.nama_lengkap || `Siswa #${s?.siswa_id ?? '—'}`
  const statusOf = s=>getStatus(s,assignment.deadline)
  const files = getFiles(selected)
  function statusBadge(s){const status=statusOf(s);return <span className={`gs-status ${status.type}`}>{status.label}</span>}
  return <WorkspaceModal className="gs-workspace" eyebrow="KEGIATAN MENGAJAR / PEMANTAUAN" title="Pemantauan pengumpulan" description={`${assignment.judul || 'Tugas'} · Tenggat: ${formatDate(assignment.deadline)}`} onClose={onClose} footer={<><span className="gs-footer-label">{totalRows != null ? `Total ${totalRows} pengumpulan tercatat` : `${submissions.length} pengumpulan pada halaman ini`}</span><button className="ga-btn primary" type="button" onClick={onClose}>Selesai</button></>}>
  {error&&<div className="ga-alert error" role="alert">{error}<button className="ga-link" type="button" onClick={()=>setError('')}>Tutup</button></div>}
  {classOverview ? <section className="gs-class-overview" aria-label="Rekap pengumpulan seluruh anggota kelas aktif">
    <div className="gs-overview-copy"><span className="gs-overview-kicker">PROGRES KELAS · ANGGOTA AKTIF</span><strong>{classOverview.class_name || 'Kelas tugas ini'}</strong><small>{classOverview.subject_name ? `${classOverview.subject_name} · ` : ''}Anggota aktif per {classOverview.as_of} WIB</small></div>
    <div className="gs-overview-count"><strong>{classOverview.submitted_students}<span> / {classOverview.total_students}</span></strong><small>siswa mengumpulkan</small></div>
    <div className="gs-overview-pending"><strong>{classOverview.not_submitted_students}</strong><small>belum mengumpulkan</small></div>
    <div className="gs-overview-progress" role="progressbar" aria-label="Progres pengumpulan kelas" aria-valuemin={0} aria-valuemax={100} aria-valuenow={completionPercent}><span style={{width: `${completionPercent}%`}}/></div>
  </section> : (!loading && !error ? <p className="gs-scope">Rekap anggota kelas belum tersedia dari API. Angka siswa tidak ditebak dari jumlah submission.</p> : null)}
  <div className="gs-metrics" aria-label="Ringkasan halaman aktif">{[['Data dimuat',summary.total,''],['Sudah mengumpulkan',summary.submitted,'submitted'],['Terlambat',summary.late,'late'],['Perlu revisi',summary.revision,'revision'],['Sudah dinilai',summary.graded,'graded']].map(([l,n,t])=><div className={t} key={l}><span>{l}</span><strong>{loading?'—':n}</strong></div>)}</div>
  <p className="gs-scope">Status di bawah ini hanya dihitung dari halaman submission yang dimuat. Rekap anggota kelas di atas memakai seluruh anggota aktif pada tanggal yang ditampilkan.</p>
  <div className="gs-toolbar"><div><h3>Daftar pengumpulan</h3><p>Periksa jawaban dan lampiran siswa.</p></div><button type="button" className="ga-btn" onClick={loadSubmissions} disabled={loading}>{loading?'Memuat…':'↻ Muat ulang'}</button></div>
  {loading?<div className="ga-empty" role="status">Memuat pengumpulan...</div>:!submissions.length?<div className="ga-empty">Belum ada pengumpulan yang tercatat.</div>:<div className={`gs-split ${mobileDetail&&selected?'show-detail':''}`}><section className="gs-list"><label className="ga-field">Cari siswa<input type="search" value={query} onChange={e=>{setQuery(e.target.value);setMobileDetail(false)}} placeholder="Nama, NIS, atau status"/></label><p className="gs-list-count">{shown.length} pengumpulan</p><div className="gs-students">{shown.map(s=><button type="button" key={s.id} aria-pressed={selected?.id===s.id} onClick={()=>{setSelectedId(s.id);setMobileDetail(true);setExpandedId(null)}} className="gs-student"><span className="gs-avatar" aria-hidden="true">{nameOf(s).trim().slice(0,1).toUpperCase()}</span><span className="gs-student-copy"><strong>{nameOf(s)}</strong><small>NIS: {s.siswa?.nis||'—'}</small>{statusBadge(s)}</span><span aria-hidden="true">›</span></button>)}{!shown.length&&<p>Tidak ada hasil pencarian.</p>}</div></section><section className="gs-detail">{selected?<><button type="button" className="ga-btn gs-back" onClick={()=>setMobileDetail(false)}>← Daftar siswa</button><p className="ga-eyebrow">DETAIL JAWABAN</p><div className="gs-detail-head"><div><h3>{nameOf(selected)}</h3><p>NIS: {selected.siswa?.nis||'—'}</p></div>{statusBadge(selected)}</div><div className="gs-time"><span>Waktu pengumpulan</span><strong>{formatDate(selected.submitted_at)}</strong></div><div className="gs-answer"><h4>Jawaban siswa</h4><p>{selected.jawaban?.trim()||'Siswa tidak mengisi jawaban teks.'}</p></div><h4>Lampiran jawaban ({files.length})</h4>{files.length?<div className="ga-file-list">{files.map((file,i)=><div key={file.id??i} className="ga-file"><FileTypeIcon name={file.name}/><div><strong>{file.name||'Lampiran jawaban'}</strong><small>{formatFileSize(file.size)||file.mime_type||'File lampiran'}</small></div>{file.id?<button type="button" className="ga-btn" onClick={()=>downloadFile(file)} disabled={downloading!=null}>{downloading===file.id?'Mengunduh…':'Unduh'}</button>:<small>File lama</small>}</div>)}</div>:<p>Tidak ada lampiran.</p>}{selected.catatan&&<div className="gs-answer"><h4>Catatan</h4><p>{selected.catatan}</p></div>}<button className="ga-attachment-toggle" type="button" aria-expanded={expandedId===selected.id} onClick={()=>setExpandedId(old=>old===selected.id?null:selected.id)}>Informasi pengumpulan <span>{expandedId===selected.id?'−':'+'}</span></button>{expandedId===selected.id&&<dl className="gs-extra"><dt>ID pengumpulan</dt><dd>{selected.id}</dd><dt>Status sistem</dt><dd>{selected.status||'—'}</dd><dt>Terakhir diperbarui</dt><dd>{formatDate(selected.updated_at)}</dd></dl>}</>:<p>Pilih pengumpulan siswa.</p>}</section></div>}
  {pagination&&lastPage>1&&<nav className="gs-pagination" aria-label="Navigasi halaman pengumpulan"><button type="button" className="ga-btn" disabled={loading||currentPage<=1} onClick={()=>setPage(p=>Math.max(1,p-1))}>Sebelumnya</button><span>Halaman {currentPage} dari {lastPage}</span><button type="button" className="ga-btn" disabled={loading||currentPage>=lastPage} onClick={()=>setPage(p=>Math.min(lastPage,p+1))}>Berikutnya</button></nav>}
  </WorkspaceModal>
}
