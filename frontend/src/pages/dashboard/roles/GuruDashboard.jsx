import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../../context/useAuth'
import { useDashboard } from '../hooks/useDashboard'
import { homeroomApi } from '../../../services/homeroomApi'
import { classApi } from '../../../services/classApi'
import LoadingState from '../../../components/ui/LoadingState'
import ErrorState from '../../../components/ui/ErrorState'
import {teacherDay} from '../../../utils/teacherDay'
import './GuruDashboard.css'

/**
 * Dashboard Guru V4 — redesign presentasi dari data GET /api/dashboard.
 * Tidak memperluas role Guru maupun menganggap jadwal = bukti mengajar.
 */
const SUMMARY_ITEMS = [
  { label: 'Jadwal mengajar', key: 'teaching_schedule_count', icon: 'calendar', tone: 'teal', detail: 'Entri jadwal yang ditugaskan' },
  { label: 'Bahan ajar', key: 'material_count', icon: 'book', tone: 'blue', detail: 'Materi yang telah disimpan' },
  { label: 'Tugas', key: 'assignment_count', icon: 'clipboard', tone: 'amber', detail: 'Tugas yang telah dibuat' },
  { label: 'Komponen nilai', key: 'grade_component_count', icon: 'chart', tone: 'purple', detail: 'Komponen nilai terdaftar' },
  { label: 'Catatan presensi', key: 'attendance_record_count', icon: 'check', tone: 'cyan', detail: 'Catatan siswa per pertemuan' },
]

const QUICK_LINKS = [
  { title: 'Jadwal Mengajar', description: 'Lihat kelas dan pembagian jam mengajar.', path: '/app/schedules', permission: 'schedules.view', icon: 'calendar', tone: 'teal' },
  { title: 'Presensi', description: 'Kelola kehadiran siswa sesuai jadwal.', path: '/app/attendance', permission: 'attendance.view', icon: 'check', tone: 'blue' },
  { title: 'Bahan Ajar', description: 'Buka materi yang sudah disiapkan.', path: '/app/materials', permission: 'materials.view', icon: 'book', tone: 'amber' },
  { title: 'Tugas', description: 'Kelola tugas dan pengumpulan siswa.', path: '/app/assignments', permission: 'assignments.view', icon: 'clipboard', tone: 'purple' },
  { title: 'Penilaian', description: 'Kelola komponen dan nilai dalam cakupan mengajar.', path: '/app/grades', permission: 'grades.view', icon: 'chart', tone: 'cyan' },
]

function Icon({ name, size = 20 }) {
  const symbols = {
    calendar: <><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 10h18M8 14h3M8 17h6"/></>,
    check: <><rect x="4" y="3" width="16" height="18" rx="2"/><path d="m8 12 2.5 2.5L16 9"/></>,
    book: <><path d="M5 4.5A2.5 2.5 0 0 1 7.5 2H20v17H7a2 2 0 0 0-2 2V4.5ZM5 19a2 2 0 0 1 2-2h13"/><path d="M9 7h7M9 11h5"/></>,
    clipboard: <><rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V2h6v2M9 10h6M9 14h6"/></>,
    chart: <><path d="M3 3v18h18M7 15l4-4 3 3 6-7"/></>,
    arrow: <><path d="M5 12h14m-6-6 6 6-6 6"/></>,
    class: <><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 9h10M7 13h7"/></>,
    clock: <><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/></>,
    sparkle: <><path d="m12 2 1.7 6.3L20 10l-6.3 1.7L12 18l-1.7-6.3L4 10l6.3-1.7L12 2Z"/><path d="m19 17 .7 2.3L22 20l-2.3.7L19 23l-.7-2.3L16 20l2.3-.7L19 17Z"/></>,
    layers: <><path d="m12 2 9 5-9 5-9-5 9-5ZM3 12l9 5 9-5M3 17l9 5 9-5"/></>,
  }
  return <svg aria-hidden="true" width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round" className="guru-v4__icon">{symbols[name] ?? symbols.arrow}</svg>
}

function formatCount(value) {
  if (value === null || value === undefined || value === '') return '—'
  const number = Number(value)
  return Number.isSafeInteger(number) && number >= 0 ? number.toLocaleString('id-ID') : '—'
}

function cleanString(value) {
  return typeof value === 'string' && value.trim() ? value.trim() : null
}

function classDetails(record) {
  if (!record || typeof record !== 'object') return null
  const kelas = record.kelas ?? record.class ?? record.kelas_detail ?? null
  const name = cleanString(kelas?.nama) || cleanString(kelas?.nama_kelas) || cleanString(kelas?.name) || cleanString(record.nama_kelas) || cleanString(record.class_name)
  const grade = kelas?.tingkat ?? kelas?.grade ?? record.tingkat ?? null
  return name ? { name, grade } : null
}

function classLabel(kelas) {
  const name = cleanString(kelas?.nama) || cleanString(kelas?.name) || cleanString(kelas?.nama_kelas)
  if (!name) return 'Kelas belum tersedia'
  const grade = kelas?.tingkat ?? kelas?.grade
  const roman = { 10: 'X', 11: 'XI', 12: 'XII' }[String(grade)]
  if (roman && !new RegExp(`^${roman}(?:\\s|[-.])`, 'i').test(name)) return `${roman} ${name}`
  return name
}

function timeLabel(value) {
  if (typeof value !== 'string') return '—'
  const match = value.match(/^(\d{2}):(\d{2})/)
  return match ? `${match[1]}.${match[2]}` : '—'
}

function getRecords(response) {
  if (Array.isArray(response)) return response
  if (Array.isArray(response?.data)) return response.data
  if (Array.isArray(response?.data?.data)) return response.data.data
  if (Array.isArray(response?.items)) return response.items
  return []
}

function isActiveNow(record) {
  const today = teacherDay()
  const start = record?.tanggal_mulai?.slice(0, 10)
  const end = record?.tanggal_selesai?.slice(0, 10)
  if (start && start > today) return false
  if (end && end < today) return false
  return record?.is_active !== false
}

/** Hanya cocokkan identitas guru yang diketahui, bukan nama (tidak unik). */
function matchesTeacher(record, user, dashboard) {
  const knownTeacherId = user?.guru?.id ?? dashboard?.guru?.id ?? dashboard?.guru_id
  const recordTeacherId = record?.guru_id ?? record?.teacher_id ?? record?.guru?.id
  if (knownTeacherId != null && recordTeacherId != null) return String(knownTeacherId) === String(recordTeacherId)
  const knownUserId = user?.id
  const recordUserId = record?.guru?.user_id ?? record?.guru?.user?.id
  if (knownUserId != null && recordUserId != null) return String(knownUserId) === String(recordUserId)
  return null
}

function useHomeroomDetails({ dashboard, user, isHomeroomTeacher }) {
  const embedded = useMemo(() => classDetails(dashboard?.wali_kelas), [dashboard])
  const [state, setState] = useState({ status: 'idle', info: null })

  useEffect(() => {
    if (!dashboard || !isHomeroomTeacher || embedded) return undefined
    let active = true
    setState({ status: 'loading', info: null })

    async function resolveHomeroom() {
      try {
        // Endpoint terscope oleh Laravel untuk akun Guru/Wali Kelas.
        const response = await homeroomApi.getAll({ scope: 'homeroom', page: 1, per_page: 100 })
        const records = getRecords(response).filter(isActiveNow)
        const knownYearId = dashboard?.tahun_akademik?.id ?? dashboard?.academic_year?.id ?? null
        const candidates = knownYearId == null ? records : records.filter((item) => String(item.tahun_akademik_id) === String(knownYearId))
        const own = candidates.filter((record) => matchesTeacher(record, user, dashboard) === true)
        const chosen = own[0] ?? (candidates.length === 1 && matchesTeacher(candidates[0], user, dashboard) !== false ? candidates[0] : null)
        let info = classDetails(chosen)
        const classId = chosen?.kelas_id ?? dashboard?.wali_kelas?.kelas_id
        if (!info && classId) {
          try {
            const classResponse = await classApi.getById(classId)
            info = classDetails({ kelas: classResponse?.data ?? classResponse })
          } catch { /* Jangan menebak nama kelas dari ID */ }
        }
        if (active) setState({ status: info ? 'ready' : 'unavailable', info })
      } catch {
        if (active) setState({ status: 'error', info: null })
      }
    }
    resolveHomeroom()
    return () => { active = false }
  }, [dashboard, embedded, isHomeroomTeacher, user])

  if (!isHomeroomTeacher) return { status: 'inactive', info: null }
  if (embedded) return { status: 'ready', info: embedded }
  return state
}

function TodayAgenda({ entries, canViewSchedule, contextStatus }) {
  return (
    <section className="guru-v4__panel guru-v4__agenda" aria-labelledby="guru-agenda-heading">
      <div className="guru-v4__panel-top">
        <div><span className="guru-v4__kicker">AGENDA PENGAJARAN</span><h2 id="guru-agenda-heading">Jadwal hari ini</h2><p>Disusun berdasarkan jadwal rutin yang tersimpan di SIAKAD.</p></div>
        <span className="guru-v4__counter">{entries.length} sesi</span>
      </div>
      {entries.length ? (
        <ol className="guru-v4__agenda-list">
          {entries.map((row, i) => (
            <li key={row.id ?? `schedule-${i}`} className="guru-v4__agenda-item">
              <div className="guru-v4__agenda-time"><strong>{timeLabel(row.jam_mulai)}</strong><span>{timeLabel(row.jam_selesai)}</span></div>
              <div className="guru-v4__agenda-marker" aria-hidden="true"><span /></div>
              <div className="guru-v4__agenda-detail"><strong>{cleanString(row.mata_pelajaran?.nama) ?? 'Mata pelajaran belum tersedia'}</strong><span>{classLabel(row.kelas)} · {cleanString(row.hari) ?? 'Hari belum tersedia'}</span></div>
              <span className="guru-v4__scheduled">Terjadwal</span>
            </li>
          ))}
        </ol>
      ) : (
        <div className="guru-v4__agenda-empty"><span className="guru-v4__agenda-empty-icon"><Icon name="calendar" size={23} /></span><div><strong>{contextStatus==='active'?'Belum ada jadwal rutin hari ini':'Konteks jadwal belum tersedia'}</strong><p>Periksa halaman Jadwal untuk melihat seluruh jadwal mengajar.</p></div></div>
      )}
      <div className="guru-v4__panel-foot">
        <span>Terjadwal tidak berarti pertemuan sudah berlangsung.</span>
        {canViewSchedule && <Link to="/app/schedules">Semua jadwal <Icon name="arrow" size={16} /></Link>}
      </div>
    </section>
  )
}

function RecentActivity({ recent, permissions }) {
  const materials = Array.isArray(recent?.materials) ? recent.materials.slice(0, 3) : []
  const assignments = Array.isArray(recent?.assignments) ? recent.assignments.slice(0, 3) : []
  const canMaterials = permissions.includes('materials.view')
  const canAssignments = permissions.includes('assignments.view')
  if (!canMaterials && !canAssignments) return null

  return (
    <section className="guru-v4__panel guru-v4__recent" aria-labelledby="guru-recent-heading">
      <div className="guru-v4__panel-top"><div><span className="guru-v4__kicker">TERBARU DARI SISTEM</span><h2 id="guru-recent-heading">Aktivitas terkini</h2><p>Materi dan tugas yang sudah tercatat.</p></div></div>
      <div className="guru-v4__recent-columns">
        {canMaterials && <div className="guru-v4__recent-group">
          <div className="guru-v4__recent-subhead"><span className="guru-v4__recent-icon guru-v4__recent-icon--materials"><Icon name="book" size={18} /></span><strong>Bahan ajar</strong><Link to="/app/materials" aria-label="Lihat semua bahan ajar"><Icon name="arrow" size={15}/></Link></div>
          {materials.length ? <ul>{materials.map((row, i) => <li key={row.id ?? `material-${i}`}><span>{cleanString(row.judul) ?? 'Judul belum tersedia'}</span><small>{cleanString(row.mata_pelajaran) ?? 'Mapel belum tersedia'}</small></li>)}</ul> : <p className="guru-v4__recent-empty">Belum ada bahan ajar tersimpan.</p>}
        </div>}
        {canAssignments && <div className="guru-v4__recent-group">
          <div className="guru-v4__recent-subhead"><span className="guru-v4__recent-icon guru-v4__recent-icon--tasks"><Icon name="clipboard" size={18}/></span><strong>Tugas</strong><Link to="/app/assignments" aria-label="Lihat semua tugas"><Icon name="arrow" size={15}/></Link></div>
          {assignments.length ? <ul>{assignments.map((row, i) => <li key={row.id ?? `assignment-${i}`}><span>{cleanString(row.judul) ?? 'Judul belum tersedia'}</span><small>{cleanString(row.mata_pelajaran) ?? 'Mapel belum tersedia'}</small></li>)}</ul> : <p className="guru-v4__recent-empty">Belum ada tugas tersimpan.</p>}
        </div>}
      </div>
      <p className="guru-v4__recent-note">Ringkasan hanya menampilkan beberapa catatan terbaru dari API.</p>
    </section>
  )
}

export default function GuruDashboard() {
  const { user, assignments = [] } = useAuth()
  const [revision,setRevision]=useState(0)
  return <GuruDashboardWorkspace key={`${user?.id}:${JSON.stringify(assignments)}:${revision}`} onRefresh={()=>setRevision(n=>n+1)} />
}

function GuruDashboardWorkspace({onRefresh}) {
  const { user, permissions = [], assignments = [] } = useAuth()
  const { dashboard, loading, error } = useDashboard()
  const isHomeroomTeacher = dashboard?.is_wali_kelas === true && assignments.some((a) => a.type === 'WALI_KELAS')
  const homeroom = useHomeroomDetails({ dashboard, user, isHomeroomTeacher })

  if (loading) return <LoadingState message="Memuat dashboard Guru..." />
  if (error) return <ErrorState message={error.message || 'Dashboard Guru tidak dapat dimuat.'} onRetry={onRefresh}/>
  if (!dashboard) return <ErrorState message="Data dashboard Guru tidak tersedia." />

  const summary = dashboard.summary ?? {}
  const name = cleanString(user?.name) || cleanString(user?.username) || cleanString(dashboard?.user?.name) || 'Guru'
  const canViewSchedule = permissions.includes('schedules.view')
  const quickLinks = QUICK_LINKS.filter((link) => permissions.includes(link.permission))
  const todaySchedule = Array.isArray(dashboard.today_schedule) ? dashboard.today_schedule : []
  const todayCount = Number(summary.today_schedule_count)
  const todayAvailable = summary.today_schedule_count !== null && summary.today_schedule_count !== undefined && Number.isSafeInteger(todayCount) && todayCount >= 0
  const hasTodaySchedule = todayAvailable && todayCount > 0
  const homeroomLabel = homeroom.info?.grade != null ? `${homeroom.info.name} · Tingkat ${homeroom.info.grade}` : homeroom.info?.name

  return (
    <main className="dashboard-page guru-v4">
      <header className="guru-v4__hero" aria-labelledby="guru-v4-title">
        <div className="guru-v4__hero-pattern" aria-hidden="true"><span/><span/><span/></div>
        <div className="guru-v4__hero-copy">
          <span className="guru-v4__hero-kicker"><span className="guru-v4__live-dot"/> RUANG KERJA AKADEMIK · GURU</span>
          <h1 id="guru-v4-title">Selamat datang,<br/><em>{name}.</em></h1>
          <p>Satu tempat untuk melihat agenda mengajar, materi, tugas, dan aktivitas akademik yang menjadi tanggung jawab Anda.</p><p>{dashboard.academic_year?`${dashboard.academic_year.tahun_mulai}/${dashboard.academic_year.tahun_selesai} · ${dashboard.academic_year.semester}`:dashboard.context_status==='conflict'?'Konflik periode aktif — hubungi Tata Usaha':dashboard.context_status==='unlinked'?'Profil Guru belum terhubung':'Belum ada periode aktif'}{dashboard.as_of?` · ${dashboard.as_of} WIB`:''}</p>
          <div className="guru-v4__hero-bottom">
            {canViewSchedule && <Link className="guru-v4__hero-cta" to="/app/schedules">Lihat jadwal saya <Icon name="arrow" size={18}/></Link>}
            <button className="guru-v4__hero-pill" type="button" onClick={onRefresh}>Muat ulang</button><span className="guru-v4__hero-pill"><Icon name="check" size={15}/> Akses Guru</span>
            {isHomeroomTeacher && <span className="guru-v4__hero-pill guru-v4__hero-pill--homeroom"><Icon name="class" size={15}/> Wali Kelas aktif</span>}
          </div>
        </div>
        <div className="guru-v4__hero-side">
          <div className="guru-v4__today-top"><span>JADWAL HARI INI</span><span className="guru-v4__today-icon"><Icon name="calendar" size={21}/></span></div>
          <div className="guru-v4__today-count"><strong>{formatCount(summary.today_schedule_count)}</strong><div><b>jadwal rutin</b><span>tercatat untuk hari ini</span></div></div>
          <div className="guru-v4__today-bottom"><span className="guru-v4__today-status-dot"/>{!todayAvailable ? 'Informasi jadwal hari ini belum tersedia' : hasTodaySchedule ? 'Ada jadwal mengajar yang tercatat' : 'Belum ada jadwal rutin hari ini'}</div>
          <span className="guru-v4__today-caption">Bukan konfirmasi kehadiran atau pelaksanaan mengajar.</span>
        </div>
      </header>

      <section className="guru-v4__summary" aria-labelledby="guru-summary-heading">
        <div className="guru-v4__section-head"><div><span className="guru-v4__kicker">RINGKASAN AKADEMIK</span><h2 id="guru-summary-heading">Aktivitas mengajar</h2><p>Angka bersumber dari catatan dan penugasan yang tersimpan.</p></div><span className="guru-v4__section-aside"><Icon name="layers" size={15}/> Ringkasan akun Guru</span></div>
        <div className="guru-v4__metrics">
          {SUMMARY_ITEMS.map((item) => <div className={`guru-v4__metric guru-v4__metric--${item.tone}`} key={item.key}>
            <span className="guru-v4__metric-icon"><Icon name={item.icon} size={19}/></span>
            <strong className="guru-v4__metric-value">{formatCount(summary[item.key])}</strong>
            <span className="guru-v4__metric-title">{item.label}</span>
            <small>{item.detail}</small>
          </div>)}
        </div>
      </section>

      <div className="guru-v4__focus-grid">
        <TodayAgenda entries={todaySchedule} canViewSchedule={canViewSchedule} contextStatus={dashboard.context_status}/>
        <aside className="guru-v4__panel guru-v4__homeroom" aria-labelledby="guru-homeroom-heading">
          <div className="guru-v4__homeroom-heading"><span className="guru-v4__kicker">KONTEKS PENGAJARAN</span><span className={`guru-v4__homeroom-badge ${isHomeroomTeacher ? 'is-active' : ''}`}>{isHomeroomTeacher ? 'Wali Kelas aktif' : 'Guru mapel'}</span></div>
          <h2 id="guru-homeroom-heading">Penugasan Wali Kelas</h2>
          <p className="guru-v4__homeroom-desc">Akses khusus mengikuti penugasan yang berlaku, bukan role akun baru.</p>
          <div className="guru-v4__homeroom-card" aria-live="polite">
            <span className="guru-v4__homeroom-symbol"><Icon name="class" size={24}/></span>
            <span className="guru-v4__kicker">{isHomeroomTeacher ? 'KELAS YANG DIAMPU' : 'STATUS PENUGASAN'}</span>
            <strong>{!isHomeroomTeacher ? 'Belum ada penugasan aktif' : homeroom.status === 'loading' || homeroom.status === 'idle' ? 'Memeriksa nama kelas...' : homeroomLabel || 'Nama kelas belum diterima dari API'}</strong>
            <small>{isHomeroomTeacher ? (homeroom.status === 'ready' ? 'Kelas sesuai data penugasan aktif SIAKAD.' : 'Penugasan terdeteksi. Periksa detail kelas jika nama belum tersedia.') : 'Modul Wali Kelas muncul jika ada penugasan aktif.'}</small>
          </div>
          {isHomeroomTeacher && <Link className="guru-v4__homeroom-link" to="/app/homeroom">Buka Kelas Saya <Icon name="arrow" size={17}/></Link>}
        </aside>
      </div>

      <RecentActivity recent={dashboard.recent} permissions={permissions}/>

      <section className="guru-v4__shortcuts" aria-labelledby="guru-shortcuts-heading">
        <div className="guru-v4__section-head"><div><span className="guru-v4__kicker">AKSES CEPAT</span><h2 id="guru-shortcuts-heading">Lanjutkan pekerjaan</h2><p>Menu yang ditampilkan mengikuti izin akun Guru.</p></div></div>
        {quickLinks.length ? <div className="guru-v4__shortcut-grid">{quickLinks.map((item) => <Link key={item.path} to={item.path} className={`guru-v4__shortcut guru-v4__shortcut--${item.tone}`}>
          <span className="guru-v4__shortcut-icon"><Icon name={item.icon} size={21}/></span>
          <span className="guru-v4__shortcut-copy"><strong>{item.title}</strong><small>{item.description}</small></span>
          <span className="guru-v4__shortcut-arrow"><Icon name="arrow" size={17}/></span>
        </Link>)}</div> : <p className="guru-v4__shortcut-empty">Belum ada pintasan yang diizinkan untuk akun ini.</p>}
      </section>
    </main>
  )
}
