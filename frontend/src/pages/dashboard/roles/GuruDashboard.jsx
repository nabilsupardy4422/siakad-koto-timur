import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../../context/useAuth'
import { useDashboard } from '../hooks/useDashboard'
import { homeroomApi } from '../../../services/homeroomApi'
import { classApi } from '../../../services/classApi'
import LoadingState from '../../../components/ui/LoadingState'
import ErrorState from '../../../components/ui/ErrorState'
import './GuruDashboard.css'

/**
 * Dashboard Guru V3.
 * - Statistik tetap berasal dari GET /api/dashboard.
 * - Detail Wali Kelas: gunakan objek dashboard dahulu, lalu endpoint
 *   homeroom-assignments (yang sudah dipakai pada halaman Kelas Saya).
 * - Hak akses dan pembatasan data tetap ditentukan Laravel.
 */
const SUMMARY_ITEMS = [
  { label: 'Jadwal mengajar', key: 'teaching_schedule_count', short: 'Jadwal' },
  { label: 'Bahan ajar', key: 'material_count', short: 'Materi' },
  { label: 'Tugas', key: 'assignment_count', short: 'Tugas' },
  { label: 'Komponen nilai', key: 'grade_component_count', short: 'Penilaian' },
  { label: 'Catatan presensi', key: 'attendance_record_count', short: 'Presensi' },
]

const QUICK_LINKS = [
  {
    title: 'Jadwal Mengajar',
    description: 'Lihat pembagian jam dan kelas mengajar Anda.',
    path: '/app/schedules',
    permission: 'schedules.view',
    icon: 'calendar',
  },
  {
    title: 'Presensi',
    description: 'Kelola kehadiran sesuai jadwal pengajaran.',
    path: '/app/attendance',
    permission: 'attendance.view',
    icon: 'check',
  },
  {
    title: 'Bahan Ajar',
    description: 'Siapkan materi untuk kegiatan pembelajaran.',
    path: '/app/materials',
    permission: 'materials.view',
    icon: 'book',
  },
  {
    title: 'Tugas',
    description: 'Kelola tugas dan aktivitas belajar.',
    path: '/app/assignments',
    permission: 'assignments.view',
    icon: 'clipboard',
  },
  {
    title: 'Penilaian',
    description: 'Kelola komponen nilai sesuai kewenangan.',
    path: '/app/grades',
    permission: 'grades.view',
    icon: 'chart',
  },
]

function Icon({ name }) {
  const paths = {
    calendar: <><rect x="3" y="5" width="18" height="16" rx="2" /><path d="M7 3v4M17 3v4M3 10h18" /></>,
    check: <><rect x="4" y="3" width="16" height="18" rx="2" /><path d="m8 12 2.5 2.5L16 9" /></>,
    book: <><path d="M5 4.5A2.5 2.5 0 0 1 7.5 2H20v17H7a2 2 0 0 0-2 2V4.5ZM5 19a2 2 0 0 1 2-2h13" /><path d="M9 7h7M9 11h5" /></>,
    clipboard: <><rect x="5" y="4" width="14" height="17" rx="2" /><path d="M9 4V2h6v2M9 10h6M9 14h6" /></>,
    chart: <><path d="M3 3v18h18M7 15l4-4 3 3 6-7" /></>,
    arrow: <><path d="M5 12h14m-6-6 6 6-6 6" /></>,
    class: <><rect x="3" y="4" width="18" height="16" rx="2" /><path d="M7 9h10M7 13h7" /></>,
  }
  return <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" className="guru-v3__icon">{paths[name] ?? paths.arrow}</svg>
}

function formatCount(value) {
  const number = Number(value ?? 0)
  return Number.isFinite(number) ? number.toLocaleString('id-ID') : '—'
}

function cleanString(value) {
  return typeof value === 'string' && value.trim() ? value.trim() : null
}

function classDetails(record) {
  if (!record || typeof record !== 'object') return null
  const kelas = record.kelas ?? record.class ?? record.kelas_detail ?? null
  const name = cleanString(kelas?.nama) || cleanString(kelas?.nama_kelas) ||
    cleanString(kelas?.name) || cleanString(record.nama_kelas) || cleanString(record.class_name)
  const grade = kelas?.tingkat ?? kelas?.grade ?? record.tingkat ?? null
  return name ? { name, grade } : null
}

function getRecords(response) {
  // apiClient mengembalikan body JSON. Bentuk nested dipertahankan untuk kompatibilitas.
  if (Array.isArray(response)) return response
  if (Array.isArray(response?.data)) return response.data
  if (Array.isArray(response?.data?.data)) return response.data.data
  if (Array.isArray(response?.items)) return response.items
  return []
}

function isActiveNow(record) {
  const current = new Date()
  const today = `${current.getFullYear()}-${String(current.getMonth() + 1).padStart(2, '0')}-${String(current.getDate()).padStart(2, '0')}`
  const start = record?.tanggal_mulai?.slice(0, 10)
  const end = record?.tanggal_selesai?.slice(0, 10)
  if (start && start > today) return false
  if (end && end < today) return false
  return record?.is_active !== false
}

/** Hindari mencocokkan guru dari nama tampilan, karena nama tidak unik. */
function matchesTeacher(record, user, dashboard) {
  const knownTeacherId = user?.guru?.id ?? dashboard?.guru?.id ?? dashboard?.guru_id
  const recordTeacherId = record?.guru_id ?? record?.teacher_id ?? record?.guru?.id
  if (knownTeacherId != null && recordTeacherId != null) {
    return String(knownTeacherId) === String(recordTeacherId)
  }
  const knownUserId = user?.id
  const recordUserId = record?.guru?.user_id ?? record?.guru?.user?.id
  if (knownUserId != null && recordUserId != null) {
    return String(knownUserId) === String(recordUserId)
  }
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
        // Endpoint ini untuk akun GURU HARUS sudah terscope oleh Laravel.
        const response = await homeroomApi.getAll({ scope: 'homeroom', page: 1, per_page: 100 })
        const records = getRecords(response).filter(isActiveNow)
        const knownYearId = dashboard?.tahun_akademik?.id ?? dashboard?.academic_year?.id ?? null
        const candidates = knownYearId == null ? records : records.filter(
          (item) => String(item.tahun_akademik_id) === String(knownYearId),
        )
        const own = candidates.filter((record) => matchesTeacher(record, user, dashboard) === true)
        // Tanpa ID Guru, hanya terima record tunggal dari endpoint yang dibatasi Laravel.
        const chosen = own[0] ?? (
          candidates.length === 1 && matchesTeacher(candidates[0], user, dashboard) !== false
            ? candidates[0]
            : null
        )
        let info = classDetails(chosen)

        const classId = chosen?.kelas_id ?? dashboard?.wali_kelas?.kelas_id
        if (!info && classId) {
          // Pengambilan detail kelas read-only. Kegagalan akses tidak memblokir dashboard.
          try {
            const classResponse = await classApi.getById(classId)
            const classObject = classResponse?.data ?? classResponse
            info = classDetails({ kelas: classObject })
          } catch {
            // Nama tidak ditebak dari ID kelas.
          }
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

export default function GuruDashboard() {
  const { user, assignments = [] } = useAuth()
  return <GuruDashboardWorkspace key={`${user?.id}:${JSON.stringify(assignments)}`} />
}

function GuruDashboardWorkspace() {
  const { user, permissions = [], assignments = [] } = useAuth()
  const { dashboard, loading, error } = useDashboard()
  const isHomeroomTeacher = dashboard?.is_wali_kelas === true && assignments.some(a=>a.type==='WALI_KELAS')
  const homeroom = useHomeroomDetails({ dashboard, user, isHomeroomTeacher })

  if (loading) return <LoadingState message="Memuat dashboard Guru..." />
  if (error) return <ErrorState message={error.message || 'Dashboard Guru tidak dapat dimuat.'} />
  if (!dashboard) return <ErrorState message="Data dashboard Guru tidak tersedia." />

  const summary = dashboard.summary ?? {}
  const name = user?.name || user?.username || dashboard?.user?.name || 'Guru'
  const todayCount = Number(summary.today_schedule_count ?? 0)
  const canViewSchedule = permissions.includes('schedules.view')
  const quickLinks = QUICK_LINKS.filter((link) => permissions.includes(link.permission))
  const label = homeroom.info?.grade != null
    ? `${homeroom.info.name} · Tingkat ${homeroom.info.grade}`
    : homeroom.info?.name

  return (
    <section className="dashboard-page guru-v3">
      <header className="guru-v3__intro-layout">
        <div className="guru-v3__intro">
          <span className="guru-v3__eyebrow"><span /> RUANG KERJA GURU</span>
          <h1>Selamat datang,<br /><strong>{name}</strong></h1>
          <p>Pantau informasi mengajar dan lanjutkan aktivitas akademik Anda dari satu ruang kerja.</p>
          <div className="guru-v3__role-row">
            <span className="guru-v3__role-pill"><i aria-hidden="true" /> Guru</span>
            {isHomeroomTeacher && <span className="guru-v3__role-pill guru-v3__role-pill--blue">Penugasan Wali Kelas aktif</span>}
          </div>
        </div>
        <section className="guru-v3__today" aria-labelledby="guru-v3-today-label">
          <div className="guru-v3__today-head">
            <span id="guru-v3-today-label">JADWAL HARI INI</span><Icon name="calendar" />
          </div>
          <div className="guru-v3__today-number"><strong>{formatCount(summary.today_schedule_count)}</strong><span>jadwal mengajar<br />tercatat hari ini</span></div>
          <div className="guru-v3__today-foot">
            <span>{todayCount > 0 ? 'Lihat agenda mengajar Anda.' : 'Belum ada jadwal mengajar hari ini.'}</span>
            {canViewSchedule && <Link to="/app/schedules">Buka jadwal <Icon name="arrow" /></Link>}
          </div>
        </section>
      </header>

      <section className="guru-v3__summary" aria-labelledby="guru-v3-summary-heading">
        <div className="guru-v3__panel-heading">
          <div><span className="guru-v3__micro">RINGKASAN AKADEMIK</span><h2 id="guru-v3-summary-heading">Aktivitas mengajar</h2></div>
          <p>Data sesuai akses dan catatan SIAKAD.</p>
        </div>
        <div className="guru-v3__metrics">
          {SUMMARY_ITEMS.map((item) => <div className="guru-v3__metric" key={item.key}>
            <strong>{formatCount(summary[item.key])}</strong>
            <span>{item.label}</span>
          </div>)}
        </div>
      </section>

      <div className="guru-v3__bottom">
        <section className="guru-v3__actions" aria-labelledby="guru-v3-quick-heading">
          <div className="guru-v3__panel-heading">
            <div><span className="guru-v3__micro">AKSES CEPAT</span><h2 id="guru-v3-quick-heading">Lanjutkan pekerjaan</h2></div>
            <p>Pilih aktivitas sesuai hak akses.</p>
          </div>
          {quickLinks.length > 0 ? <div className="guru-v3__links">
            {quickLinks.map((item) => <Link className="guru-v3__link" key={item.path} to={item.path}>
              <span className="guru-v3__link-icon"><Icon name={item.icon} /></span>
              <span className="guru-v3__link-copy"><strong>{item.title}</strong><small>{item.description}</small></span>
              <span className="guru-v3__link-arrow"><Icon name="arrow" /></span>
            </Link>)}
          </div> : <p className="guru-v3__empty">Belum ada pintasan yang diizinkan untuk akun ini.</p>}
        </section>

        <aside className="guru-v3__homeroom" aria-labelledby="guru-v3-homeroom-heading">
          <div className="guru-v3__homeroom-head"><span className="guru-v3__micro">KONTEKS WALI KELAS</span><span className={`guru-v3__badge ${isHomeroomTeacher ? 'is-active' : ''}`}>{isHomeroomTeacher ? 'Aktif' : 'Tidak aktif'}</span></div>
          <h2 id="guru-v3-homeroom-heading">Penugasan Wali Kelas</h2>
          <p>Akses tambahan sesuai penugasan Guru yang berlaku.</p>
          <div className="guru-v3__homeroom-info" aria-live="polite">
            <span className="guru-v3__micro">{isHomeroomTeacher ? 'KELAS YANG DIAMPU' : 'STATUS PENUGASAN'}</span>
            <strong>{!isHomeroomTeacher ? 'Belum ada penugasan aktif'
              : homeroom.status === 'loading' || homeroom.status === 'idle' ? 'Memeriksa nama kelas...'
              : label || 'Nama kelas belum diterima dari API'}</strong>
            <p>{isHomeroomTeacher
              ? (homeroom.status === 'ready' ? 'Kelas mengikuti penugasan aktif yang diterima dari SIAKAD.' : 'Status penugasan aktif terdeteksi. Periksa detail kelas bila nama belum muncul.')
              : 'Penugasan Wali Kelas ditentukan berdasarkan data di sistem.'}</p>
          </div>
          {isHomeroomTeacher && <div className="guru-v3__homeroom-foot">
            <span>Wali Kelas adalah penugasan, bukan role baru.</span>
            <Link to="/app/homeroom">Buka Kelas Saya <Icon name="arrow" /></Link>
          </div>}
        </aside>
      </div>
    </section>
  )
}
