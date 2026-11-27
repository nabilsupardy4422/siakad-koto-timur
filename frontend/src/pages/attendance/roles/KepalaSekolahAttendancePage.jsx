import { useEffect, useMemo, useState } from 'react'
import Card from '../../../components/ui/Card'
import Select from '../../../components/ui/Select'
import Input from '../../../components/ui/Input'
import Button from '../../../components/ui/Button'
import LoadingState from '../../../components/ui/LoadingState'
import ErrorState from '../../../components/ui/ErrorState'
import EmptyState from '../../../components/ui/EmptyState'
import { academicYearApi } from '../../../services/academicYearApi'
import { attendanceApi } from '../../../services/attendanceApi'
import PrincipalPeriodInsights from './PrincipalPeriodInsights.jsx'
import PrincipalScheduleCoverage from './PrincipalScheduleCoverage.jsx'
import {
  formatCount, isISODate, normalizePrincipalDailyRecap,
  principalCoverage, principalDailyTotals, todayWIB,
} from './principalDailyRecap'
import {yearsFrom,defaultYear} from '../../tu-monitoring/monitoringLogic.js'
import './KepalaSekolahAttendancePage.css'

function responseCollection(payload) {
  const rows = payload?.data
  if (!Array.isArray(rows)) throw new Error('Daftar tahun akademik tidak sesuai kontrak API.')
  return rows
}

function getError(error) {
  return error?.payload?.message || error?.message || 'Gagal memuat rekap presensi.'
}

function yearLabel(year) {
  return `${year.tahun_mulai}/${year.tahun_selesai} · ${year.semester}`
}

const STATUS_CARDS = [
  { key: 'hadir', label: 'Hadir', color: 'teal' },
  { key: 'izin', label: 'Izin', color: 'blue' },
  { key: 'sakit', label: 'Sakit', color: 'amber' },
  { key: 'alpa', label: 'Alpa', color: 'rose' },
  { key: 'belum_ada_data', label: 'Belum Tercatat', color: 'slate' },
]

function CoveragePanel({ totals }) {
  const coverage = principalCoverage(totals)
  const recorded = totals.total_siswa - totals.belum_ada_data
  return (
    <Card>
      <div className="principal-attendance__coverage">
        <div>
          <span className="principal-attendance__eyebrow">KELENGKAPAN REKAP</span>
          <h2>Catatan presensi harian</h2>
          <p>Jumlah siswa yang sudah memiliki status pada tanggal terpilih.</p>
        </div>
        <div className="principal-attendance__coverage-stats">
          <strong>{coverage === null ? '—' : `${Number(coverage.toFixed(1)).toLocaleString('id-ID')}%`}</strong>
          <span>{formatCount(recorded)} dari {formatCount(totals.total_siswa)} siswa tercatat</span>
        </div>
        <div className="principal-attendance__track" role="progressbar" aria-label="Kelengkapan catatan presensi" aria-valuemin={0} aria-valuemax={100} aria-valuenow={coverage === null ? undefined : Math.round(coverage)} aria-valuetext={coverage === null ? 'Belum ada anggota kelas' : `${Number(coverage.toFixed(1)).toLocaleString('id-ID')} persen tercatat`}>
          <span style={{ width: `${coverage ?? 0}%` }} />
        </div>
        <p className="principal-attendance__coverage-note">Kelengkapan data bukan persentase kehadiran. Siswa tanpa catatan tidak dianggap Alpa.</p>
      </div>
    </Card>
  )
}

function RecapTable({ rows, date, selectedYear }) {
  return (
    <Card>
      <div className="principal-attendance__table-head">
        <div>
          <span className="principal-attendance__eyebrow">MONITORING PER KELAS</span>
          <h2>Rekap presensi per kelas</h2>
          <p>{date} · {selectedYear ? yearLabel(selectedYear) : '—'} · Satu siswa dihitung sekali per tanggal</p>
        </div>
        <span className="principal-attendance__pill">{rows.length} kelas</span>
      </div>
      {!rows.length ? (
        <EmptyState title="Belum ada kelas dalam rekap" description="Coba pilih tanggal atau kelas lain. Kelas tanpa anggota aktif akan ditampilkan dengan total nol jika tercatat pada periode ini." />
      ) : (
        <div className="principal-attendance__table-scroll" role="region" aria-label="Tabel rekap presensi kelas" tabIndex={0}>
          <table className="principal-attendance__table">
            <thead>
              <tr>
                <th scope="col">Kelas</th>
                <th scope="col">Siswa</th>
                <th scope="col">Hadir</th>
                <th scope="col">Izin</th>
                <th scope="col">Sakit</th>
                <th scope="col">Alpa</th>
                <th scope="col">Belum tercatat</th>
                <th scope="col">Kelengkapan</th>
              </tr>
            </thead>
            <tbody>
              {rows.map(row => (
                <tr key={row.id}>
                  <th scope="row" className="principal-attendance__class-label">{row.label}</th>
                  <td>{formatCount(row.total_siswa)}</td>
                  <td className="principal-attendance__count--present">{formatCount(row.hadir)}</td>
                  <td>{formatCount(row.izin)}</td>
                  <td>{formatCount(row.sakit)}</td>
                  <td>{formatCount(row.alpa)}</td>
                  <td className={row.belum_ada_data > 0 ? 'principal-attendance__count--pending' : ''}>{formatCount(row.belum_ada_data)}</td>
                  <td>
                    <div className="principal-attendance__completion-cell">
                      <span>{row.completion === null ? '—' : `${Number(row.completion.toFixed(1)).toLocaleString('id-ID')}%`}</span>
                      <div className="principal-attendance__mini-track" aria-hidden="true"><span style={{ width: `${row.completion ?? 0}%` }} /></div>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Card>
  )
}

export default function KepalaSekolahAttendancePage() {
  const [mode, setMode] = useState('daily')
  const [yearsState, setYearsState] = useState({ status: 'loading', rows: [], error: '' })
  const [yearRetry, setYearRetry] = useState(0)
  const [yearId, setYearId] = useState('')
  const [date, setDate] = useState(todayWIB)
  const [classId, setClassId] = useState('')
  const [recapState, setRecapState] = useState({ status: 'idle', rows: [], error: '' })
  const [recapRetry, setRecapRetry] = useState(0)

  useEffect(() => {
    let cancelled = false
    setYearsState({ status: 'loading', rows: [], error: '' })
    async function loadYears() {
      try {
        const response = await academicYearApi.getAll({ per_page: 100 })
        const years = yearsFrom(response)
        if (cancelled) return
        setYearsState({ status: 'ready', rows: years, error: '' })
        setYearId(current => years.some(row => String(row.id) === current)
          ? current
          : defaultYear(years))
      } catch (error) {
        if (!cancelled) { setYearId(''); setYearsState({ status: 'error', rows: [], error: getError(error) }) }
      }
    }
    loadYears()
    return () => { cancelled = true }
  }, [yearRetry])

  const selectedYear = useMemo(
    () => yearsState.rows.find(row => String(row.id) === yearId) || null,
    [yearsState.rows, yearId],
  )

  useEffect(() => {
    let cancelled = false
    if (mode !== 'daily' || !selectedYear || !isISODate(date) || date>todayWIB()) {
      setRecapState({ status: 'idle', rows: [], error: '' })
      return () => { cancelled = true }
    }
    setRecapState({ status: 'loading', rows: [], error: '' })
    async function load() {
      try {
        const response = await attendanceApi.getPrincipalClassRecap({
          academic_year_id: selectedYear.id,
          date,
          semester: selectedYear.semester,
        })
        const normalized = normalizePrincipalDailyRecap(response, {
          yearId: selectedYear.id, date, semester: selectedYear.semester,
        })
        if (cancelled) return
        setRecapState({ status: 'ready', rows: normalized.rows, warnings: response.meta.warnings || [], context: `${selectedYear.id}:${date}`, error: '' })
      } catch (error) {
        if (!cancelled) setRecapState({ status: 'error', rows: [], error: getError(error) })
      }
    }
    load()
    return () => { cancelled = true }
  }, [selectedYear, date, recapRetry, mode])

  const classOptions = useMemo(() => recapState.rows.map(row => ({
    value: String(row.id), label: row.label,
  })), [recapState.rows])
  const filteredRows = useMemo(() => classId
    ? recapState.rows.filter(row => String(row.id) === classId)
    : recapState.rows, [recapState.rows, classId])
  const totals = useMemo(() => principalDailyTotals(filteredRows), [filteredRows])
  const loading = yearsState.status === 'loading' || recapState.status === 'loading'
  const showData = yearsState.status === 'ready' && recapState.status === 'ready' && recapState.context === `${selectedYear?.id}:${date}`

  return (
    <div className="attendance-page principal-attendance">
      <header className="principal-attendance__hero">
        <div className="principal-attendance__hero-content">
          <span className="principal-attendance__hero-kicker"><span className="principal-attendance__hero-dot" /> PUSAT ANALITIK PRESENSI</span>
          <h1>Monitoring Presensi</h1>
          <p>Mulai dari ringkasan satu tanggal hingga catatan setiap mata pelajaran sepanjang tahun ajaran.</p>
          <div className="principal-attendance__hero-facts"><span>◈ Data akademik resmi</span><span>◈ Berbasis kelas &amp; jadwal</span><span>◈ WIB</span></div>
        </div>
        <div className="principal-attendance__hero-side"><span className="principal-attendance__read-only">Monitoring · Baca saja</span></div>
      </header>
      <nav className="principal-attendance__mode-switch" aria-label="Jenis rekap presensi">
        <button type="button" className={mode === 'daily' ? 'is-active' : ''} onClick={() => {setMode('daily'); if(date>todayWIB())setDate(todayWIB());setClassId('')}} aria-current={mode === 'daily' ? 'page' : undefined}>
          <strong>Rekap harian</strong><small>Satu siswa per tanggal</small>
        </button>
        <button type="button" className={mode === 'coverage' ? 'is-active' : ''} onClick={() => {setMode('coverage');setClassId('')}} aria-current={mode === 'coverage' ? 'page' : undefined}><strong>Pola jadwal</strong><small>Termasuk rencana mendatang</small></button>
        <button type="button" className={mode === 'period'  ? 'is-active' : ''} onClick={() => setMode('period')} aria-current={mode === 'period' ? 'page' : undefined}>
          <strong>Analisis periode</strong><small>Per mapel &amp; seluruh tahun ajaran</small>
        </button>
      </nav>
      <Card>
        <div className="principal-attendance__filter-head">
          <div>
            <span className="principal-attendance__eyebrow">FILTER AKADEMIK</span>
            <h2>{mode === 'daily' ? 'Jelajahi rekap harian' : 'Pilih tahun ajaran'}</h2>
            <p>{mode === 'daily'
              ? 'Setiap siswa dihitung sekali per tanggal, bukan per mata pelajaran.'
              : 'Untuk rentang tanggal, hasil terbatas pada semester terpilih. Seluruh tahun ajaran menggabungkan semester yang tersedia.'}</p>
          </div>
          {mode === 'daily' && <Button variant="secondary" disabled={loading || !selectedYear} onClick={() => setRecapRetry(value => value + 1)}>↻ Muat ulang</Button>}
        </div>
        <div className={`principal-attendance__filters ${mode === 'period' ? 'principal-attendance__filters--period' : ''}`}>
          <Select id="principal-attendance-year" label="Tahun Akademik" required value={yearId}
            options={yearsState.rows.map(row => ({ value: String(row.id), label: yearLabel(row) }))}
            placeholder="Pilih tahun akademik" disabled={yearsState.status !== 'ready' || !yearsState.rows.length}
            onChange={event => { setClassId(''); setYearId(event.target.value) }} />
          {mode !== 'period' && <>
            <Input id="principal-attendance-date" label="Tanggal Presensi" type="date" required value={date}
              max={mode==='daily'?todayWIB():undefined} onChange={event => {setClassId('');setDate(event.target.value)}} />
            {mode==='daily'&&<Select id="principal-attendance-class" label="Kelas" value={classId}
              options={classOptions} placeholder="Semua kelas" disabled={!showData || classOptions.length === 0}
              onChange={event => setClassId(event.target.value)} />}
          </>}
        </div>
        {selectedYear && <p className="principal-attendance__semester">Semester terpilih: <strong>{selectedYear.semester}</strong> · Mengikuti tahun akademik yang dipilih.</p>}
      </Card>
      {yearsState.status === 'error' && (
        <div className="principal-attendance__message"><ErrorState message={yearsState.error} /><Button variant="secondary" onClick={() => setYearRetry(n => n + 1)}>Coba lagi</Button></div>
      )}
      {yearsState.status === 'ready' && !yearsState.rows.length && <EmptyState title="Belum ada tahun akademik" description="Tata Usaha perlu menetapkan tahun akademik sebelum rekap dapat ditampilkan." />}
      {yearsState.status==='ready'&&yearsState.rows.length>0&&!selectedYear&&<p role="status">Pilih periode secara eksplisit; periode aktif tidak tersedia atau tidak tunggal.</p>}
      {mode === 'period' && yearsState.status === 'ready' && selectedYear && (
        <PrincipalPeriodInsights key={selectedYear.id} year={selectedYear} />
      )}
      {mode==='coverage'&&selectedYear&&isISODate(date)&&<PrincipalScheduleCoverage key={`${selectedYear.id}:${date}`} year={selectedYear} date={date} classId='' dailyRetry={recapRetry}/> }
      {mode === 'daily' && <>
      {!isISODate(date) && <ErrorState message="Tanggal presensi belum valid. Gunakan format tanggal yang benar." />}
      {recapState.status === 'error' && (
        <div className="principal-attendance__message"><ErrorState message={recapState.error} /><Button variant="secondary" onClick={() => setRecapRetry(n => n + 1)}>Coba lagi</Button></div>
      )}
      {loading && <LoadingState />}
      {showData && (
        <>
          {recapState.warnings?.map(w=><p key={w} role="alert" className="principal-attendance__footnote">{w}</p>)}
          <div className="principal-attendance__metrics">
            <CoveragePanel totals={totals} />
            <div className="principal-attendance__status-grid" aria-label="Status presensi harian">
              {STATUS_CARDS.map(item => (
                <div key={item.key} className={`principal-attendance__status principal-attendance__status--${item.color}`}>
                  <span>{item.label}</span>
                  <strong>{formatCount(totals[item.key])}</strong>
                </div>
              ))}
            </div>
          </div>
          <RecapTable rows={filteredRows} date={date} selectedYear={selectedYear} />
          <PrincipalScheduleCoverage key={`${selectedYear.id}:${date}:${classId}`} year={selectedYear} date={date} classId={classId} dailyRetry={recapRetry} />
          <p className="principal-attendance__footnote">
            Total siswa adalah keanggotaan kelas aktif pada tanggal rekap. Alpa hanya berasal dari catatan ALPA; siswa tanpa catatan masuk Belum Tercatat. Rekap harian menerapkan prioritas status ALPA &gt; SAKIT &gt; IZIN &gt; HADIR bila ada lebih dari satu pertemuan pada tanggal yang sama.
          </p>
        </>
      )}
      </>}
    </div>
  )
}
