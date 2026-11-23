import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { studentDashboardWorkspaceApi } from '../../../services/studentDashboardWorkspaceApi'
import { formatDeadline, formatWibDate, parseStudentDashboard } from './studentDashboardView'
import './SiswaDashboard.css'

const quickLinks = [
  { url: '/app/schedules', label: 'Jadwal', icon: '▦', description: 'Lihat jadwal pelajaran lengkap' },
  { url: '/app/attendance', label: 'Presensi', icon: '✓', description: 'Pantau kehadiran harian' },
  { url: '/app/grades', label: 'Nilai', icon: '▤', description: 'Cek hasil penilaian guru' },
  { url: '/app/materials', label: 'Bahan ajar', icon: '▣', description: 'Akses materi terbaru kelas' },
  { url: '/app/assignments', label: 'Tugas', icon: '◫', description: 'Lihat dan kumpulkan tugas' },
  { url: '/app/report-cards', label: 'Rapor', icon: '▧', description: 'Dokumen rapor resmi' },
]

function StatCard({ tone = 'blue', eyebrow, value, helper, footer }) {
  return (
    <article className={`sd-stat-card sd-tone-${tone}`}>
      <div className="sd-stat-top">
        <span className="sd-stat-eyebrow">{eyebrow}</span>
        <span className="sd-stat-dot" aria-hidden="true" />
      </div>
      <strong>{value}</strong>
      <p>{helper}</p>
      {footer ? <small>{footer}</small> : null}
    </article>
  )
}

export default function SiswaDashboard() {
  const [dashboard, setDashboard] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const generation = useRef(0)

  const reload = useCallback(async () => {
    const current = ++generation.current
    setLoading(true)
    setError('')
    try {
      const payload = await studentDashboardWorkspaceApi.get()
      const parsed = parseStudentDashboard(payload)
      if (current === generation.current) setDashboard(parsed)
    } catch (err) {
      if (current === generation.current) {
        setDashboard(null)
        setError(err?.message || 'Dashboard gagal dimuat.')
      }
    } finally {
      if (current === generation.current) setLoading(false)
    }
  }, [])

  useEffect(() => {
    reload()
    return () => { generation.current += 1 }
  }, [reload])

  const active = dashboard?.placement_status === 'active'
  const tasks = dashboard?.assignment_summary ?? { total: 0, pending: 0, submitted: 0, overdue: 0, late_submitted: 0 }
  const today = dashboard?.today_schedule || []
  const urgent = dashboard?.upcoming_assignments || []
  const subjects = dashboard?.subjects || []
  const materials = dashboard?.recent_materials || []

  const nextClass = today[0] || null

  const completionRate = useMemo(() => {
    if (!tasks.total) return 0
    return Math.round((tasks.submitted / tasks.total) * 100)
  }, [tasks.total, tasks.submitted])

  const focusSubject = useMemo(() => {
    if (!subjects.length) return null
    const sorted = [...subjects].sort((a, b) => (b.pending || 0) - (a.pending || 0) || a.name.localeCompare(b.name))
    return sorted[0]
  }, [subjects])

  return (
    <main className="student-dashboard-v3" aria-busy={loading}>
      <div className="sd-shell">
        <header className="sd-hero-card">
          <div className="sd-hero-main">
            <div className="sd-eyebrow">AKADEMIK / DASHBOARD SISWA</div>
            <h1>Selamat datang{dashboard?.student_name ? `, ${dashboard.student_name}` : ''}.</h1>
            <p className="sd-hero-copy">Pantau jadwal, tugas, materi, dan progres belajarmu dari satu dashboard yang rapi dan mudah dipahami.</p>
            {dashboard ? (
              <div className="sd-hero-chips" aria-label="Ringkasan konteks akademik">
                <span className="sd-chip"><b>Periode</b>{dashboard.academic_year.label}</span>
                <span className="sd-chip"><b>Kelas</b>{dashboard.class?.nama || 'Belum tersedia'}</span>
                <span className="sd-chip"><b>Hari ini</b>{formatWibDate(dashboard.as_of)}</span>
              </div>
            ) : null}
          </div>

          <aside className="sd-hero-side">
            <div className="sd-hero-actions">
              <button className="sd-refresh" type="button" onClick={reload} disabled={loading}>{loading ? 'Memuat…' : '↻ Perbarui data'}</button>
              <Link className="sd-ghost-link" to="/app/schedules">Lihat jadwal</Link>
            </div>
            <div className="sd-spotlight-card">
              <div className="sd-spotlight-head">
                <span className="sd-mini-label">FOKUS HARI INI</span>
                <span className={`sd-status-pill ${active ? 'is-active' : 'is-idle'}`}>{active ? 'Penempatan aktif' : 'Belum aktif'}</span>
              </div>
              {active ? (
                <>
                  <div className="sd-spotlight-title">{nextClass ? nextClass.subject : 'Tidak ada jadwal kelas hari ini'}</div>
                  <p className="sd-spotlight-copy">
                    {nextClass
                      ? `${nextClass.start}–${nextClass.end} WIB · ${nextClass.teacher || 'Guru belum tersedia'}`
                      : 'Gunakan waktu hari ini untuk meninjau materi dan tugas yang sudah tersedia.'}
                  </p>
                  <div className="sd-progress-block">
                    <div className="sd-progress-meta">
                      <span>Progres tugas</span>
                      <strong>{completionRate}%</strong>
                    </div>
                    <div className="sd-progress-bar" aria-hidden="true"><span style={{ width: `${completionRate}%` }} /></div>
                    <small>{tasks.submitted} dari {tasks.total} tugas sudah kamu kumpulkan</small>
                  </div>
                </>
              ) : (
                <p className="sd-spotlight-copy">Jadwal dan tugas akan muncul otomatis setelah penempatan kelasmu aktif.</p>
              )}
            </div>
          </aside>
        </header>

        {error && (
          <div role="alert" className="sd-error">
            <div>
              <strong>Dashboard tidak dapat dimuat.</strong>
              <span>{error}</span>
            </div>
            <button type="button" onClick={reload}>Coba lagi</button>
          </div>
        )}

        {!dashboard && !error && <div className="sd-empty" role="status">Memuat informasi akademik…</div>}

        {dashboard && (
          <>
            {!active ? (
              <section className="sd-empty sd-placement">
                <h2>Penempatan kelas belum aktif</h2>
                <p>{dashboard.placement_status === 'scheduled' ? 'Penempatanmu dijadwalkan mulai pada tanggal mendatang.' : 'Jadwal dan tugas belum dapat ditampilkan. Hubungi Tata Usaha untuk memeriksa penempatan kelas.'}</p>
              </section>
            ) : (
              <>
                <section className="sd-stats-grid" aria-label="Ringkasan utama dashboard">
                  <StatCard
                    tone="blue"
                    eyebrow="Pelajaran hari ini"
                    value={today.length}
                    helper="Sesi kelas yang terjadwal"
                    footer={nextClass ? `${nextClass.subject} · ${nextClass.start}` : 'Tidak ada sesi terjadwal'}
                  />
                  <StatCard
                    tone="amber"
                    eyebrow="Tugas aktif"
                    value={tasks.pending}
                    helper={tasks.overdue ? `${tasks.overdue} tugas melewati tenggat` : 'Tugas belum dikumpulkan'}
                    footer={focusSubject ? `${focusSubject.name} menjadi fokus saat ini` : 'Belum ada fokus mata pelajaran'}
                  />
                  <StatCard
                    tone="green"
                    eyebrow="Sudah dikumpulkan"
                    value={tasks.submitted}
                    helper="Tugas yang telah berhasil dikirim"
                    footer={tasks.late_submitted ? `${tasks.late_submitted} dikumpulkan terlambat` : 'Belum ada pengumpulan terlambat'}
                  />
                  <StatCard
                    tone="violet"
                    eyebrow="Materi terbaru"
                    value={materials.length}
                    helper="Materi yang siap dipelajari"
                    footer={materials[0] ? `${materials[0].subject} · ${materials[0].title}` : 'Belum ada materi terbaru'}
                  />
                </section>

                <section className="sd-grid sd-grid-primary">
                  <article className="sd-panel">
                    <div className="sd-panel-head">
                      <div>
                        <span className="sd-panel-kicker">HARI INI</span>
                        <h2>Jadwal belajar</h2>
                        <p>{dashboard.today_name} · Mengikuti jadwal kelas resmi</p>
                      </div>
                      <Link to="/app/schedules">Lihat jadwal →</Link>
                    </div>
                    {today.length ? (
                      <div className="sd-schedule-list">
                        {today.map((row, index) => (
                          <div key={row.id} className={`sd-schedule-item ${index === 0 ? 'is-highlight' : ''}`}>
                            <div className="sd-schedule-time">
                              <strong>{row.start}</strong>
                              <span>{row.end} WIB</span>
                            </div>
                            <div className="sd-schedule-content">
                              <strong>{row.subject}</strong>
                              <span>{row.teacher || 'Guru belum tersedia'}</span>
                            </div>
                            <span className="sd-schedule-badge">{index === 0 ? 'Sesi berikutnya' : 'Terjadwal'}</span>
                          </div>
                        ))}
                      </div>
                    ) : <div className="sd-inline-empty">Tidak ada jadwal rutin pada hari ini. Periksa halaman Jadwal untuk informasi lengkap.</div>}
                  </article>

                  <article className="sd-panel">
                    <div className="sd-panel-head">
                      <div>
                        <span className="sd-panel-kicker">PRIORITAS</span>
                        <h2>Tugas terdekat</h2>
                        <p>Belum dikumpulkan · diurutkan dari yang paling penting</p>
                      </div>
                      <Link to="/app/assignments">Semua tugas →</Link>
                    </div>
                    {urgent.length ? (
                      <div className="sd-task-stack">
                        {urgent.map(task => (
                          <Link to="/app/assignments" className="sd-task-card" key={task.id}>
                            <div className="sd-task-main">
                              <div>
                                <strong>{task.title}</strong>
                                <span>{task.subject}</span>
                              </div>
                              <span className={`sd-task-badge ${task.status === 'overdue' ? 'is-overdue' : 'is-pending'}`}>
                                {task.status === 'overdue' ? 'Tenggat lewat' : 'Belum dikirim'}
                              </span>
                            </div>
                            <small>⏱ {formatDeadline(task.deadline)}</small>
                          </Link>
                        ))}
                      </div>
                    ) : <div className="sd-inline-empty">Tidak ada tugas yang perlu dikumpulkan saat ini.</div>}
                  </article>
                </section>

                <section className="sd-grid sd-grid-secondary">
                  <article className="sd-panel">
                    <div className="sd-panel-head">
                      <div>
                        <span className="sd-panel-kicker">MAPEL</span>
                        <h2>Tugas per mata pelajaran</h2>
                        <p>Ringkasan tugas berdasarkan mata pelajaran di kelasmu</p>
                      </div>
                      <Link to="/app/assignments">Buka tugas →</Link>
                    </div>
                    {subjects.length ? (
                      <div className="sd-subject-list">
                        {subjects.map(subject => (
                          <Link to="/app/assignments" key={subject.id} className="sd-subject-row">
                            <div className="sd-subject-main">
                              <div className="sd-subject-icon">◫</div>
                              <div>
                                <strong>{subject.name}</strong>
                                <span>{subject.teachers.join(' · ') || 'Guru belum tersedia'}</span>
                              </div>
                            </div>
                            <div className="sd-subject-meta">
                              <strong>{subject.pending}</strong>
                              <small>{subject.pending ? 'perlu dikerjakan' : 'aman'}</small>
                            </div>
                          </Link>
                        ))}
                      </div>
                    ) : <div className="sd-inline-empty">Belum ada mata pelajaran terjadwal.</div>}
                  </article>

                  <article className="sd-panel">
                    <div className="sd-panel-head">
                      <div>
                        <span className="sd-panel-kicker">MATERI</span>
                        <h2>Bahan ajar terbaru</h2>
                        <p>Materi terbaru yang dapat kamu buka kembali kapan saja</p>
                      </div>
                      <Link to="/app/materials">Lihat materi →</Link>
                    </div>
                    {materials.length ? (
                      <div className="sd-material-list">
                        {materials.map(material => (
                          <Link to="/app/materials" key={material.id} className="sd-material-row">
                            <div className="sd-material-icon">▤</div>
                            <div className="sd-material-main">
                              <strong>{material.title}</strong>
                              <span>{material.subject}</span>
                            </div>
                            <span className="sd-arrow">›</span>
                          </Link>
                        ))}
                      </div>
                    ) : <div className="sd-inline-empty">Belum ada materi yang tersedia untuk ditampilkan.</div>}
                  </article>
                </section>

                <section className="sd-panel sd-quick-panel">
                  <div className="sd-panel-head no-border">
                    <div>
                      <span className="sd-panel-kicker">AKSES CEPAT</span>
                      <h2>Modul akademik utama</h2>
                      <p>Pindah ke fitur penting dengan lebih cepat tanpa harus membuka menu satu per satu.</p>
                    </div>
                  </div>
                  <div className="sd-quick-grid">
                    {quickLinks.map(link => (
                      <Link to={link.url} key={link.url} className="sd-quick-card">
                        <span className="sd-quick-icon" aria-hidden="true">{link.icon}</span>
                        <strong>{link.label}</strong>
                        <small>{link.description}</small>
                        <span className="sd-quick-arrow" aria-hidden="true">↗</span>
                      </Link>
                    ))}
                  </div>
                </section>
              </>
            )}

            <p className="sd-disclaimer">Informasi mengikuti periode aktif, penempatan kelas, dan data resmi SIAKAD. Dashboard ini menonjolkan informasi penting agar siswa cepat memahami apa yang harus diperiksa hari ini.</p>
          </>
        )}
      </div>
    </main>
  )
}
