import { useEffect, useMemo, useState } from 'react'
import { apiClient } from '../../../services/apiClient'
import { isISODate, formatCount, todayWIB } from './principalDailyRecap'
import { normalizePrincipalPeriodInsights, periodParams, recordedPresenceRate } from './principalPeriodInsights'

const DISPLAY = [
  ['hadir', 'Hadir', 'green'], ['izin', 'Izin', 'blue'],
  ['sakit', 'Sakit', 'gold'], ['alpa', 'Alpa', 'rose'],
]
const number = value => Number.isFinite(value) ? new Intl.NumberFormat('id-ID', { maximumFractionDigits: 1 }).format(value) : '—'
const formatPercentage = value => value === null ? '—' : `${number(value)}%`
const errorMessage = err => err?.payload?.message || err?.message || 'Rekap periode tidak dapat dimuat.'

function ClassBreakdown({ item }) {
  const [expanded, setExpanded] = useState(false)
  const rate = recordedPresenceRate(item.totals)
  return (
    <article className="principal-attendance__class-card">
      <button type="button" className="principal-attendance__class-toggle" onClick={() => setExpanded(value => !value)} aria-expanded={expanded}>
        <span className="principal-attendance__class-title">
          <span className="principal-attendance__class-mark">{item.label.slice(0, 3).trim()}</span>
          <span><strong>{item.label}</strong><small>{item.semester} · {item.subjects.length} mata pelajaran tercatat</small></span>
        </span>
        <span className="principal-attendance__class-figures">
          <span><strong>{formatCount(item.totals.total)}</strong><small>catatan</small></span>
          <span><strong>{formatPercentage(rate)}</strong><small>hadir tercatat</small></span>
          <span aria-hidden="true" className="principal-attendance__expand">{expanded ? '−' : '+'}</span>
        </span>
      </button>
      {expanded && (
        <div className="principal-attendance__subject-region">
          <p className="principal-attendance__subject-note">Satu catatan = status seorang siswa pada satu pertemuan mata pelajaran. Ini bukan jumlah siswa unik atau jumlah hari sekolah.</p>
          <div className="principal-attendance__table-scroll" role="region" aria-label={`Rincian mapel ${item.label}`} tabIndex={0}>
            <table className="principal-attendance__table principal-attendance__subject-table">
              <thead><tr><th scope="col">Mata pelajaran</th><th scope="col">Catatan</th><th scope="col">Hadir</th><th scope="col">Izin</th><th scope="col">Sakit</th><th scope="col">Alpa</th><th scope="col">Persen hadir</th></tr></thead>
              <tbody>{item.subjects.map(subject => (
                <tr key={subject.id}><th scope="row">{subject.name}</th><td>{formatCount(subject.total)}</td>
                  <td className="principal-attendance__count--present">{formatCount(subject.hadir)}</td>
                  <td>{formatCount(subject.izin)}</td><td>{formatCount(subject.sakit)}</td><td>{formatCount(subject.alpa)}</td>
                  <td>{formatPercentage(recordedPresenceRate(subject))}</td></tr>
              ))}</tbody>
            </table>
          </div>
        </div>
      )}
    </article>
  )
}

export default function PrincipalPeriodInsights({ year }) {
  const [scope, setScope] = useState('SEMESTER')
  const [from, setFrom] = useState(() => `${todayWIB().slice(0, 7)}-01`)
  const [to, setTo] = useState(todayWIB)
  const [refresh, setRefresh] = useState(0)
  const [classKey, setClassKey] = useState('')
  const [state, setState] = useState({ status: 'idle', value: null, message: '' })
  const validRange = scope === 'YEAR' || (isISODate(from) && isISODate(to) && from <= to)

  useEffect(() => {
    let cancelled = false
    setClassKey('')
    if (!year || !validRange) {
      setState({ status: 'idle', value: null, message: '' })
      return () => { cancelled = true }
    }
    setState({ status: 'loading', value: null, message: '' })
    async function load() {
      try {
        const params = periodParams({ yearId: year.id, scope, from, to })
        const search = new URLSearchParams(params).toString()
        const response = await apiClient.get(`/attendance/principal-insights?${search}`)
        const normalized = normalizePrincipalPeriodInsights(response, { yearId: year.id, scope, from, to })
        if (!cancelled) setState({ status: 'ready', value: normalized, message: '' })
      } catch (error) {
        if (!cancelled) setState({ status: 'error', value: null, message: errorMessage(error) })
      }
    }
    load()
    return () => { cancelled = true }
  }, [year?.id, scope, from, to, validRange, refresh])

  const allClasses = state.value?.classes || []
  const classes = useMemo(() => classKey
    ? allClasses.filter(item => item.key === classKey)
    : allClasses, [allClasses, classKey])
  const totals = useMemo(() => classes.reduce((sum, item) => {
    for (const key of ['total', 'hadir', 'izin', 'sakit', 'alpa']) sum[key] += item.totals[key]
    return sum
  }, { total: 0, hadir: 0, izin: 0, sakit: 0, alpa: 0 }), [classes])
  const rate = recordedPresenceRate(totals)
  const incompleteYear = scope === 'YEAR' && state.value && state.value.periods.length < 2

  return (
    <div className="principal-attendance__period-insights">
      <div className="principal-attendance__period-filters">
        <div className="principal-attendance__filter-head">
          <div><span className="principal-attendance__eyebrow">ANALISIS PERIODE</span>
            <h2>Jelajahi kehadiran per mata pelajaran</h2>
            <p>Pilih rentang tanggal atau seluruh tahun ajaran. Data bersumber dari catatan pertemuan yang benar-benar tersimpan.</p></div>
          <button type="button" className="principal-attendance__refresh" disabled={state.status === 'loading' || !year} onClick={() => setRefresh(x => x + 1)}>↻ Perbarui data</button>
        </div>
        <div className="principal-attendance__scope-buttons" role="group" aria-label="Cakupan analisis">
          <button type="button" className={scope === 'SEMESTER' ? 'is-selected' : ''} onClick={() => setScope('SEMESTER')} aria-pressed={scope === 'SEMESTER'}>Rentang tanggal · {year?.semester || 'Semester'}</button>
          <button type="button" className={scope === 'YEAR' ? 'is-selected' : ''} onClick={() => setScope('YEAR')} aria-pressed={scope === 'YEAR'}>Seluruh tahun ajaran</button>
        </div>
        {scope === 'SEMESTER' ? (
          <div className="principal-attendance__date-range">
            <label>Mulai <input type="date" value={from} onChange={event => setFrom(event.target.value)} /></label>
            <label>Sampai <input type="date" value={to} min={from} onChange={event => setTo(event.target.value)} /></label>
            <span className="principal-attendance__hint">Data dibatasi semester {year?.semester?.toLowerCase() || 'terpilih'}.</span>
          </div>
        ) : (
          <p className="principal-attendance__year-note">Mengambil semua catatan pada semester Ganjil dan Genap {year?.tahun_mulai}/{year?.tahun_selesai} yang tersedia. Filter tanggal tidak membatasi hasil.</p>
        )}
        {!validRange && <p className="principal-attendance__alert" role="alert">Tanggal awal dan akhir wajib valid; tanggal akhir tidak boleh lebih awal.</p>}
      </div>
      {state.status === 'loading' && <div className="principal-attendance__insight-empty" role="status">Memuat catatan presensi periode…</div>}
      {state.status === 'error' && <div className="principal-attendance__insight-empty" role="alert">{state.message}<button type="button" onClick={() => setRefresh(x => x + 1)}>Coba lagi</button></div>}
      {state.status === 'ready' && (
        <>
          {incompleteYear && <div className="principal-attendance__alert" role="status">Baru {state.value.periods[0]?.semester} yang tersedia untuk tahun ajaran ini. Hasil belum mencakup kedua semester.</div>}
          <div className="principal-attendance__period-summary">
            <div className="principal-attendance__summary-feature">
              <span className="principal-attendance__eyebrow">PERSENTASE HADIR TERCATAT</span>
              <strong>{formatPercentage(rate)}</strong>
              <p>{rate === null ? 'Belum ada catatan pada filter ini.' : `${formatCount(totals.hadir)} dari ${formatCount(totals.total)} catatan berstatus Hadir.`}</p>
              <div className="principal-attendance__track"><span style={{ width: `${rate ?? 0}%` }} /></div>
            </div>
            <div className="principal-attendance__summary-minis">
              <div><small>Total catatan</small><strong>{formatCount(totals.total)}</strong></div>
              {DISPLAY.map(([key, text, color]) => (
                <div key={key} className={`principal-attendance__mini-stat principal-attendance__mini-stat--${color}`}>
                  <small>{text}</small><strong>{formatCount(totals[key])}</strong>
                </div>
              ))}
            </div>
          </div>
          <div className="principal-attendance__period-classes">
            <div className="principal-attendance__filter-head">
              <div><span className="principal-attendance__eyebrow">RINCIAN KELAS & MAPEL</span>
                <h2>Telusuri catatan pembelajaran</h2>
                <p>Klik kelas untuk melihat rincian status per mata pelajaran.</p></div>
              <label className="principal-attendance__class-filter">Pilih kelas
                <select value={classKey} onChange={event => setClassKey(event.target.value)}>
                  <option value="">Semua kelas dengan catatan</option>
                  {allClasses.map(item => <option key={item.key} value={item.key}>{item.label} · {item.semester}</option>)}
                </select>
              </label>
            </div>
            {classes.length ? classes.map(item => <ClassBreakdown key={item.key} item={item} />)
              : <div className="principal-attendance__insight-empty">Belum ada catatan presensi pertemuan untuk filter ini. Data akan muncul setelah Guru mencatat presensi pada jadwal mengajar yang sesuai.</div>}
          </div>
          <div className="principal-attendance__interpretation">
            <strong>Bagaimana membaca angka ini?</strong>
            <p>Analisis periode menghitung catatan siswa per jadwal pelajaran per tanggal, bukan siswa unik, bukan hari sekolah, dan bukan seluruh pertemuan yang seharusnya diselenggarakan. Persentase Hadir hanya berasal dari status yang sudah direkam. Tidak ada persentase kelengkapan periode karena jadwal yang benar-benar terlaksana belum tercatat sebagai sumber pembanding.</p>
          </div>
        </>
      )}
    </div>
  )
}
