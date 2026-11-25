import { useEffect, useMemo, useRef, useState } from 'react'

import { academicYearApi } from '../../../services/academicYearApi'
import { scheduleApi } from '../../../services/scheduleApi'
import InteractiveTimetable from '../components/InteractiveTimetable'
import {
  choosePrincipalPeriod,
  combinePrincipalSchedulePages,
  filterPrincipalSchedules,
  parsePrincipalAcademicYears,
  parsePrincipalSchedulePage,
  principalScheduleClassLabel,
  principalScheduleOptions,
  principalScheduleSummary,
  principalTimetableDisplaySchedules,
  scrollPrincipalTimetable,
  sortPrincipalWeekly,
} from './principalScheduleMonitoring'

import '../styles/principal-schedule.css'
import '../styles/principal-timetable.css'
import '../styles/principal-schedule-mobile-scroll.css'

const PAGE_SIZE = 100
const INITIAL_FILTERS = { class_id: '', teacher_id: '', subject_id: '', day: '' }
const DAYS = [
  { value: 'SENIN', label: 'Senin' }, { value: 'SELASA', label: 'Selasa' },
  { value: 'RABU', label: 'Rabu' }, { value: 'KAMIS', label: 'Kamis' },
  { value: 'JUMAT', label: 'Jumat' }, { value: 'SABTU', label: 'Sabtu' },
  { value: 'MINGGU', label: 'Minggu' },
]

function yearLabel(year) {
  return `${year.tahun_mulai}/${year.tahun_selesai} · ${year.semester}${year.is_active ? ' (Aktif)' : ''}`
}

function errorText(error, fallback) {
  return error?.payload?.message || error?.message || fallback
}

function HistoricalScheduleList({ schedules }) {
  return (
    <section className="principal-schedule__historical-panel" aria-label="Daftar jadwal periode tidak aktif">
      <div className="principal-schedule__table-header">
        <div>
          <span className="principal-schedule__eyebrow">RIWAYAT JADWAL</span>
          <h2>Jadwal mingguan tersimpan</h2>
          <p>Daftar ini merupakan pola jadwal pada periode terpilih, bukan catatan bahwa pembelajaran telah terlaksana.</p>
        </div>
        <span className="principal-schedule__historical-pill">Periode tidak aktif</span>
      </div>
      <div className="principal-schedule__table-scroll" role="region" aria-label="Tabel jadwal periode tidak aktif" tabIndex={0}>
        <table className="principal-schedule__table">
          <thead><tr><th scope="col">Hari</th><th scope="col">Jam WIB</th><th scope="col">Kelas</th><th scope="col">Mata pelajaran</th><th scope="col">Guru</th></tr></thead>
          <tbody>
            {sortPrincipalWeekly(schedules).map(item => (
              <tr key={item.id}>
                <td><strong>{DAYS.find(day => day.value === item.hari)?.label}</strong></td>
                <td>{item.jam_mulai.slice(0, 5)}–{item.jam_selesai.slice(0, 5)}</td>
                <td>{principalScheduleClassLabel(item)}</td>
                <td>{item.mapel?.nama || item.mapel?.kode || '—'}</td>
                <td>{item.guru?.nama_lengkap || '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  )
}

export default function PrincipalSchedule() {
  const timetableAreaRef = useRef(null)
  const [yearsState, setYearsState] = useState({ status: 'loading', rows: [], error: '' })
  const [yearsRetry, setYearsRetry] = useState(0)
  const [periodId, setPeriodId] = useState('')
  const [scheduleState, setScheduleState] = useState({ status: 'idle', rows: [], error: '' })
  const [scheduleRetry, setScheduleRetry] = useState(0)
  const [filters, setFilters] = useState(INITIAL_FILTERS)

  useEffect(() => {
    let cancelled = false
    setYearsState({ status: 'loading', rows: [], error: '' })
    async function loadYears() {
      try {
        // GET /academic-years returns an unpaginated collection.
        const response = await academicYearApi.getAll()
        const rows = parsePrincipalAcademicYears(response)
        if (cancelled) return
        setYearsState({ status: 'ready', rows, error: '' })
        setPeriodId(current => String(choosePrincipalPeriod(rows, current) ?? ''))
      } catch (error) {
        if (cancelled) return
        setPeriodId('')
        setYearsState({ status: 'error', rows: [], error: errorText(error, 'Gagal memuat tahun akademik.') })
      }
    }
    loadYears()
    return () => { cancelled = true }
  }, [yearsRetry])

  const selectedYear = useMemo(() =>
    yearsState.rows.find(row => String(row.id) === periodId) || null,
  [yearsState.rows, periodId])

  useEffect(() => {
    let cancelled = false
    if (yearsState.status !== 'ready' || !selectedYear) {
      setScheduleState({ status: 'idle', rows: [], error: '' })
      return () => { cancelled = true }
    }
    setScheduleState({ status: 'loading', rows: [], error: '' })
    async function loadSchedules() {
      try {
        const pages = []
        let lastPage = 1
        for (let page = 1; page <= lastPage; page += 1) {
          const response = await scheduleApi.getAll({
            academic_year_id: selectedYear.id, page, per_page: PAGE_SIZE,
          })
          if (cancelled) return
          const normalized = parsePrincipalSchedulePage(response, selectedYear.id, page)
          pages.push(normalized)
          lastPage = normalized.meta.last
          // Avoid treating changing pagination metadata as a complete dataset.
          if (page > 1 && normalized.meta.last !== pages[0].meta.last) {
            throw new Error('Jumlah halaman jadwal berubah saat pengambilan data.')
          }
        }
        const rows = combinePrincipalSchedulePages(pages)
        if (!cancelled) setScheduleState({ status: 'ready', rows, error: '' })
      } catch (error) {
        if (!cancelled) setScheduleState({ status: 'error', rows: [], error: errorText(error, 'Gagal memuat jadwal.') })
      }
    }
    loadSchedules()
    return () => { cancelled = true }
  }, [yearsState.status, selectedYear, scheduleRetry])

  const schedules = scheduleState.status === 'ready' ? scheduleState.rows : []
  const classOptions = useMemo(() => principalScheduleOptions(schedules,
    item => item.kelas_id, principalScheduleClassLabel), [schedules])
  const teacherOptions = useMemo(() => principalScheduleOptions(schedules,
    item => item.guru_id, item => item.guru?.nama_lengkap || `Guru #${item.guru_id}`), [schedules])
  const subjectOptions = useMemo(() => principalScheduleOptions(schedules,
    item => item.mapel_id, item => item.mapel?.nama || item.mapel?.kode || `Mapel #${item.mapel_id}`), [schedules])

  const filteredSchedules = useMemo(() => filterPrincipalSchedules(schedules, filters), [schedules, filters])
  const summary = useMemo(() => principalScheduleSummary(filteredSchedules), [filteredSchedules])
  const timetableSchedules = useMemo(() => principalTimetableDisplaySchedules(filteredSchedules), [filteredSchedules])
  const isHistory = selectedYear && !selectedYear.is_active

  function updatePeriod(value) {
    setPeriodId(value)
    setFilters(INITIAL_FILTERS)
  }
  function updateFilter(name, value) {
    setFilters(current => ({ ...current, [name]: value }))
  }
  function reload() {
    setYearsRetry(n => n + 1)
    setScheduleRetry(n => n + 1)
  }

  return (
    <div className="principal-schedule principal-schedule--v19">
      <header className="principal-schedule__header">
        <div className="principal-schedule__header-copy">
          <span className="principal-schedule__eyebrow"><span className="principal-schedule__hero-dot" /> MONITORING AKADEMIK · JADWAL</span>
          <h1>Jadwal pembelajaran sekolah,<br /><em>jelas untuk setiap kelas.</em></h1>
          <p>Pantau jadwal resmi berdasarkan semester, kelas, guru, mata pelajaran, dan hari. Tidak ada perubahan data dari halaman ini.</p>
          <span className="principal-schedule__hero-facts">Data jadwal tersimpan <span>•</span> Akses baca saja <span>•</span> Waktu WIB</span>
        </div>
        <div className="principal-schedule__hero-aside">
          <span>PERIODE DIPANTAU</span>
          <strong>{selectedYear ? yearLabel(selectedYear) : 'Belum dipilih'}</strong>
        </div>
      </header>

      <section className="principal-schedule__filters" aria-labelledby="principal-schedule-filter-title">
        <div className="principal-schedule__filter-heading">
          <div>
            <span className="principal-schedule__section-eyebrow">FILTER AKADEMIK</span>
            <h2 id="principal-schedule-filter-title">Pilih konteks monitoring</h2>
            <p>Satu pilihan periode sudah mencakup semester; pilihan lainnya hanya menyaring jadwal yang tercatat.</p>
          </div>
          <div className="principal-schedule__filter-actions">
            <button type="button" onClick={() => setFilters(INITIAL_FILTERS)} disabled={scheduleState.status !== 'ready'}>Reset Filter</button>
            <button type="button" onClick={reload}>Muat Ulang</button>
          </div>
        </div>
        <div className="principal-schedule__filter-grid principal-schedule__filter-grid--v19">
          <label><span>Tahun Akademik / Semester</span>
            <select aria-label="Tahun Akademik / Semester" value={periodId} onChange={event => updatePeriod(event.target.value)} disabled={yearsState.status !== 'ready' || yearsState.rows.length === 0}>
              {yearsState.rows.length === 0 && <option value="">Belum ada periode</option>}
              {yearsState.rows.map(item => <option key={item.id} value={String(item.id)}>{yearLabel(item)}</option>)}
            </select>
          </label>
          <label><span>Kelas</span>
            <select value={filters.class_id} onChange={event => updateFilter('class_id', event.target.value)} disabled={scheduleState.status !== 'ready'}>
              <option value="">Semua kelas</option>
              {classOptions.map(item => <option key={item.value} value={item.value}>{item.label}</option>)}
            </select>
          </label>
          <label><span>Guru</span>
            <select value={filters.teacher_id} onChange={event => updateFilter('teacher_id', event.target.value)} disabled={scheduleState.status !== 'ready'}>
              <option value="">Semua guru</option>
              {teacherOptions.map(item => <option key={item.value} value={item.value}>{item.label}</option>)}
            </select>
          </label>
          <label><span>Mata Pelajaran</span>
            <select value={filters.subject_id} onChange={event => updateFilter('subject_id', event.target.value)} disabled={scheduleState.status !== 'ready'}>
              <option value="">Semua mapel</option>
              {subjectOptions.map(item => <option key={item.value} value={item.value}>{item.label}</option>)}
            </select>
          </label>
          <label><span>Hari</span>
            <select value={filters.day} onChange={event => updateFilter('day', event.target.value)} disabled={scheduleState.status !== 'ready'}>
              <option value="">Semua hari</option>
              {DAYS.map(item => <option key={item.value} value={item.value}>{item.label}</option>)}
            </select>
          </label>
        </div>
      </section>

      {yearsState.status === 'loading' && <div className="principal-schedule__state" role="status"><span className="principal-schedule__spinner" />Memuat periode akademik...</div>}
      {yearsState.status === 'error' && <div className="principal-schedule__notice" role="alert"><strong>Tahun akademik gagal dimuat</strong><p>{yearsState.error}</p><button type="button" onClick={reload}>Coba lagi</button></div>}
      {yearsState.status === 'ready' && yearsState.rows.length === 0 && <div className="principal-schedule__notice"><strong>Belum ada periode akademik</strong><p>Data tahun akademik belum tersedia. Hubungi Tata Usaha.</p></div>}
      {scheduleState.status === 'loading' && <div className="principal-schedule__state" role="status"><span className="principal-schedule__spinner" />Memuat seluruh halaman jadwal...</div>}
      {scheduleState.status === 'error' && <div className="principal-schedule__notice" role="alert"><strong>Data jadwal gagal dimuat</strong><p>{scheduleState.error}</p><button type="button" onClick={() => setScheduleRetry(n => n + 1)}>Coba lagi</button></div>}

      {scheduleState.status === 'ready' && selectedYear && (
        <>
          <section className="principal-schedule__summary" aria-label="Ringkasan jadwal sesuai filter">
            {[
              ['Jadwal tersimpan', summary.schedules, 'Entri jadwal sesuai filter'],
              ['Kelas terjadwal', summary.classes, 'Kelas berbeda sesuai filter'],
              ['Guru terjadwal', summary.teachers, 'Guru berbeda sesuai filter'],
              ['Mata pelajaran', summary.subjects, 'Mapel berbeda sesuai filter'],
            ].map(([label, value, note]) => (
              <article className="principal-schedule__summary-card" key={label}>
                <span>{label}</span><strong>{value.toLocaleString('id-ID')}</strong><small>{note}</small>
              </article>
            ))}
          </section>

          <div className="principal-schedule__interpretation">
            <strong>Catatan pembacaan</strong>
            <p>Angka menunjukkan entri jadwal mingguan yang tersimpan, <b>bukan jumlah pertemuan terlaksana, kehadiran guru, atau pengisian presensi siswa</b>. Periode tidak aktif ditampilkan sebagai daftar jadwal tersimpan tanpa memproyeksikannya ke kalender hari ini.</p>
          </div>

          {filteredSchedules.length === 0 ? (
            <div className="principal-schedule__empty"><h2>Tidak ada jadwal sesuai filter</h2><p>Belum ada jadwal yang tersimpan untuk pilihan periode dan filter ini.</p><button type="button" onClick={() => setFilters(INITIAL_FILTERS)}>Reset Filter</button></div>
          ) : isHistory ? (
            <HistoricalScheduleList schedules={filteredSchedules} />
          ) : (
            <div ref={timetableAreaRef} className="principal-schedule__mobile-timetable">
              <div className="principal-schedule__mobile-scroll-controls" role="group" aria-label="Navigasi horizontal jadwal">
                <span className="principal-schedule__mobile-scroll-hint">Geser tabel ke samping untuk melihat hari lainnya</span>
                <div className="principal-schedule__mobile-scroll-actions">
                  <button type="button" aria-label="Geser tabel jadwal ke kiri" onClick={() => scrollPrincipalTimetable(timetableAreaRef.current, -1)}>‹</button>
                  <button type="button" aria-label="Geser tabel jadwal ke kanan" onClick={() => scrollPrincipalTimetable(timetableAreaRef.current, 1)}>›</button>
                </div>
              </div>
              <InteractiveTimetable role="KEPALA_SEKOLAH" schedules={timetableSchedules} academicYear={selectedYear} />
            </div>
          )}
        </>
      )}
    </div>
  )
}
