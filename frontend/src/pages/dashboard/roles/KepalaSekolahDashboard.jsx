import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { dashboardApi } from '../../../services/dashboardApi'
import { principalAttendanceApi } from '../../../services/principalAttendanceApi'
import { principalGradeApi } from '../../../services/principalGradeApi'
import { formatPrincipalGrade, normalizePrincipalGradeRecap, principalGradeBarWidth } from './principalGradeView'
import {
  attendanceTrendGeometry,
  formatAttendanceRate,
  normalizePrincipalAttendance,
  principalAttendanceMonths,
} from './principalAttendanceView'
import {
  classBarWidth,
  formatPrincipalNumber,
  normalizePrincipalDashboard,
  principalEnrollmentScope,
} from './principalDashboardView'
import './KepalaSekolahDashboard.css'

function VisualIcon({ name, size = 20 }) {
  const icons = {
    guru: <><circle cx="9" cy="8" r="3" /><path d="M3.5 20v-2a5.5 5.5 0 0 1 11 0v2" /><path d="M16.5 5a3 3 0 0 1 0 6M17 14a4.5 4.5 0 0 1 3.5 4.5V20" /></>,
    siswa: <><path d="m2 9 10-5 10 5-10 5-10-5Z" /><path d="M6 11.5V16c4 3.1 8 3.1 12 0v-4.5M22 9v6" /></>,
    kelas: <><rect x="3" y="4" width="18" height="17" rx="2" /><path d="M8 4v17M3 10h18M12 15h5" /></>,
    jadwal: <><rect x="3" y="5" width="18" height="16" rx="2" /><path d="M7 3v4M17 3v4M3 10h18M8 15h3M8 18h5" /></>,
    presensi: <><path d="M4 19V5M4 19h16" /><path d="m8 15 4-5 3 3 5-7" /><path d="M17 6h3v3" /></>,
    nilai: <><path d="M4 20h16M7 17V9M12 17V5M17 17v-6" /><circle cx="7" cy="7" r="1" /></>,
    laporan: <><path d="M7 3h8l4 4v14H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z" /><path d="M15 3v5h5M9 12h7M9 16h7" /></>,
    arrow: <><path d="M5 12h14M13 6l6 6-6 6" /></>,
    refresh: <><path d="M20 11a8 8 0 1 0-2.5 6" /><path d="M20 4v7h-7" /></>,
    shield: <><path d="m12 2 8 4v6c0 5-3.4 8.5-8 10-4.6-1.5-8-5-8-10V6l8-4Z" /><path d="m8.5 12 2.5 2.5 4.5-5" /></>,
  }

  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      {icons[name] ?? icons.laporan}
    </svg>
  )
}

const shortcuts = [
  { title: 'Monitoring Presensi', description: 'Periksa rekap kehadiran dan keterisian catatan.', path: '/app/attendance', icon: 'presensi' },
  { title: 'Monitoring Penilaian', description: 'Tinjau perkembangan penilaian akademik.', path: '/app/grades', icon: 'nilai' },
  { title: 'Jadwal Pembelajaran', description: 'Lihat distribusi jadwal dan kegiatan kelas.', path: '/app/schedules', icon: 'jadwal' },
  { title: 'Laporan Sekolah', description: 'Buka laporan resmi sesuai akses Kepala Sekolah.', path: '/app/reports', icon: 'laporan' },
]

function LoadingDashboard() {
  return (
    <main className="principal-v1" aria-busy="true">
      <div className="principal-v1__loading" role="status" aria-live="polite">
        <span className="principal-v1__loader" aria-hidden="true" />
        <div>
          <strong>Menyiapkan ikhtisar sekolah</strong>
          <p>Mengambil data akademik yang tersedia...</p>
        </div>
      </div>
      <div className="principal-v1__skeleton-grid" aria-hidden="true">
        {[1, 2, 3, 4].map((item) => <div key={item} />)}
      </div>
    </main>
  )
}

function DashboardError({ error, onRetry }) {
  return (
    <main className="principal-v1">
      <div className="principal-v1__feedback" role="alert">
        <span className="principal-v1__feedback-icon"><VisualIcon name="refresh" /></span>
        <h1>Ikhtisar belum dapat ditampilkan</h1>
        <p>{error || 'Data monitoring sekolah belum tersedia. Silakan coba kembali.'}</p>
        <button className="principal-v1__retry" type="button" onClick={onRetry}>
          <VisualIcon name="refresh" size={17} /> Coba lagi
        </button>
      </div>
    </main>
  )
}

function MetricCard({ metric }) {
  return (
    <article className="principal-v1__metric">
      <div className="principal-v1__metric-top">
        <span className={`principal-v1__metric-icon principal-v1__metric-icon--${metric.key}`}>
          <VisualIcon name={metric.key} />
        </span>
        <span className="principal-v1__metric-label">{metric.label}</span>
      </div>
      <strong className="principal-v1__metric-value">{formatPrincipalNumber(metric.value)}</strong>
      <p>{metric.value === null ? 'Data belum tersedia' : metric.note}</p>
    </article>
  )
}

function DistributionPanel({ classes, studentMetric }) {
  const displayedClasses = classes.slice(0, 8)
  const maxCount = displayedClasses[0]?.count ?? 0
  const scope = principalEnrollmentScope(displayedClasses, studentMetric)

  return (
    <section className="principal-v1__panel" aria-labelledby="principal-v1-class-title">
      <div className="principal-v1__panel-head">
        <div>
          <span className="principal-v1__eyebrow">DISTRIBUSI AKADEMIK</span>
          <h2 id="principal-v1-class-title">Sebaran anggota kelas aktif</h2>
          <p>Jumlah anggota dengan keanggotaan yang belum diakhiri, sesuai data kelas dari API.</p>
        </div>
        {classes.length > 0 && <span className="principal-v1__panel-count">{classes.length} kelas</span>}
      </div>
      {classes.length === 0 ? (
        <div className="principal-v1__empty">
          <span className="principal-v1__empty-symbol" aria-hidden="true">—</span>
          <strong>Belum ada distribusi kelas</strong>
          <p>Data anggota kelas akan tampil setelah tersedia pada sistem.</p>
        </div>
      ) : (
        <>
          <div className="principal-v1__bar-list">
            {displayedClasses.map((item) => (
              <div className="principal-v1__bar-row" key={item.id}>
                <span className="principal-v1__bar-name" title={item.name}>{item.name}</span>
                <div className="principal-v1__bar-track" role="img" aria-label={`${item.name}: ${formatPrincipalNumber(item.count)} anggota kelas aktif`}>
                  <div className="principal-v1__bar-fill" style={{ width: `${classBarWidth(item.count, maxCount)}%` }} />
                </div>
                <strong className="principal-v1__bar-count">{formatPrincipalNumber(item.count)}</strong>
              </div>
            ))}
          </div>
          {scope && (
            <div className="principal-v1__enrollment-scope" role="note" aria-label="Perbandingan satuan data profil siswa dan anggota kelas">
              <div>
                <span>Profil siswa terdaftar</span>
                <strong>{formatPrincipalNumber(scope.registeredProfiles)}</strong>
              </div>
              <div>
                <span>Keanggotaan di {scope.displayedClasses} kelas pada grafik</span>
                <strong>{formatPrincipalNumber(scope.memberships)}</strong>
              </div>
            </div>
          )}
          <p className="principal-v1__footnote">
            {classes.length > 8 ? `Menampilkan 8 dari ${classes.length} kelas dengan anggota terbanyak.` : 'Seluruh kelas yang tersedia ditampilkan.'}
            {' '}Jumlah profil siswa dan keanggotaan kelas berasal dari unit hitung berbeda.
            Selisih kedua angka bukan bukti jumlah siswa tanpa kelas; pemeriksaan penempatan memerlukan data anggota kelas berdasarkan periode.
          </p>
        </>
      )}
    </section>
  )
}

const attendanceStatuses = [
  { key: 'hadir', label: 'Hadir', color: '#168d83' },
  { key: 'izin', label: 'Izin', color: '#6489c3' },
  { key: 'sakit', label: 'Sakit', color: '#efb75e' },
  { key: 'alpa', label: 'Alpa', color: '#da7477' },
]

function AttendanceFeedback({ kind, onRetry }) {
  if (kind === 'loading') {
    return <div className="principal-v1__analytics-state" role="status" aria-live="polite"><span className="principal-v1__loader" aria-hidden="true" /> Memuat catatan presensi sekolah...</div>
  }
  if (kind === 'no-year') {
    return <div className="principal-v1__analytics-state">Tahun akademik aktif belum tersedia. Grafik presensi belum dapat ditampilkan.</div>
  }
  if (kind === 'error') {
    return (
      <div className="principal-v1__analytics-state principal-v1__analytics-state--error" role="alert">
        <strong>Rekap presensi gagal dimuat.</strong>
        <p>Data lama tidak ditampilkan agar tidak menyesatkan. Periksa koneksi dan coba kembali.</p>
        <button type="button" className="principal-v1__retry" onClick={onRetry}>Coba lagi</button>
      </div>
    )
  }
  return <div className="principal-v1__analytics-state">Belum ada catatan presensi tersimpan pada enam bulan ini. Tidak ada persentase yang dihitung.</div>
}

function AttendanceComposition({ attendance, status, onRetry }) {
  const totals = attendance?.totals
  const available = status === 'ready' && totals?.total > 0
  let cumulative = 0
  const stops = available ? attendanceStatuses.map((item) => {
    const start = cumulative
    cumulative += (totals[item.key] / totals.total) * 100
    return `${item.color} ${start}% ${cumulative}%`
  }).join(', ') : ''

  return (
    <section className="principal-v1__panel principal-v1__attendance-panel" aria-labelledby="principal-attendance-title">
      <div className="principal-v1__panel-head">
        <div>
          <span className="principal-v1__eyebrow">KOMPOSISI PRESENSI</span>
          <h2 id="principal-attendance-title">Status yang tercatat</h2>
          <p>Akumulasi catatan per pertemuan, bukan rekap siswa unik atau tingkat keterisian presensi.</p>
        </div>
      </div>
      {!available ? <AttendanceFeedback kind={status} onRetry={onRetry} /> : (
        <>
          <div className="principal-v1__donut-wrap">
            <div className="principal-v1__donut" style={{ background: `conic-gradient(${stops})` }} role="img" aria-label={attendanceStatuses.map((item) => `${item.label}: ${totals[item.key]} catatan`).join(', ')}>
              <div className="principal-v1__donut-hole">
                <strong>{formatAttendanceRate(totals.rate)}</strong>
                <span>Hadir dari catatan</span>
              </div>
            </div>
            <div className="principal-v1__donut-legend">
              {attendanceStatuses.map((item) => (
                <div className="principal-v1__legend-row" key={item.key}>
                  <span className="principal-v1__legend-color" style={{ background: item.color }} aria-hidden="true" />
                  <span>{item.label}</span>
                  <strong>{new Intl.NumberFormat('id-ID').format(totals[item.key])}</strong>
                </div>
              ))}
            </div>
          </div>
          <p className="principal-v1__analytics-footnote">Dasar perhitungan: {new Intl.NumberFormat('id-ID').format(totals.total)} catatan presensi yang tersimpan selama enam bulan terakhir.</p>
        </>
      )}
    </section>
  )
}

function AttendanceTrend({ attendance, status, onRetry }) {
  const available = status === 'ready' && attendance?.totals?.total > 0
  const geometry = available ? attendanceTrendGeometry(attendance.months) : { points: [], segments: [] }

  return (
    <section className="principal-v1__panel principal-v1__attendance-panel" aria-labelledby="principal-trend-title">
      <div className="principal-v1__panel-head">
        <div>
          <span className="principal-v1__eyebrow">TREN PRESENSI</span>
          <h2 id="principal-trend-title">Persentase hadir per bulan</h2>
          <p>Proporsi status Hadir dari seluruh catatan yang tersimpan pada bulan tersebut.</p>
        </div>
        {available && <span className="principal-v1__panel-count">6 bulan</span>}
      </div>
      {!available ? <AttendanceFeedback kind={status} onRetry={onRetry} /> : (
        <>
          <div className="principal-v1__trend-wrap" role="img" aria-label={attendance.months.map((month) => `${month.label}: ${formatAttendanceRate(month.rate)} dari ${month.total} catatan`).join('; ')}>
            <div className="principal-v1__trend-axis" aria-hidden="true"><span>100%</span><span>50%</span><span>0%</span></div>
            <svg viewBox="0 0 620 170" preserveAspectRatio="none" aria-hidden="true" focusable="false">
              {[27, 83, 139].map((y) => <line key={y} x1="17" x2="601" y1={y} y2={y} className="principal-v1__trend-gridline" />)}
              {geometry.segments.map((segment, index) => <polyline key={index} points={segment.map((point) => `${point.x},${point.y}`).join(' ')} className="principal-v1__trend-line" />)}
              {geometry.points.filter((point) => point.y !== null).map((point) => (
                <circle key={point.key} cx={point.x} cy={point.y} r="6" className="principal-v1__trend-point">
                  <title>{point.label}: {formatAttendanceRate(point.rate)}, {point.total} catatan</title>
                </circle>
              ))}
            </svg>
          </div>
          <div className="principal-v1__trend-labels" aria-hidden="true">{attendance.months.map((month) => <span key={month.key}>{month.label}</span>)}</div>
          {attendance.monthsWithRecords < 2 && (
            <p className="principal-v1__trend-insight" role="status">
              Belum cukup data untuk membandingkan tren antarbulan. Saat ini baru {attendance.monthsWithRecords} dari 6 bulan yang memiliki catatan presensi.
            </p>
          )}
          <p className="principal-v1__analytics-footnote">Bulan tanpa catatan dibiarkan kosong dan garis tidak disambung melewatinya. Bulan berjalan dihitung sampai hari ini (WIB).</p>
        </>
      )}
    </section>
  )
}

function PrincipalAttendanceAnalytics({ year, state, onRetry }) {
  const canQuery = Number.isSafeInteger(year?.id) && year.id > 0
  const status = !canQuery ? 'no-year' : state.loading ? 'loading' : state.error ? 'error' : state.data?.totals?.total > 0 ? 'ready' : 'empty'
  return (
    <section className="principal-v1__section" aria-labelledby="principal-analytics-heading">
      <div className="principal-v1__section-heading">
        <div>
          <span className="principal-v1__eyebrow">ANALITIK AKADEMIK</span>
          <h2 id="principal-analytics-heading">Kondisi presensi sekolah</h2>
          <p>Data tercatat di tahun akademik aktif · {year?.label ?? 'Periode belum ditentukan'} · enam bulan terakhir dalam WIB.</p>
        </div>
        {canQuery && <button className="principal-v1__analytics-refresh" type="button" onClick={onRetry} disabled={state.loading}>
          <VisualIcon name="refresh" size={16} /> Perbarui rekap
        </button>}
      </div>
      <div className="principal-v1__analytics-grid">
        <AttendanceComposition attendance={state.data} status={status} onRetry={onRetry} />
        <AttendanceTrend attendance={state.data} status={status} onRetry={onRetry} />
      </div>
    </section>
  )
}


function PrincipalGradeAnalytics({ year, state, onRetry }) {
  const canQuery = Number.isSafeInteger(year?.id) && year.id > 0
  const status = !canQuery ? 'no-year' : state.loading ? 'loading' : state.error ? 'error' : state.data?.total > 0 ? 'ready' : 'empty'
  const summary = status === 'ready' ? state.data : null

  const scoreBars = summary ? [
    { label: 'Skor terendah', value: summary.lowest, key: 'lowest' },
    { label: 'Rata-rata komponen', value: summary.average, key: 'average' },
    { label: 'Skor tertinggi', value: summary.highest, key: 'highest' },
  ] : []

  return (
    <section className="principal-v1__section" aria-labelledby="principal-grade-analytics-title">
      <div className="principal-v1__section-heading">
        <div>
          <span className="principal-v1__eyebrow">MONITORING PENILAIAN</span>
          <h2 id="principal-grade-analytics-title">Ringkasan nilai komponen</h2>
          <p>Skor komponen tersimpan untuk {year?.label ?? 'periode aktif'} · tidak mewakili nilai rapor atau kelulusan.</p>
        </div>
        {canQuery && (
          <button className="principal-v1__analytics-refresh" type="button" onClick={onRetry} disabled={state.loading}>
            <VisualIcon name="refresh" size={16} /> Perbarui nilai
          </button>
        )}
      </div>
      <div className="principal-v1__panel principal-v1__grade-panel">
        {status !== 'ready' ? (
          <div className={`principal-v1__analytics-state ${status === 'error' ? 'principal-v1__analytics-state--error' : ''}`} role={status === 'error' ? 'alert' : 'status'}>
            {status === 'loading' && <><span className="principal-v1__loader" aria-hidden="true" /> Memuat ringkasan nilai komponen...</>}
            {status === 'no-year' && 'Tahun akademik aktif belum tersedia. Ringkasan nilai tidak ditampilkan.'}
            {status === 'empty' && 'Belum ada catatan nilai komponen untuk periode aktif. Rata-rata tidak dihitung.'}
            {status === 'error' && <><strong>Ringkasan nilai belum dapat dimuat.</strong><p>Data lama tidak ditampilkan agar tidak menyesatkan.</p><button type="button" className="principal-v1__retry" onClick={onRetry}>Coba lagi</button></>}
          </div>
        ) : (
          <>
            <div className="principal-v1__grade-highlight">
              <span className="principal-v1__eyebrow">RATA-RATA ARITMETIKA</span>
              <div className="principal-v1__grade-average">
                <strong>{formatPrincipalGrade(summary.average)}</strong><span>/ 100</span>
              </div>
              <p>Dari <strong>{new Intl.NumberFormat('id-ID').format(summary.total)}</strong> catatan nilai komponen, bukan jumlah siswa unik.</p>
              <Link className="principal-v1__grade-detail-link" to="/app/grades">Buka monitoring penilaian <VisualIcon name="arrow" size={15} /></Link>
            </div>
            <div className="principal-v1__grade-bars" role="img" aria-label={`Skor terendah ${formatPrincipalGrade(summary.lowest)}, rata-rata ${formatPrincipalGrade(summary.average)}, tertinggi ${formatPrincipalGrade(summary.highest)} dari ${summary.total} catatan komponen`}>
              {scoreBars.map((score) => (
                <div className="principal-v1__grade-row" key={score.key}>
                  <div className="principal-v1__grade-label"><span>{score.label}</span><strong>{formatPrincipalGrade(score.value)}</strong></div>
                  <div className="principal-v1__grade-track">
                    <div className={`principal-v1__grade-fill principal-v1__grade-fill--${score.key}`} style={{ width: `${principalGradeBarWidth(score.value)}%` }} />
                  </div>
                </div>
              ))}
              <div className="principal-v1__grade-scale"><span>0</span><span>50</span><span>100</span></div>
            </div>
          </>
        )}
      </div>
      <p className="principal-v1__grade-disclaimer">Perhitungan memakai skor komponen yang tercatat pada API rekap. Rata-rata tidak diberi bobot; nilai 0 tetap dihitung. Grafik ini tidak mengukur ketuntasan, mutu rapor akhir, ataupun persebaran siswa.</p>
    </section>
  )
}

function PeriodPanel({ year }) {
  const hasPeriod = Boolean(year?.label)

  return (
    <section className="principal-v1__panel principal-v1__period" aria-labelledby="principal-v1-period-title">
      <div className="principal-v1__panel-head">
        <div>
          <span className="principal-v1__eyebrow">KONTEKS AKADEMIK</span>
          <h2 id="principal-v1-period-title">Periode sekolah</h2>
          <p>Hanya tahun ajaran dan semester yang sedang aktif.</p>
        </div>
        {hasPeriod && <span className="principal-v1__year-status">Aktif</span>}
      </div>
      <div className="principal-v1__active-period">
        <span className="principal-v1__period-icon"><VisualIcon name="jadwal" size={22} /></span>
        <div>
          <span className="principal-v1__period-caption">PERIODE AKTIF</span>
          <strong>{year?.label ?? 'Belum ditentukan'}</strong>
          <span>{year?.semester ? `Semester ${year.semester}` : 'Semester belum tersedia'}</span>
        </div>
      </div>
      {!hasPeriod && (
        <p className="principal-v1__period-warning">
          Belum ada tahun akademik aktif. Ringkasan kelas, jadwal, dan distribusi mungkin mencakup beberapa periode.
        </p>
      )}
      <p className="principal-v1__period-helper">
        Informasi mengikuti pengaturan periode akademik sekolah secara otomatis.
        Riwayat dan filter periode tersedia di halaman Laporan.
      </p>
    </section>
  )
}

export default function KepalaSekolahDashboard() {
  const [attempt, setAttempt] = useState(0)
  const [state, setState] = useState({ loading: true, data: null, error: null })
  const [analyticsAttempt, setAnalyticsAttempt] = useState(0)
  const [analytics, setAnalytics] = useState({ loading: false, data: null, error: null })
  const [gradeAttempt, setGradeAttempt] = useState(0)
  const [gradeState, setGradeState] = useState({ loading: false, data: null, error: null })

  useEffect(() => {
    let active = true
    setState({ loading: true, data: null, error: null })

    dashboardApi.getDashboard()
      .then((response) => normalizePrincipalDashboard(response))
      .then((data) => {
        if (active) setState({ loading: false, data, error: null })
      })
      .catch((error) => {
        if (active) setState({ loading: false, data: null, error: error?.message || 'Permintaan data gagal.' })
      })

    return () => { active = false }
  }, [attempt])

  const yearId = state.data?.year?.id
  useEffect(() => {
    let active = true
    if (!Number.isSafeInteger(yearId) || yearId <= 0) {
      setAnalytics({ loading: false, data: null, error: null })
      return () => { active = false }
    }

    setAnalytics({ loading: true, data: null, error: null })
    const windows = principalAttendanceMonths()
    Promise.all(windows.map((month) => principalAttendanceApi.getMonthlyRecap({
      academicYearId: yearId,
      from: month.from,
      to: month.to,
    })))
      .then((responses) => normalizePrincipalAttendance(windows, responses))
      .then((data) => {
        if (active) setAnalytics({ loading: false, data, error: null })
      })
      .catch((error) => {
        if (active) setAnalytics({ loading: false, data: null, error: error?.message || 'Gagal memuat rekap.' })
      })

    return () => { active = false }
  }, [yearId, analyticsAttempt])


  useEffect(() => {
    let active = true
    if (!Number.isSafeInteger(yearId) || yearId <= 0) {
      setGradeState({ loading: false, data: null, error: null })
      return () => { active = false }
    }

    setGradeState({ loading: true, data: null, error: null })
    principalGradeApi.getRecap({ academicYearId: yearId })
      .then(normalizePrincipalGradeRecap)
      .then((data) => {
        if (active) setGradeState({ loading: false, data, error: null })
      })
      .catch((error) => {
        if (active) setGradeState({ loading: false, data: null, error: error?.message || 'Gagal memuat rekap nilai.' })
      })

    return () => { active = false }
  }, [yearId, gradeAttempt])

  if (state.loading) return <LoadingDashboard />
  if (state.error || !state.data) {
    return <DashboardError error={state.error} onRetry={() => setAttempt((value) => value + 1)} />
  }

  const { metrics, classes, year } = state.data

  return (
    <main className="dashboard-page principal-v1">
      <header className="principal-v1__hero">
        <div className="principal-v1__hero-text">
          <span className="principal-v1__hero-kicker"><span aria-hidden="true" /> PUSAT MONITORING AKADEMIK</span>
          <h1>Satu pandangan untuk<br /><em>seluruh kegiatan sekolah.</em></h1>
          <p>Pantau data inti, perkembangan kelas, dan akses laporan dalam satu ruang kerja Kepala Sekolah.</p>
          <div className="principal-v1__hero-actions">
            <Link to="/app/reports" className="principal-v1__primary-link">Buka laporan sekolah <VisualIcon name="arrow" size={17} /></Link>
            <span className="principal-v1__read-only"><VisualIcon name="shield" size={17} /> Mode monitoring · baca saja</span>
          </div>
        </div>
        <div className="principal-v1__hero-visual" aria-hidden="true">
          <div className="principal-v1__orbit principal-v1__orbit--one" />
          <div className="principal-v1__orbit principal-v1__orbit--two" />
          <div className="principal-v1__orbit principal-v1__orbit--three" />
          <div className="principal-v1__hero-mark"><VisualIcon name="kelas" size={48} /></div>
        </div>
      </header>

      <section aria-labelledby="principal-v1-metrics-title" className="principal-v1__section">
        <div className="principal-v1__section-heading">
          <div>
            <span className="principal-v1__eyebrow">OVERVIEW SEKOLAH</span>
            <h2 id="principal-v1-metrics-title">Angka inti akademik</h2>
            <p>Ditampilkan langsung dari data yang terdaftar, tanpa estimasi atau angka contoh.</p>
          </div>
          <span className="principal-v1__period-chip">{year?.label ? `${year.label} · ${year.semester ?? 'Semester —'}` : 'Periode belum aktif'}</span>
        </div>
        <div className="principal-v1__metrics-grid">
          {metrics.map((item) => <MetricCard key={item.key} metric={item} />)}
        </div>
      </section>

      <div className="principal-v1__insights-grid">
        <DistributionPanel classes={classes} studentMetric={metrics.find((item) => item.key === 'siswa')} />
        <PeriodPanel year={year} />
      </div>

      <PrincipalAttendanceAnalytics
        year={year}
        state={analytics}
        onRetry={() => setAnalyticsAttempt((value) => value + 1)}
      />

      <PrincipalGradeAnalytics
        year={year}
        state={gradeState}
        onRetry={() => setGradeAttempt((value) => value + 1)}
      />

      <section className="principal-v1__section" aria-labelledby="principal-v1-monitoring-title">
        <div className="principal-v1__section-heading">
          <div>
            <span className="principal-v1__eyebrow">AKSES MONITORING</span>
            <h2 id="principal-v1-monitoring-title">Telusuri lebih dalam</h2>
            <p>Masuk ke modul yang sudah tersedia sesuai otorisasi Kepala Sekolah.</p>
          </div>
        </div>
        <div className="principal-v1__shortcuts">
          {shortcuts.map((item) => (
            <Link key={item.path} to={item.path} className="principal-v1__shortcut">
              <span className={`principal-v1__shortcut-icon principal-v1__shortcut-icon--${item.icon}`}><VisualIcon name={item.icon} size={21} /></span>
              <strong>{item.title}</strong>
              <p>{item.description}</p>
              <span className="principal-v1__shortcut-arrow"><VisualIcon name="arrow" size={17} /></span>
            </Link>
          ))}
        </div>
      </section>

      <p className="principal-v1__footer-note">Data ringkasan mengikuti otorisasi backend. Pengelolaan data dilakukan melalui peran operasional yang berwenang.</p>
    </main>
  )
}
