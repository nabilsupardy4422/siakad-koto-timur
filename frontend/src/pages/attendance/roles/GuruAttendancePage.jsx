import { useEffect, useMemo, useRef, useState } from 'react'
import SearchableSelect from '../../../components/ui/SearchableSelect'
import LoadingState from '../../../components/ui/LoadingState'
import ErrorState from '../../../components/ui/ErrorState'
import EmptyState from '../../../components/ui/EmptyState'
import { attendanceApi } from '../../../services/attendanceApi'
import { scheduleApi } from '../../../services/scheduleApi'
import { academicYearApi } from '../../../services/academicYearApi'
import { useAuth } from '../../../context/useAuth'
import { collectPages, loadOwnTeachingSchedules, teacherId } from '../../../utils/teachingScope'
import { isAttendanceDate, weekdayMismatch, loadAttendanceRoster } from './attendanceScope'
import ConfirmDialog from '../../../components/ui/ConfirmDialog'
import ToastNotification from '../../../components/ui/ToastNotification'
import AttendanceStatusSelector, { STATUS_OPTIONS } from '../components/AttendanceStatusSelector'
import AttendanceSummary from '../components/AttendanceSummary'
import AttendanceActionBar from '../components/AttendanceActionBar'
import './GuruAttendancePage.css'

function today() {
  const date = new Date()
  return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 10)
}
function getErrorMessage(error) {
  const errors = error?.errors || error?.payload?.errors
  return (errors && Object.values(errors).flat()[0]) || error?.payload?.message || error?.message || 'Terjadi kesalahan saat memuat presensi.'
}
function classLabel(schedule) {
  const name = schedule?.kelas?.nama || 'Kelas'
  const level = schedule?.kelas?.tingkat
  if (level == null || String(level).trim() === '') return `${name} · Tingkat belum tersedia`
  return `${name} · ${/^tingkat\s/i.test(String(level)) ? level : `Tingkat ${level}`}`
}
function dayLabel(day) {
  return day ? String(day).toLocaleLowerCase('id').replace(/^./, c => c.toLocaleUpperCase('id')) : ''
}
function timeLabel(schedule) {
  return [schedule?.jam_mulai, schedule?.jam_selesai].filter(Boolean).map(time => String(time).slice(0, 5)).join('–')
}
function scheduleDetail(schedule) {
  return [schedule?.mapel?.nama || 'Mata pelajaran', [dayLabel(schedule?.hari), timeLabel(schedule)].filter(Boolean).join(', ')].filter(Boolean).join(' · ')
}
function initials(name) {
  return name.trim().split(/\s+/).slice(0, 2).map(word => word[0]?.toUpperCase()).join('') || '—'
}
function equalRows(a, b) {
  return a.length === b.length && a.every((row, index) => row.siswa_id === b[index]?.siswa_id && row.status === b[index]?.status && row.catatan === b[index]?.catatan)
}

export default function GuruAttendancePage() {
  const { user } = useAuth()
  const guruId = teacherId(user)
  return <AttendanceWorkspace key={`${user?.id ?? ''}:${guruId ?? ''}`} guruId={guruId}/>
}

function AttendanceWorkspace({ guruId }) {
  const [activeYear, setActiveYear] = useState(null)
  const [confirm, setConfirm] = useState(null)
  const [schedules, setSchedules] = useState([])
  const [scheduleId, setScheduleId] = useState('')
  const [date, setDate] = useState(today)
  const [attendanceRows, setAttendanceRows] = useState([])
  const [baseline, setBaseline] = useState([])
  const [loadedKey, setLoadedKey] = useState('')
  const [loadingSchedules, setLoadingSchedules] = useState(true)
  const [loadingStudents, setLoadingStudents] = useState(false)
  const [saving, setSaving] = useState(false)
  const [scheduleError, setScheduleError] = useState('')
  const [rosterError, setRosterError] = useState('')
  const [saveError, setSaveError] = useState('')
  const [notice, setNotice] = useState(null)
  const [scheduleRetry, setScheduleRetry] = useState(0)
  const [rosterRetry, setRosterRetry] = useState(0)
  const saveLock = useRef(false)
  const selectedSchedule = useMemo(() => schedules.find(item => String(item.id) === String(scheduleId)) || null, [schedules, scheduleId])
  const contextKey = selectedSchedule && isAttendanceDate(date) && activeYear ? `${selectedSchedule.id}|${date}|${activeYear.id}` : ''
  const ready = Boolean(contextKey && loadedKey === contextKey && !loadingSchedules && !loadingStudents && !rosterError)
  const rows = ready ? attendanceRows : []
  const dirty = ready && !equalRows(attendanceRows, baseline)
  const allRecorded = rows.length > 0 && baseline.every(row => row.recorded)
  const validStatuses = rows.every(row => STATUS_OPTIONS.some(option => option.value === row.status))
  const options = useMemo(() => schedules.map(item => ({ value: String(item.id), label: classLabel(item), description: scheduleDetail(item) })), [schedules])

  useEffect(() => {
    let cancelled = false
    setLoadingSchedules(true)
    setScheduleError('')
    setSchedules([])
    setActiveYear(null)
    async function loadSchedules() {
      try {
        if (guruId == null) throw new Error('Identitas Guru belum tersedia. Masuk kembali atau hubungi Tata Usaha.')
        const years = await collectPages(page => academicYearApi.getAll({ is_active: 1, per_page: 100, page }))
        const active = years.filter(year => [true, 1, '1'].includes(year.is_active))
        if (active.length !== 1) throw new Error('Periode akademik aktif tidak tersedia atau tidak tunggal. Hubungi Tata Usaha.')
        const own = await loadOwnTeachingSchedules(scheduleApi, guruId, active[0].id)
        if (cancelled) return
        setActiveYear(active[0]); setSchedules(own)
        setScheduleId(current => own.some(item => String(item.id) === String(current)) ? current : '')
      } catch (error) {
        if (!cancelled) { setSchedules([]); setScheduleId(''); setScheduleError(getErrorMessage(error)) }
      } finally { if (!cancelled) setLoadingSchedules(false) }
    }
    loadSchedules()
    return () => { cancelled = true }
  }, [guruId, scheduleRetry])

  useEffect(() => {
    let cancelled = false
    setLoadedKey('')
    setAttendanceRows([])
    setBaseline([])
    setRosterError('')
    if (!contextKey) { setLoadingStudents(false); return }
    setLoadingStudents(true)
    async function load() {
      try {
        const loaded = await loadAttendanceRoster(attendanceApi, selectedSchedule, date, activeYear.id)
        if (cancelled) return
        setAttendanceRows(loaded)
        setBaseline(loaded)
        setLoadedKey(contextKey)
      } catch (error) { if (!cancelled) setRosterError(getErrorMessage(error)) }
      finally { if (!cancelled) setLoadingStudents(false) }
    }
    load()
    return () => { cancelled = true }
  }, [selectedSchedule, date, contextKey, rosterRetry, activeYear])

  function changeContext(setter, value, current) {
    if (saveLock.current || value === current) return
    const apply = () => {
      setNotice(null); setSaveError(''); setRosterError(''); setLoadedKey('')
      setter(value)
    }
    if (dirty) {
      setConfirm({ title: 'Perubahan belum disimpan', description: 'Mengganti jadwal atau tanggal akan membuang isian yang belum disimpan.', label: 'Abaikan perubahan', action: apply })
    } else apply()
  }
  function reloadRoster() {
    if (saveLock.current) return
    const apply = () => { setSaveError(''); setNotice(null); setLoadedKey(''); setRosterRetry(n => n + 1) }
    if (dirty) setConfirm({ title: 'Muat ulang presensi?', description: 'Perubahan yang belum disimpan akan dibuang dan data server dimuat ulang.', label: 'Muat ulang', action: apply })
    else apply()
  }
  function updateRow(studentId, field, value) {
    if (!ready || saveLock.current) return
    setNotice(null); setSaveError('')
    setAttendanceRows(current => current.map(row => String(row.siswa_id) === String(studentId) ? { ...row, [field]: value } : row))
  }
  function allPresent() {
    if (!ready || saveLock.current) return
    setNotice(null); setSaveError('')
    const apply = () => setAttendanceRows(current => current.map(row => ({ ...row, status: 'HADIR' })))
    if (rows.some(row => row.status && row.status !== 'HADIR')) {
      setConfirm({ title: 'Tandai semua siswa hadir?', description: 'Status izin, sakit, dan alpa yang ada pada formulir akan diganti menjadi hadir. Data belum disimpan hingga Anda menekan Simpan.', label: 'Tandai semua hadir', action: apply })
    } else apply()
  }
  function reset() {
    if (!ready || saveLock.current) return
    setAttendanceRows(baseline); setNotice(null); setSaveError('')
  }
  async function saveAttendance() {
    if (!ready || !rows.length || !validStatuses || saveLock.current || loadingSchedules) return
    saveLock.current = true
    setSaving(true); setNotice(null); setSaveError('')
    try {
      await attendanceApi.bulkSave({ jadwal_pelajaran_id: Number(selectedSchedule.id), tanggal: date, presensi: rows.map(row => ({ siswa_id: Number(row.siswa_id), status: row.status, catatan: row.catatan.trim() || null })) })
      setNotice({ type: 'success', title: 'Presensi berhasil disimpan', message: 'Kehadiran siswa telah tercatat untuk jadwal dan tanggal terpilih.' })
      const saved = rows.map(row => ({ ...row, catatan: row.catatan.trim(), recorded: true }))
      setAttendanceRows(saved); setBaseline(saved)
    } catch (error) {
      const message = getErrorMessage(error)
      setSaveError(message)
      setNotice({ type: 'error', title: 'Presensi gagal disimpan', message })
    }
    finally { saveLock.current = false; setSaving(false) }
  }
  const year = activeYear
  const yearLabel = year?.tahun_mulai && year?.tahun_selesai ? `${year.tahun_mulai}/${year.tahun_selesai}` : year?.tahun ?? year?.nama
  const period = [yearLabel, year?.semester ? `Semester ${dayLabel(year.semester)}` : ''].filter(Boolean).join(' · ')
  return <div className="guru-attendance-v2">
    <header className="page-intro"><div><span className="eyebrow">KEGIATAN MENGAJAR</span><h1>Presensi Kelas</h1><p>Catat kehadiran siswa dalam satu pertemuan, sesuai jadwal mengajar Anda.</p></div></header>
    <ToastNotification notice={notice} onClose={() => setNotice(null)}/>
    {confirm && <ConfirmDialog title={confirm.title} description={confirm.description} confirmLabel={confirm.label} onClose={() => setConfirm(null)} onConfirm={() => { const action = confirm.action; setConfirm(null); action() }}/>}
    <section className="control-panel" aria-labelledby="attendance-session-heading">
      <div className="section-head"><div><div className="step-label">01 / KONTEKS PERTEMUAN</div><h2 id="attendance-session-heading">Pilih jadwal dan tanggal</h2></div>{period && <span className="period">{period}</span>}</div>
      {scheduleError && <div className="state-wrap"><ErrorState message={scheduleError}/><button type="button" className="secondary-btn" onClick={() => setScheduleRetry(n => n + 1)}>Muat ulang jadwal</button></div>}
      <div className="filter-grid">
        <SearchableSelect label="Jadwal mengajar / kelas" value={scheduleId} options={options} required disabled={loadingSchedules || saving || !schedules.length} placeholder={loadingSchedules ? 'Memuat jadwal…' : schedules.length ? 'Pilih jadwal mengajar' : 'Jadwal belum tersedia'} onChange={value => changeContext(setScheduleId, value, scheduleId)}/>
        <div className="date-field"><label htmlFor="guru-attendance-date">Tanggal presensi <span>*</span></label><input id="guru-attendance-date" type="date" required value={date} disabled={saving} onChange={event => changeContext(setDate, event.target.value, date)}/></div>
      </div>
      {selectedSchedule && isAttendanceDate(date) && weekdayMismatch(selectedSchedule, date) && <p className="default-note" role="status">Tanggal yang dipilih berbeda dari hari jadwal. Pastikan pertemuan pengganti atau pencatatan ini benar sebelum menyimpan.</p>}
      {selectedSchedule && <div className="context-strip"><div className="context-icon" aria-hidden="true">▦</div><div><small>KELAS TERPILIH</small><strong>{classLabel(selectedSchedule)}</strong></div><div><small>MATA PELAJARAN</small><strong>{selectedSchedule.mapel?.nama || '—'}</strong></div><div><small>HARI / JAM JADWAL</small><strong>{[dayLabel(selectedSchedule.hari), timeLabel(selectedSchedule)].filter(Boolean).join(' · ') || '—'}</strong></div><div><small>JUMLAH SISWA</small><strong>{ready ? `${rows.length} siswa` : '—'}</strong></div></div>}
    </section>
    <section className="roster-panel" aria-labelledby="attendance-roster-heading" aria-busy={loadingStudents || saving}>
      <div className="section-head roster-head"><div><div className="step-label">02 / PENCATATAN KEHADIRAN</div><h2 id="attendance-roster-heading">Daftar siswa</h2><p>Atur status setiap siswa, tambahkan catatan bila diperlukan.</p></div><button className="secondary-btn" type="button" disabled={!ready || !rows.length || saving} onClick={allPresent}>✓ Tandai semua hadir</button></div>
      {!selectedSchedule || !isAttendanceDate(date) ? <div className="state-wrap"><EmptyState title="Jadwal dan tanggal diperlukan" description="Pilih jadwal mengajar dan tanggal untuk menampilkan daftar siswa."/></div> : rosterError ? <div className="state-wrap"><ErrorState message={rosterError}/><button className="secondary-btn" type="button" onClick={() => setRosterRetry(n => n + 1)}>Muat ulang daftar siswa</button></div> : !ready ? <div className="state-wrap"><LoadingState message="Memuat daftar siswa dan presensi…"/></div> : !rows.length ? <div className="state-wrap"><EmptyState title="Siswa tidak ditemukan" description="Tidak ada siswa untuk kelas dan tanggal ini. Pastikan anggota kelas sudah terdaftar."/></div> : <>
        <AttendanceSummary rows={rows}/>
        <div className="roster-caption"><span>DAFTAR KEHADIRAN · ISIAN FORM</span><span>{rows.length} siswa</span></div>
        {!allRecorded && <p className="default-note">Siswa tanpa catatan terdahulu belum memiliki status. Pilih status setiap siswa atau gunakan Tandai semua hadir.</p>}
        {!validStatuses && <p className="default-note" role="alert">Tentukan status yang valid untuk setiap siswa sebelum menyimpan.</p>}
        <div className="roster-grid table-head" aria-hidden="true"><span>Siswa / NIS/NISN</span><span>Status kehadiran</span><span>Catatan <small>(opsional)</small></span></div>
        <ol className="student-list">{rows.map((row, index) => <li className="roster-grid student-row" key={row.siswa_id}>
          <div className="student-id"><span className="student-no" aria-hidden="true">{String(index + 1).padStart(2, '0')}</span><span className="student-avatar" aria-hidden="true">{initials(row.nama_lengkap)}</span><div><strong>{row.nama_lengkap}</strong><small>NIS/NISN {row.nis}</small></div></div>
          <div><span className="mobile-label">Status kehadiran</span><AttendanceStatusSelector row={row} disabled={saving} onChange={value => updateRow(row.siswa_id, 'status', value)}/></div>
          <div><label className="mobile-label" htmlFor={`note-${row.siswa_id}`}>Catatan (opsional)</label><input id={`note-${row.siswa_id}`} className="note-input" aria-label={`Catatan ${row.nama_lengkap}`} placeholder="Tambahkan catatan" maxLength={1000} disabled={saving} value={row.catatan} onChange={event => updateRow(row.siswa_id, 'catatan', event.target.value)}/></div>
        </li>)}</ol>
        {saveError && <div className="state-wrap"><ErrorState message={saveError}/><button type="button" className="secondary-btn" disabled={saving} onClick={reloadRoster}>Muat ulang data presensi</button></div>}
        <AttendanceActionBar count={rows.length} dirty={dirty} allRecorded={allRecorded} saving={saving} disabled={saving || !validStatuses || loadingSchedules} resetDisabled={saving || loadingSchedules} onReset={reset} onSave={saveAttendance}/>
      </>}
    </section>
  </div>
}
