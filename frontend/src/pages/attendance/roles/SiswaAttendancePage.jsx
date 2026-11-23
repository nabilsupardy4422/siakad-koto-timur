import { useEffect, useMemo, useState } from 'react'
import { attendanceApi } from '../../../services/attendanceApi'
import './SiswaAttendancePageV2.css'
import { timeText, initials, teacherNames, getSummary, periodText, textStatus, totalSummary, presentPercentage, isInvalidDateRange } from './studentAttendanceView.js'

const STATUS = { HADIR: 'Hadir', IZIN: 'Izin', SAKIT: 'Sakit', ALPA: 'Alpa' }
const STAT_META = [['HADIR', 'Hadir'], ['IZIN', 'Izin'], ['SAKIT', 'Sakit'], ['ALPA', 'Alpa']]
const formatDate = (date) => date ? new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${date}T00:00:00Z`)) : '—'

function Icon({ name, size = 19, stroke = 1.8 }) {
  const path = {
    refresh: <><path d="M20 7v5h-5"/><path d="M4 17v-5h5"/><path d="M5.7 9A7 7 0 0 1 18 7l2 5M4 12l2 5a7 7 0 0 0 12.3-2"/></>,
    calendar: <><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 10h18"/></>,
    book: <><path d="M12 6c-3-2-6-2-9-1v14c3-1 6-1 9 1 3-2 6-2 9-1V5c-3-1-6-1-9 1ZM12 6v14"/></>,
    check: <><circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/></>,
    clock: <><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></>,
    heart: <><path d="M20.8 9.5c0 5-8.8 10.3-8.8 10.3S3.2 14.5 3.2 9.5a4.5 4.5 0 0 1 8.8-1.3 4.5 4.5 0 0 1 8.8 1.3Z"/></>,
    alert: <><circle cx="12" cy="12" r="9"/><path d="M12 7v6M12 17h.01"/></>,
    arrow: <><path d="M5 12h14m-6-6 6 6-6 6"/></>,
    back: <><path d="M19 12H5m6 6-6-6 6-6"/></>,
    filter: <><path d="M4 7h16M7 12h10M10 17h4"/></>,
    user: <><circle cx="12" cy="8" r="3"/><path d="M5.5 20a6.5 6.5 0 0 1 13 0"/></>,
    shield: <><path d="m12 2 8 4v6c0 5-3.5 8-8 10-4.5-2-8-5-8-10V6l8-4Z"/><path d="m9 12 2 2 4-4"/></>,
    info: <><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></>,
    chevron: <><path d="m9 6 6 6-6 6"/></>,
    sparkle: <><path d="m12 2 2.2 7.8L22 12l-7.8 2.2L12 22l-2.2-7.8L2 12l7.8-2.2L12 2Z"/></>,
  }[name] || <circle cx="12" cy="12" r="8"/>
  return <svg aria-hidden="true" width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={stroke} strokeLinecap="round" strokeLinejoin="round">{path}</svg>
}

export default function SiswaAttendancePage() {
  const [subjectId, setSubjectId] = useState(null)
  const [page, setPage] = useState(1)
  const [fromDraft, setFromDraft] = useState('')
  const [toDraft, setToDraft] = useState('')
  const [dates, setDates] = useState({})
  const [result, setResult] = useState(null)
  const [busy, setBusy] = useState(true)
  const [error, setError] = useState('')
  const [retry, setRetry] = useState(0)

  useEffect(() => {
    let current = true
    setBusy(true)
    setError('')
    attendanceApi.getStudentWorkspace({ subject_id: subjectId, page, per_page: 10, ...dates })
      .then(response => {
        if (!current) return
        if (!response?.data || !Array.isArray(response.data.subjects) || !Array.isArray(response.data.history)) {
          throw new Error('Format data presensi tidak sesuai. Silakan muat ulang halaman.')
        }
        setResult(response)
      })
      .catch(err => { if (current) { setError(err?.payload?.message || err?.message || 'Gagal memuat presensi.') } })
      .finally(() => { if (current) setBusy(false) })
    return () => { current = false }
  }, [subjectId, page, dates, retry])

  const subjects = result?.data?.subjects || []
  const chosen = subjects.find(subject => String(subject.id) === String(subjectId))
  const ctx = result?.data?.context
  const meta = result?.meta
  const totals = useMemo(() => totalSummary(subjects), [subjects])
  const attendanceRatio = presentPercentage(totals)
  const invalidRange = isInvalidDateRange(fromDraft, toDraft)
  const [viewMode, setViewMode] = useState('subjects')
  const showDetails = viewMode === 'details' && Boolean(chosen)

  const applyFilters = (event) => {
    event.preventDefault()
    if (invalidRange) return
    setPage(1)
    setDates({ date_from: fromDraft || undefined, date_to: toDraft || undefined })
  }
  const resetFilters = () => { setFromDraft(''); setToDraft(''); setPage(1); setDates({}) }

  return <main className="sa-premium">
    <div className="sa-premium-pagehead">
      <div className="sa-premium-heading">
        <span className="sa-premium-kicker">AKADEMIK / PRESENSI SISWA</span>
        <h1>Presensi saya</h1>
        <p>Lihat rekap dan riwayat kehadiran untuk setiap mata pelajaran.</p>
      </div>
      <button type="button" className="sa-premium-reload" disabled={busy} onClick={() => setRetry(n => n + 1)}><Icon name="refresh" size={16}/> Perbarui data</button>
    </div>

    {error && <div role="alert" className="sa-premium-alert"><Icon name="alert"/><span>{error}</span><button type="button" onClick={() => setRetry(n => n + 1)}>Coba lagi</button></div>}
    {busy && !result && <div className="sa-premium-skeleton" role="status" aria-live="polite">Memuat data presensi…</div>}
    {busy && result && <div className="sa-premium-updating" role="status" aria-live="polite"><span className="sa-premium-spin"/> Memperbarui data…</div>}

    {ctx && <>
      <section className="sa-premium-overview" aria-label="Konteks akademik">
        <div className="sa-premium-overview-main">
          <span className="sa-premium-overline">TAHUN AKADEMIK</span>
          <strong>{periodText(ctx.academic_year)}</strong>
        </div>
        <div className="sa-premium-overview-main">
          <span className="sa-premium-overline">KELAS SAYA</span>
          <strong>{ctx.class?.nama || 'Belum ditempatkan'}</strong>
        </div>
        <div className="sa-premium-overview-main">
          <span className="sa-premium-overline">STATUS PENEMPATAN</span>
          <strong className="sa-premium-placement"><span className="sa-premium-placement-dot"/>{textStatus(ctx.status)}</strong>
        </div>
      </section>
      {ctx.status !== 'active' ? <section className="sa-premium-empty"><div className="sa-premium-empty-icon"><Icon name="calendar" size={26}/></div><h2>Belum ada presensi kelas aktif</h2><p>Riwayat presensi tersedia ketika penempatan kelasmu sudah berlaku. Hubungi Tata Usaha jika datanya belum sesuai.</p></section> : <>
        <section className="sa-premium-metrics" aria-label="Ringkasan presensi">
          <div className="sa-premium-metric">
            <span className="sa-premium-metric-label">Catatan presensi</span>
            <div className="sa-premium-metric-value">{totals.total.toLocaleString('id-ID')}</div>
            <div className="sa-premium-metric-bottom">Dari {subjects.length} mata pelajaran</div>
          </div>
          <div className="sa-premium-metric">
            <span className="sa-premium-metric-label">Kehadiran tercatat</span>
            <div className="sa-premium-metric-value">{attendanceRatio === null ? '—' : `${attendanceRatio}%`}</div>
            <div className="sa-premium-metric-bottom">{attendanceRatio === null ? 'Belum ada catatan untuk dihitung' : `${totals.HADIR} dari ${totals.total} catatan`}</div>
          </div>
          {STAT_META.map(([key, caption]) => <div className={`sa-premium-metric sa-premium-metric-${key.toLowerCase()}`} key={key}>
            <span className="sa-premium-metric-label">{caption}</span>
            <div className="sa-premium-metric-value">{totals[key]}</div>
          </div>)}
        </section>
        <section className="sa-premium-toolbar" aria-label="Filter tanggal">
          <div className="sa-premium-toolbar-heading"><strong>Filter riwayat</strong><span>Opsional · berdasarkan tanggal catatan</span></div>
          <form className="sa-premium-filter" onSubmit={applyFilters}>
            <label>Dari<input aria-label="Dari tanggal" type="date" value={fromDraft} onChange={event => setFromDraft(event.target.value)}/></label>
            <label>Sampai<input aria-label="Sampai tanggal" type="date" value={toDraft} onChange={event => setToDraft(event.target.value)}/></label>
            <button className="sa-premium-apply" disabled={invalidRange || busy} type="submit">Terapkan <Icon name="arrow" size={15}/></button>
            <button className="sa-premium-reset" type="button" onClick={resetFilters}>Reset</button>
          </form>
          {invalidRange && <p role="alert" className="sa-premium-validation">Tanggal awal tidak boleh melebihi tanggal akhir.</p>}
        </section>

        {!showDetails ? <>
          <div className="sa-premium-sectionhead"><div><h2>Mata pelajaran</h2><p>Pilih mapel untuk melihat rincian presensimu.</p></div><span className="sa-premium-section-count">{subjects.length} mata pelajaran</span></div>
          {subjects.length ? <div className="sa-premium-subject-grid">{subjects.map((subject, index) => <button className="sa-premium-subject" type="button" key={subject.id} onClick={() => { setSubjectId(subject.id); setViewMode('details'); setPage(1) }}>
            <div className="sa-premium-subject-top"><span className={`sa-premium-monogram sa-premium-tone-${index%4}`}><Icon name="book" size={19}/></span><span className="sa-premium-card-arrow"><Icon name="chevron" size={18}/></span></div>
            <div className="sa-premium-subject-name"><h3>{subject.name}</h3><p><Icon name="user" size={14}/>{teacherNames(subject).join(' · ') || 'Guru belum tersedia'}</p></div>
            <div className="sa-premium-subject-stats"><span>{subject.schedules?.length || 0} jadwal</span><span>{getSummary(subject).total || 0} catatan</span><span>{getSummary(subject).HADIR || 0} hadir</span></div>
            <div className="sa-premium-subject-bottom"><span>Lihat riwayat presensi</span><Icon name="chevron" size={16}/></div>
          </button>)}</div> : <div className="sa-premium-empty"><div className="sa-premium-empty-icon"><Icon name="book" size={24}/></div><h2>Belum ada mata pelajaran</h2><p>Jadwal kelas aktif belum memiliki mata pelajaran yang dapat ditampilkan.</p></div>}
        </> : <>
          <button className="sa-premium-return" type="button" onClick={() => { setViewMode('subjects'); setSubjectId(null); setPage(1) }}><Icon name="back" size={16}/> Semua mata pelajaran</button>
          <div className="sa-premium-sectionhead sa-premium-detail-head"><div><span className="sa-premium-section-eyebrow">RIWAYAT MATA PELAJARAN</span><h2>{chosen.name}</h2><p>{getSummary(chosen).total || 0} catatan presensi dalam periode terpilih</p></div><span className="sa-premium-section-count"><Icon name="calendar" size={15}/>{chosen.schedules.length} jadwal rutin</span></div>
          <div className="sa-premium-detail-layout">
            <section className="sa-premium-panel sa-premium-teacher-panel"><div className="sa-premium-panel-head"><div><h3>Jadwal dan guru pengampu</h3></div><span className="sa-premium-panel-icon"><Icon name="calendar" size={20}/></span></div>
              <div className="sa-premium-teacher-list">{chosen.schedules.map(schedule => <div className="sa-premium-teacher" key={schedule.id}><span className="sa-premium-teacher-avatar">{initials(schedule.teacher)}</span><div className="sa-premium-teacher-main"><strong>{schedule.teacher || 'Guru belum tersedia'}</strong><small>{schedule.day}</small></div><span className="sa-premium-teacher-time">{timeText(schedule.start)}–{timeText(schedule.end)}</span></div>)}</div>
              <div className="sa-premium-teacher-hint"><Icon name="info" size={15}/> Jadwal berasal dari data resmi kelasmu.</div>
            </section>
            <section className="sa-premium-panel sa-premium-history-panel"><div className="sa-premium-panel-head"><div><h3>Riwayat kehadiran</h3></div><span className="sa-premium-history-count">{meta?.total ?? getSummary(chosen).total ?? 0} catatan</span></div>
              {result.data.history.length ? <div className="sa-premium-table-wrap"><table><thead><tr><th>Tanggal</th><th>Guru pengampu</th><th>Jam</th><th>Status</th><th>Catatan</th></tr></thead><tbody>{result.data.history.map(row => <tr key={row.id}><td>{formatDate(row.date)}</td><td>{row.teacher}</td><td>{timeText(row.start)}–{timeText(row.end)}</td><td><span className={`sa-premium-pill sa-premium-pill-${String(row.status).toLowerCase()}`}>{STATUS[row.status] || row.status}</span></td><td>{row.note || '—'}</td></tr>)}</tbody></table></div> : <div className="sa-premium-history-empty"><div className="sa-premium-history-empty-icon"><Icon name="calendar" size={24}/></div><strong>Belum ada catatan kehadiran</strong><p>Guru belum mencatat presensi untuk mapel dan rentang waktu ini. Tidak adanya catatan bukan berarti Alpa.</p></div>}
              {meta && meta.last_page > 1 && <div className="sa-premium-pagination"><span>Halaman {meta.current_page} dari {meta.last_page}</span><div><button disabled={page <= 1 || busy} type="button" onClick={() => setPage(n => Math.max(1, n - 1))}>Sebelumnya</button><button disabled={page >= meta.last_page || busy} type="button" onClick={() => setPage(n => n + 1)}>Berikutnya</button></div></div>}
            </section>
          </div>
        </>}
        <div className="sa-premium-disclaimer"><Icon name="info" size={17}/><p><strong>Catatan:</strong> Persentase hadir dihitung dari catatan presensi yang telah tersimpan, bukan dari seluruh jadwal pertemuan. Tanggal masa depan tidak dihitung, dan tanpa catatan tidak berarti Alpa.</p></div>
      </>}
    </>}
  </main>
}
