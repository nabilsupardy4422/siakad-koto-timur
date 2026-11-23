import { useCallback, useEffect, useMemo, useState } from 'react'
import { scheduleApi } from '../../../services/scheduleApi'
import {
  WEEK_DAYS, DAY_LABELS, displayClock, getCompleteStudentSchedule,
  nextWeeklyClass, wibNow, yearTitle,
} from './studentScheduleScope'
import '../styles/student-schedule-v2.css'

const icons = {
  calendar: <><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 10h18"/></>,
  home: <><path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1z"/><path d="M9 21v-8h6v8"/></>,
  book: <><path d="M12 7c-3-3-6-3-9-2v15c3-1 6-1 9 2 3-3 6-3 9-2V5c-3-1-6-1-9 2ZM12 7v15"/></>,
  clock: <><circle cx="12" cy="12" r="9"/><path d="M12 7v5l4 2"/></>,
  filter: <><path d="M4 6h16M7 12h10M10 18h4"/></>,
  arrow: <><path d="m9 18 6-6-6-6"/></>,
  info: <><circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7h.01"/></>,
  close: <><path d="M5 5l14 14M19 5 5 19"/></>,
  refresh: <><path d="M20 7v5h-5M4 17v-5h5"/><path d="M6 9a7 7 0 0 1 12-2l2 5M4 12l2 5a7 7 0 0 0 12-2"/></>,
}
function Icon({ name, size = 20 }) {
  return <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">{icons[name] || icons.info}</svg>
}
const getError = (error) => error?.payload?.message || error?.message || 'Jadwal gagal dimuat. Coba muat ulang.'
const weekday = (row) => WEEK_DAYS.indexOf(String(row.hari || '').toUpperCase())
const subjectOf = (row) => row.mapel.nama
const teacherOf = (row) => row.guru.nama_lengkap
const classroom = (context) => `${context.class.nama} · Tingkat ${context.class.tingkat}`
const SUBJECT_PALETTE = ['#2563eb', '#148476', '#9a601b', '#7c52ae', '#bf526c', '#377993', '#486c42']

function StatusNotice({ context }) {
  const state = context.status
  const info = {
    scheduled: ['Penempatan kelas belum dimulai', `Penempatan Anda baru berlaku mulai ${context.starts_on}. Jadwal belum bisa diakses sebelum tanggal tersebut.`],
    ended: ['Penempatan kelas telah berakhir', 'Hubungi Tata Usaha untuk memastikan penempatan kelas pada periode aktif.'],
    unassigned: ['Belum ada penempatan kelas aktif', 'Jadwal akan tersedia setelah Tata Usaha menetapkan kelas Anda pada periode akademik aktif.'],
  }[state] || ['Jadwal belum tersedia', 'Hubungi Tata Usaha untuk informasi selanjutnya.']
  return <section className="ssv2-notice" role="status">
    <span className="ssv2-notice-symbol"><Icon name="info" size={28}/></span>
    <h2>{info[0]}</h2><p>{info[1]}</p>
    <span>Hanya jadwal kelas yang menjadi penempatan aktif Anda yang dapat ditampilkan.</span>
  </section>
}

export default function StudentSchedule() {
  const [result, setResult] = useState(null)
  const [pending, setPending] = useState(true)
  const [error, setError] = useState('')
  const [mode, setMode] = useState(() => typeof window !== 'undefined' && window.matchMedia?.('(max-width: 720px)')?.matches ? 'day' : 'week')
  const [day, setDay] = useState(() => wibNow().weekday)
  const [subject, setSubject] = useState('all')
  const [detail, setDetail] = useState(null)
  const [reload, setReload] = useState(0)

  useEffect(() => {
    let cancelled = false
    setPending(true); setError(''); setResult(null); setDetail(null)
    const readPage = (page) => scheduleApi.getAll({ per_page: 100, page })
    getCompleteStudentSchedule(readPage)
      .then((value) => { if (!cancelled) { setResult(value); setPending(false); setSubject('all') } })
      .catch((reason) => { if (!cancelled) { setError(getError(reason)); setPending(false) } })
    return () => { cancelled = true }
  }, [reload])

  // Keep the read-only view fresh when returning to the tab (period/placement may change at TU).
  useEffect(() => {
    const refresh = () => { if (document.visibilityState === 'visible') setReload(n => n + 1) }
    window.addEventListener('focus', refresh)
    document.addEventListener('visibilitychange', refresh)
    return () => { window.removeEventListener('focus', refresh); document.removeEventListener('visibilitychange', refresh) }
  }, [])

  const context = result?.context
  const schedules = result?.schedules || []
  const subjects = useMemo(() => [...new Map(schedules.map(s => [String(s.mapel_id), { id: String(s.mapel_id), title: subjectOf(s) }])).values()].sort((a, b) => a.title.localeCompare(b.title, 'id')), [schedules])
  const colored = useMemo(() => Object.fromEntries(subjects.map((s, i) => [s.id, SUBJECT_PALETTE[i % SUBJECT_PALETTE.length]])), [subjects])
  const filtered = schedules.filter(s => subject === 'all' || String(s.mapel_id) === subject)
  const daily = filtered.filter(s => weekday(s) === day)
  const now = wibNow()
  const next = nextWeeklyClass(schedules, now)
  const openDetail = useCallback((row) => setDetail(row), [])

  return <div className="ssv2">
    <header className="ssv2-head">
      <div><span className="ssv2-eyebrow">— KEGIATAN PEMBELAJARAN</span><h1>Jadwal pelajaran.</h1>
        <p>Lihat susunan pelajaran rutin sesuai kelas dan periode akademik Anda.</p></div>
      <div className="ssv2-period"><Icon name="calendar"/><span><small>PERIODE AKADEMIK AKTIF</small><strong>{yearTitle(context?.academic_year)}</strong></span></div>
    </header>

    {pending ? <section className="ssv2-notice" role="status"><Icon name="refresh" size={28}/><h2>Memuat jadwal kelas…</h2><p>Memeriksa penempatan dan periode aktif Anda.</p></section> :
    error ? <section className="ssv2-notice ssv2-notice--error" role="alert"><Icon name="info" size={28}/><h2>Jadwal belum dapat ditampilkan</h2><p>{error}</p><button type="button" onClick={() => setReload(n => n + 1)}>Coba lagi</button></section> :
    context?.status !== 'active' ? <StatusNotice context={context}/> : <>
      <div className="ssv2-stats">
        <div className="ssv2-stat"><span className="ssv2-stat-icon"><Icon name="home"/></span><span><small>Kelas saya</small><strong>{classroom(context)}</strong></span></div>
        <div className="ssv2-stat"><span className="ssv2-stat-icon"><Icon name="calendar"/></span><span><small>Jadwal per minggu</small><strong>{schedules.length}</strong></span></div>
        <div className="ssv2-stat"><span className="ssv2-stat-icon"><Icon name="book"/></span><span><small>Mata pelajaran</small><strong>{subjects.length}</strong></span></div>
      </div>
      <div className="ssv2-columns">
        <section className="ssv2-panel ssv2-main">
          <div className="ssv2-panel-top"><div><h2>Jadwal mingguan</h2><p>Pola rutin Senin–Minggu · WIB</p></div>
            <div className="ssv2-switch" aria-label="Tampilan jadwal">
              <button type="button" className={mode === 'week' ? 'is-current' : ''} onClick={() => setMode('week')} aria-pressed={mode === 'week'}>Mingguan</button>
              <button type="button" className={mode === 'day' ? 'is-current' : ''} onClick={() => setMode('day')} aria-pressed={mode === 'day'}>Per hari</button>
            </div>
          </div>
          <div className="ssv2-filter"><label><Icon name="filter" size={17}/><span className="ssv2-sr">Filter mata pelajaran</span>
            <select value={subject} onChange={e => setSubject(e.target.value)}><option value="all">Semua mata pelajaran</option>{subjects.map(s => <option key={s.id} value={s.id}>{s.title}</option>)}</select></label>
            <span>{filtered.length} jadwal dalam cakupan</span></div>
          {schedules.length === 0 ? <div className="ssv2-empty"><Icon name="calendar" size={27}/><h3>Jadwal kelas belum diatur</h3><p>Tata Usaha belum menetapkan jadwal rutin untuk kelas ini.</p></div> : mode === 'week' ?
            <div className="ssv2-week" role="group" aria-label="Jadwal rutin mingguan">
              {WEEK_DAYS.map((key, index) => {
                const entries = filtered.filter(s => weekday(s) === index)
                return <div className={'ssv2-week-day' + (now.weekday === index ? ' today' : '')} key={key}>
                  <div className="ssv2-day-heading"><strong>{DAY_LABELS[index]}</strong><small>{entries.length} pelajaran{index === now.weekday ? ' · hari ini' : ''}</small></div>
                  <div className="ssv2-day-body">{entries.length ? entries.map(row => <button type="button" className="ssv2-lesson" key={row.id} style={{ '--lesson-color': colored[String(row.mapel_id)] }} onClick={() => openDetail(row)}>
                    <span>{displayClock(row.jam_mulai)}–{displayClock(row.jam_selesai)}</span><strong>{subjectOf(row)}</strong><small>{teacherOf(row)}</small>
                  </button>) : <p className="ssv2-no-day">Tidak ada jadwal</p>}</div>
                </div>
              })}
            </div> : <><div className="ssv2-day-tabs" aria-label="Pilih hari">
              {DAY_LABELS.map((label, i) => <button key={label} type="button" onClick={() => setDay(i)} className={i === day ? 'is-current' : ''} aria-pressed={i === day}>{label.slice(0, 3)}</button>)}</div>
              <div className="ssv2-day-agenda"><h3>{DAY_LABELS[day]} <span>· {daily.length} pelajaran</span></h3>
                {daily.length ? daily.map(row => <button className="ssv2-agenda-lesson" key={row.id} type="button" onClick={() => openDetail(row)}>
                  <span className="ssv2-agenda-clock">{displayClock(row.jam_mulai)}<small>{displayClock(row.jam_selesai)}</small></span><span className="ssv2-agenda-info"><strong>{subjectOf(row)}</strong><small>{teacherOf(row)} · {classroom(context)}</small></span><Icon name="arrow" size={19}/>
                </button>) : <p className="ssv2-no-lesson">Tidak ada pelajaran rutin pada hari ini.</p>}</div></>}
          <div className="ssv2-legend">{subjects.map(sub => <span key={sub.id}><i style={{ background: colored[sub.id] }}/>{sub.title}</span>)}</div>
        </section>
        <aside className="ssv2-aside">
          <section className="ssv2-panel"><h2>Agenda pelajaran</h2><span className="ssv2-side-label">{DAY_LABELS[now.weekday].toUpperCase()} · HARI INI (WIB)</span>
            <p className="ssv2-today-info">{schedules.some(row => weekday(row) === now.weekday) ? `${schedules.filter(row => weekday(row) === now.weekday).length} jadwal rutin hari ini.` : 'Tidak ada jadwal rutin hari ini.'}</p>
            <span className="ssv2-side-label">{next?.underway ? 'SEDANG BERLANGSUNG' : 'JADWAL RUTIN BERIKUTNYA'}</span>
            {next ? <div className="ssv2-next"><strong>{subjectOf(next.schedule)}</strong><p>{DAY_LABELS[weekday(next.schedule)]} · {displayClock(next.schedule.jam_mulai)}–{displayClock(next.schedule.jam_selesai)} WIB</p><small>{teacherOf(next.schedule)}</small>
              <button type="button" onClick={() => openDetail(next.schedule)}>Lihat detail <Icon name="arrow" size={17}/></button></div> : <p className="ssv2-today-info">Belum ada jadwal.</p>}
          </section>
          <section className="ssv2-panel"><h2>Informasi jadwal</h2><p>Jadwal mengikuti <strong>penempatan kelas pada periode aktif</strong>. Hari dan jam berasal dari data resmi SIAKAD yang dikelola Tata Usaha.</p>
            <p>Jadwal rutin bukan bukti bahwa pertemuan sudah terlaksana atau tidak ada hari libur.</p>
            <button className="ssv2-reload" type="button" onClick={() => setReload(n => n + 1)}><Icon name="refresh" size={15}/> Muat data terbaru</button></section>
        </aside>
      </div>
      <p className="ssv2-foot"><Icon name="info" size={17}/> Informasi ini merupakan jadwal rutin mingguan, bukan catatan presensi atau kalender hari libur. Penempatan kelas dan jadwal dikelola Tata Usaha.</p>
      {detail && <div className="ssv2-modal-backdrop" onMouseDown={e => { if (e.target === e.currentTarget) setDetail(null) }}>
        <section className="ssv2-modal" role="dialog" aria-modal="true" aria-labelledby="ssv2-detail-title"><div className="ssv2-modal-head"><span>DETAIL JADWAL PELAJARAN</span><button type="button" aria-label="Tutup detail" onClick={() => setDetail(null)}><Icon name="close"/></button></div>
          <h2 id="ssv2-detail-title">{subjectOf(detail)}</h2>
          <dl><div><dt>Guru pengampu</dt><dd>{teacherOf(detail)}</dd></div><div><dt>Kelas</dt><dd>{classroom(context)}</dd></div><div><dt>Hari</dt><dd>{DAY_LABELS[weekday(detail)]}</dd></div><div><dt>Jam pelajaran (WIB)</dt><dd>{displayClock(detail.jam_mulai)}–{displayClock(detail.jam_selesai)}</dd></div><div><dt>Periode akademik</dt><dd>{yearTitle(context.academic_year)}</dd></div></dl>
          <button className="ssv2-modal-close" type="button" onClick={() => setDetail(null)}>Selesai</button>
        </section>
      </div>}
    </>}
  </div>
}
