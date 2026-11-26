import { useEffect, useMemo, useState } from 'react'
import { apiClient } from '../../../services/apiClient'
import './PrincipalScheduleCoverage.css'
import { formatCount, isISODate } from './principalDailyRecap'
import {
  scheduleCoverageParams, normalizePrincipalScheduleCoverage, recordedScheduleRate,
} from './PrincipalScheduleCoverage.js'

const STATE = {
  complete: ['Lengkap', 'complete'],
  partial: ['Sebagian', 'partial'],
  without_records: ['Belum ada catatan', 'missing'],
  not_finished: ['Belum selesai', 'upcoming'],
  without_students: ['Tanpa anggota', 'neutral'],
}
const pct = value => value === null ? '—' : `${Number(value.toFixed(1)).toLocaleString('id-ID')}%`
const errorText = error => error?.payload?.message || error?.message || 'Monitoring jadwal tidak dapat dimuat.'

export default function PrincipalScheduleCoverage({ year, date, classId = '', dailyRetry = 0 }) {
  const [retry, setRetry] = useState(0)
  const [state, setState] = useState({ status: 'idle', value: null, error: '' })
  const valid = Boolean(year?.id) && isISODate(date)

  useEffect(() => {
    let cancelled = false
    if (!valid) { setState({ status: 'idle', value: null, error: '' }); return () => { cancelled = true } }
    setState({ status: 'loading', value: null, error: '' })
    async function load() {
      try {
        const request = { yearId: year.id, date, classId }
        const params = scheduleCoverageParams(request)
        const response = await apiClient.get(`/attendance/principal-schedule-coverage?${new URLSearchParams(params)}`)
        const value = normalizePrincipalScheduleCoverage(response, request)
        if (!cancelled) setState({ status: 'ready', value, error: '' })
      } catch (error) {
        if (!cancelled) setState({ status: 'error', value: null, error: errorText(error) })
      }
    }
    load()
    return () => { cancelled = true }
  }, [year?.id, date, classId, dailyRetry, retry, valid])

  const stats = state.value?.summary
  const rate = useMemo(() => stats ? recordedScheduleRate(stats) : null, [stats])
  return (
    <section className="principal-attendance__schedule-section" aria-label="Monitoring catatan jadwal pelajaran">
      <div className="principal-attendance__schedule-heading">
        <div>
          <span className="principal-attendance__eyebrow">VISIBILITAS PENGISIAN GURU</span>
          <h2>Presensi pada jadwal pelajaran</h2>
          <p>Pantau apakah jadwal mingguan pada tanggal pilihan sudah memiliki catatan siswa. Berbeda dari rekap satu siswa per hari.</p>
        </div>
        <button type="button" className="principal-attendance__refresh" disabled={!valid || state.status === 'loading'} onClick={() => setRetry(v => v + 1)}>↻ Perbarui</button>
      </div>
      {state.status === 'loading' && <div className="principal-attendance__insight-empty" role="status">Memeriksa jadwal dan catatan presensi…</div>}
      {state.status === 'error' && <div className="principal-attendance__alert" role="alert">{state.error} <button type="button" className="principal-attendance__refresh" onClick={() => setRetry(v => v + 1)}>Coba lagi</button></div>}
      {state.status === 'ready' && <>
        <div className="principal-attendance__schedule-summary">
          <div className="principal-attendance__schedule-feature">
            <span className="principal-attendance__eyebrow">JADWAL SELESAI YANG MEMILIKI CATATAN</span>
            <strong>{pct(rate)}</strong>
            <p>{stats.evaluated === 0
              ? 'Belum ada jadwal dengan anggota kelas yang selesai waktunya.'
              : `${formatCount(stats.with_records)} dari ${formatCount(stats.evaluated)} jadwal selesai memiliki sedikitnya satu catatan.`}</p>
            <div className="principal-attendance__track" role="progressbar" aria-label="Jadwal selesai dengan catatan" aria-valuemin={0} aria-valuemax={100} aria-valuenow={rate === null ? undefined : Math.round(rate)}>
              <span style={{ width: `${rate ?? 0}%` }} />
            </div>
            <small>Belum membuktikan semua siswa sudah diabsen atau pelajaran benar-benar berlangsung.</small>
          </div>
          <div className="principal-attendance__schedule-stats">
            <div><small>Jadwal mingguan</small><strong>{formatCount(stats.scheduled)}</strong></div>
            <div><small>Lengkap seluruh siswa</small><strong>{formatCount(stats.complete)}</strong></div>
            <div><small>Tercatat sebagian</small><strong>{formatCount(stats.partial)}</strong></div>
            <div><small>Belum ada catatan</small><strong>{formatCount(stats.without_records)}</strong></div>
          </div>
        </div>
        <div className="principal-attendance__schedule-details">
          <div className="principal-attendance__schedule-topline">
            <h3>Rincian jadwal · {state.value.weekday}, {date}</h3>
            <span>{formatCount(stats.not_finished)} belum selesai · {formatCount(stats.without_students)} tanpa anggota kelas</span>
          </div>
          {state.value.rows.length === 0 ? (
            <div className="principal-attendance__insight-empty">Tidak ada jadwal mingguan yang terdaftar pada hari ini untuk filter yang dipilih. Pertemuan pengganti di luar pola hari rutin tidak termasuk di panel ini.</div>
          ) : (
            <div className="principal-attendance__table-scroll" role="region" aria-label="Rincian pengisian presensi berdasarkan jadwal" tabIndex={0}>
              <table className="principal-attendance__table principal-attendance__schedule-table">
                <thead><tr><th scope="col">Kelas / mapel</th><th scope="col">Guru</th><th scope="col">Jam WIB</th><th scope="col">Siswa tercatat</th><th scope="col">Status catatan</th></tr></thead>
                <tbody>{state.value.rows.map(row => {
                  const [text, css] = STATE[row.state]
                  return (
                    <tr key={row.id}>
                      <th scope="row"><strong>{row.classLabel}</strong><small>{row.subject}</small></th>
                      <td>{row.teacher}</td>
                      <td>{row.time}</td>
                      <td><strong>{formatCount(row.recorded)}</strong> / {formatCount(row.students)}</td>
                      <td><span className={`principal-attendance__schedule-chip principal-attendance__schedule-chip--${css}`}>{text}</span></td>
                    </tr>
                  )
                })}</tbody>
              </table>
            </div>
          )}
        </div>
        <div className="principal-attendance__schedule-disclaimer">
          <strong>Cara membaca indikator ini</strong>
          <p>Angka membandingkan jadwal mingguan yang telah melewati jam selesai dan mempunyai anggota aktif dengan catatan presensi yang tersimpan pada tanggal tersebut. Jadwal sebelum selesai, kelas kosong, libur, pembatalan, perubahan jadwal, serta pertemuan pengganti belum dapat diverifikasi otomatis. <b>“Belum ada catatan” tidak boleh digunakan sebagai bukti guru tidak mengajar atau tidak mengisi presensi.</b></p>
        </div>
      </>}
    </section>
  )
}
