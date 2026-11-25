import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { academicYearApi } from '../../../services/academicYearApi'
import { classApi } from '../../../services/classApi'
import { subjectApi } from '../../../services/subjectApi'
import { teacherApi } from '../../../services/teacherApi'
import { reportApi } from '../../../services/reportApi'
import {
  PRINCIPAL_REPORT_TYPES, PRINCIPAL_REPORT_FIELDS, REPORT_FILE_LABEL,
  formatReportPeriod, loadEntireCatalog, makePrincipalExportParams, parseYears,
} from './principalReportContract.js'
import { makePreparedDownload, makeSharedFile, isNativeFileShareAvailable } from './principalReportDownload.js'
import './KepalaSekolahReports.css'

function SvgIcon({ name, size = 19 }) {
  const paths = {
    download: 'M12 3v12m-5-5 5 5 5-5M4 17v4h16v-4',
    doc: 'M5 3h10l4 4v14H5z M14 3v5h5 M8 12h8m-8 4h8',
    table: 'M3 5h18v14H3z M3 11h18 M9 5v14',
    shield: 'M12 2 4 6v6c0 5 4 8 8 10 4-2 8-5 8-10V6z M9 12l2 2 4-4',
    refresh: 'M20 11a8 8 0 1 0-2 6m2-6v-6m0 6h-6',
    info: 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20 M12 11v6m0-10v1',
    calendar: 'M3 5h18v16H3z M7 3v4m10-4v4M3 10h18',
  }
  return <svg aria-hidden="true" width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round"><path d={paths[name] || paths.doc}/></svg>
}

const emptyFilter = () => ({ class_id: '', subject_id: '', teacher_id: '', search: '', date_from: '', date_to: '' })
const choose = (rows, labelFn) => rows.map(item => ({ id: String(item.id), label: labelFn(item) }))

function SelectField({ label, value, onChange, options, placeholder, disabled }) {
  return <label className="ks-report__field"><span>{label}</span><select value={value} onChange={e => onChange(e.target.value)} disabled={disabled}><option value="">{placeholder}</option>{options.map(o => <option value={o.id} key={o.id}>{o.label}</option>)}</select></label>
}

export default function KepalaSekolahReports() {
  const [type, setType] = useState('attendance')
  const [format, setFormat] = useState('pdf')
  const [years, setYears] = useState([])
  const [yearId, setYearId] = useState('')
  const [yearBusy, setYearBusy] = useState(true)
  const [yearError, setYearError] = useState('')
  const [refreshKey, setRefreshKey] = useState(0)
  const [catalog, setCatalog] = useState({ classes: [], subjects: [], teachers: [] })
  const [catalogBusy, setCatalogBusy] = useState(false)
  const [catalogError, setCatalogError] = useState('')
  const [filters, setFilters] = useState(emptyFilter)
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState(null)
  const [prepared, setPrepared] = useState(null)
  const preparedRef = useRef(null)
  const downloading = useRef(false)
  const mountedRef = useRef(false)

  const discardPrepared = useCallback(() => {
    const previous = preparedRef.current
    preparedRef.current = null
    if (previous) URL.revokeObjectURL(previous.url)
    setPrepared(null)
  }, [])

  useEffect(() => {
    mountedRef.current = true
    return () => {
      mountedRef.current = false
      if (preparedRef.current) {
        URL.revokeObjectURL(preparedRef.current.url)
        preparedRef.current = null
      }
    }
  }, [])

  const fields = PRINCIPAL_REPORT_FIELDS[type] || []
  const selectedYear = years.find(y => String(y.id) === yearId)
  const needsYear = fields.includes('academic_year_id')
  const needsClass = fields.includes('class_id')
  const needsSubject = fields.includes('subject_id')
  const needsTeacher = fields.includes('teacher_id')

  useEffect(() => {
    let alive = true
    setYearBusy(true)
    setYearError('')
    academicYearApi.getAll().then(response => {
      if (!alive) return
      const result = parseYears(response)
      setYears(result)
      setYearId(current => result.some(y => String(y.id) === current) ? current : String(result.find(y => y.is_active)?.id ?? result[0]?.id ?? ''))
    }).catch(error => {
      if (!alive) return
      setYears([])
      setYearId('')
      setYearError(error?.message || 'Tahun akademik gagal dimuat.')
    }).finally(() => { if (alive) setYearBusy(false) })
    return () => { alive = false }
  }, [refreshKey])

  useEffect(() => {
    let alive = true
    setCatalog({ classes: [], subjects: [], teachers: [] })
    setCatalogError('')
    const requests = []
    if (needsClass && yearId) {
      requests.push(['classes', loadEntireCatalog(page => classApi.getAll({ academic_year_id: yearId, per_page: 100, page }))])
    }
    if (needsSubject) {
      requests.push(['subjects', loadEntireCatalog(page => subjectApi.getAll({ per_page: 100, page }))])
    }
    if (needsTeacher) {
      requests.push(['teachers', loadEntireCatalog(page => teacherApi.getAll({ page_size: 100, page }))])
    }
    if (requests.length === 0) { setCatalogBusy(false); return undefined }
    setCatalogBusy(true)
    Promise.all(requests.map(([, promise]) => promise)).then(results => {
      if (alive) setCatalog(Object.fromEntries(requests.map(([key], index) => [key, results[index]])))
    }).catch(error => {
      if (alive) { setCatalog({ classes: [], subjects: [], teachers: [] }); setCatalogError(error?.message || 'Daftar filter gagal dimuat.') }
    }).finally(() => { if (alive) setCatalogBusy(false) })
    return () => { alive = false }
  }, [type, yearId, refreshKey, needsClass, needsSubject, needsTeacher])

  const changeType = useCallback(next => {
    if (downloading.current) return
    discardPrepared()
    setType(next)
    setFilters(emptyFilter())
    setNotice(null)
  }, [discardPrepared])

  const changeYear = useCallback(next => {
    discardPrepared()
    setYearId(next)
    setFilters(emptyFilter())
    setNotice(null)
  }, [discardPrepared])

  const setFilter = (key, value) => {
    discardPrepared()
    setFilters(current => ({ ...current, [key]: value }))
    setNotice(null)
  }

  const fieldOptions = useMemo(() => ({
    classes: choose(catalog.classes || [], c => `${({ 10: 'X', 11: 'XI', 12: 'XII' })[Number(c.tingkat)] || `Tingkat ${c.tingkat}`} ${c.nama}`),
    subjects: choose(catalog.subjects || [], m => `${m.nama}${m.kode ? ` · ${m.kode}` : ''}`),
    teachers: choose(catalog.teachers || [], g => g.nama_lengkap || `Guru #${g.id}`),
  }), [catalog])

  const disabled = busy || yearBusy || catalogBusy
  const exportDisabled = disabled || Boolean(catalogError) || (needsYear && (!yearId || Boolean(yearError)))
  const reportName = PRINCIPAL_REPORT_TYPES.find(r => r.id === type)?.label || 'Laporan'

  async function download(event) {
    event.preventDefault()
    if (downloading.current || exportDisabled) return
    let params
    try {
      params = makePrincipalExportParams(type, yearId, filters, format, years)
      if (params.class_id && !fieldOptions.classes.some(c => Number(c.id) === params.class_id)) throw new Error('Kelas di luar periode yang dipilih.')
      if (params.subject_id && !fieldOptions.subjects.some(m => Number(m.id) === params.subject_id)) throw new Error('Mata pelajaran tidak tersedia.')
      if (params.teacher_id && !fieldOptions.teachers.some(g => Number(g.id) === params.teacher_id)) throw new Error('Guru tidak tersedia.')
    } catch (error) {
      setNotice({ kind: 'error', text: error.message })
      return
    }
    downloading.current = true
    setBusy(true)
    setNotice(null)
    try {
      const blob = await reportApi.downloadReport(type, params)
      if (!mountedRef.current) return
      const filename = `laporan-${REPORT_FILE_LABEL[type]}${needsYear ? `-${selectedYear.tahun_mulai}-${selectedYear.semester.toLowerCase()}` : ''}.${format === 'excel' ? 'xlsx' : 'pdf'}`
      const next = makePreparedDownload(blob, filename, format)
      discardPrepared()
      preparedRef.current = next
      setPrepared(next)
      setNotice({ kind: 'success', text: 'Dokumen sudah siap. Ketuk tautan Unduh untuk menyimpan file. Jika tidak muncul di unduhan browser, gunakan Buka PDF atau Bagikan / Simpan ke File bila tersedia.' })
    } catch (error) {
      setNotice({ kind: 'error', text: error?.message || 'Laporan tidak dapat diunduh.' })
    } finally {
      downloading.current = false
      setBusy(false)
    }
  }

  const shareAvailable = isNativeFileShareAvailable(prepared)
  async function shareDocument() {
    if (!prepared || !isNativeFileShareAvailable(prepared)) return
    try {
      await navigator.share({ files: [makeSharedFile(prepared)], title: 'Laporan SIAKAD' })
      setNotice({ kind: 'success', text: 'Dialog berbagi telah ditutup. Pastikan dokumen disimpan melalui aplikasi tujuan.' })
    } catch (error) {
      if (error?.name !== 'AbortError') setNotice({ kind: 'error', text: error?.message || 'Tidak dapat membuka dialog berbagi.' })
    }
  }

  return <main className="ks-report" aria-label="Workspace laporan Kepala Sekolah">
    <header className="ks-report__hero">
      <div className="ks-report__hero-copy">
        <span className="ks-report__eyebrow"><span className="ks-report__dot"/> DOKUMEN AKADEMIK · KEPALA SEKOLAH</span>
        <h1>Laporan sekolah,<br/><em>rapi dan terarah.</em></h1>
        <p>Pilih laporan sesuai kebutuhan monitoring, atur cakupan data, lalu unduh laporan yang dihasilkan sistem dalam format PDF atau Excel.</p>
        <div className="ks-report__hero-tags"><span><SvgIcon name="shield" size={14}/> Akses baca &amp; ekspor</span><span><SvgIcon name="calendar" size={14}/> Berbasis periode akademik</span></div>
      </div>
      <div className="ks-report__hero-note" aria-label="Format laporan tersedia"><small>FORMAT TERSEDIA</small><strong>PDF <span>/</span> EXCEL</strong><small>Diotorisasi oleh server</small></div>
    </header>

    <section className="ks-report__panel" aria-labelledby="ks-report-types-title">
      <div className="ks-report__section-heading"><div><span className="ks-report__kicker">01 · JENIS DOKUMEN</span><h2 id="ks-report-types-title">Pilih laporan yang diperlukan</h2><p>Tujuh jenis laporan dari layanan pelaporan SIAKAD yang sudah tersedia.</p></div></div>
      <div className="ks-report__type-grid" role="group" aria-label="Jenis laporan">
        {PRINCIPAL_REPORT_TYPES.map(item => <button type="button" className={`ks-report__type ${item.id === type ? 'is-selected' : ''}`} aria-pressed={item.id === type} key={item.id} onClick={() => changeType(item.id)} disabled={busy}>
          <span className="ks-report__type-mark">{item.mark}</span><strong>{item.label}</strong><small>{item.caption}</small><span className="ks-report__type-check" aria-hidden="true">{item.id === type ? '✓' : '↗'}</span>
        </button>)}
      </div>
    </section>

    <section className="ks-report__panel" aria-labelledby="ks-report-filters-title">
      <div className="ks-report__section-heading"><div><span className="ks-report__kicker">02 · CAKUPAN DATA</span><h2 id="ks-report-filters-title">Filter {reportName}</h2><p>Hanya data dalam cakupan yang dipilih. Filter yang tidak didukung tidak dikirim ke API.</p></div><button type="button" className="ks-report__quiet" disabled={busy} onClick={() => {discardPrepared(); setFilters(emptyFilter()); setNotice(null)}}>Reset filter</button></div>
      {yearError && needsYear && <div className="ks-report__alert" role="alert">{yearError} <button type="button" onClick={() => { discardPrepared(); setRefreshKey(v => v + 1) }}>Coba lagi</button></div>}
      {catalogError && <div className="ks-report__alert" role="alert">{catalogError} <button type="button" onClick={() => { discardPrepared(); setRefreshKey(v => v + 1) }}>Muat ulang</button></div>}
      <form id="ks-report-form" onSubmit={download} className="ks-report__form">
        <div className="ks-report__fields">
          {needsYear && <label className="ks-report__field"><span>Tahun Akademik / Semester <b aria-hidden="true">*</b></span><select value={yearId} onChange={e => changeYear(e.target.value)} disabled={disabled || Boolean(yearError)} required><option value="">{yearBusy ? 'Memuat periode…' : 'Pilih periode akademik'}</option>{years.map(y => <option value={y.id} key={y.id}>{formatReportPeriod(y)}</option>)}</select></label>}
          {needsClass && <SelectField label="Kelas" placeholder={yearId ? 'Semua kelas' : 'Pilih periode dahulu'} value={filters.class_id} onChange={v => setFilter('class_id', v)} options={fieldOptions.classes} disabled={disabled || !yearId || Boolean(catalogError)}/>}
          {needsSubject && <SelectField label="Mata Pelajaran" placeholder="Semua mata pelajaran" value={filters.subject_id} onChange={v => setFilter('subject_id', v)} options={fieldOptions.subjects} disabled={disabled || Boolean(catalogError)}/>}
          {needsTeacher && <SelectField label="Guru" placeholder="Semua guru" value={filters.teacher_id} onChange={v => setFilter('teacher_id', v)} options={fieldOptions.teachers} disabled={disabled || Boolean(catalogError)}/>}
          {fields.includes('search') && <label className="ks-report__field"><span>Cari {type === 'teachers' ? 'guru' : 'siswa'}</span><input type="search" maxLength={100} value={filters.search} placeholder={type === 'teachers' ? 'Nama atau NIP…' : 'Nama, NISN, atau NIS…'} onChange={e => setFilter('search', e.target.value)} disabled={busy}/></label>}
          {fields.includes('date_from') && <label className="ks-report__field"><span>Tanggal mulai</span><input type="date" value={filters.date_from} max={filters.date_to || undefined} onChange={e => setFilter('date_from', e.target.value)} disabled={busy}/></label>}
          {fields.includes('date_to') && <label className="ks-report__field"><span>Tanggal selesai</span><input type="date" min={filters.date_from || undefined} value={filters.date_to} onChange={e => setFilter('date_to', e.target.value)} disabled={busy}/></label>}
        </div>
        <div className="ks-report__context"><SvgIcon name="info" size={17}/><span>{needsYear ? `Periode dipilih: ${formatReportPeriod(selectedYear)}. Semester mengikuti periode ini, bukan filter kedua.` : 'Laporan guru tidak menggunakan filter periode. Status akun guru tidak tersedia bagi Kepala Sekolah.'} {type === 'attendance' ? 'Catatan presensi dihitung per jadwal/pertemuan tersimpan, bukan per hari unik.' : ''}{type === 'grades' || type === 'semester-recap' ? ' Hasil rekap bukan rapor akhir.' : ''}</span></div>
      </form>
    </section>

    <section className="ks-report__panel ks-report__export" aria-labelledby="ks-report-export-title">
      <div className="ks-report__section-heading"><div><span className="ks-report__kicker">03 · FORMAT DOKUMEN</span><h2 id="ks-report-export-title">Unduh laporan</h2><p>Hasil PDF atau Excel dibuat dari server sesuai filter dan kewenangan Kepala Sekolah.</p></div><span className="ks-report__pill"><SvgIcon name="shield" size={15}/> Baca saja</span></div>
      <div className="ks-report__export-grid">
        <div className="ks-report__format-group" role="radiogroup" aria-label="Format laporan">
          <label className={`ks-report__format ${format === 'pdf' ? 'is-selected' : ''}`}><input type="radio" value="pdf" name="ks-report-format" checked={format === 'pdf'} onChange={() => { discardPrepared(); setFormat('pdf'); setNotice(null) }} disabled={busy}/><SvgIcon name="doc" size={23}/><span><strong>PDF</strong><small>Dokumen siap cetak</small></span></label>
          <label className={`ks-report__format ${format === 'excel' ? 'is-selected' : ''}`}><input type="radio" value="excel" name="ks-report-format" checked={format === 'excel'} onChange={() => { discardPrepared(); setFormat('excel'); setNotice(null) }} disabled={busy}/><SvgIcon name="table" size={23}/><span><strong>Excel</strong><small>Data tabular XLSX</small></span></label>
        </div>
        {prepared
          ? <a className="ks-report__download" href={prepared.url} download={prepared.filename} aria-label={`Unduh ${prepared.filename}`}><SvgIcon name="download"/>Unduh {format === 'pdf' ? 'PDF' : 'Excel'}</a>
          : <button type="submit" form="ks-report-form" className="ks-report__download" disabled={exportDisabled} aria-busy={busy}><SvgIcon name="download"/>{busy ? 'Menyiapkan dokumen…' : `Siapkan ${format === 'pdf' ? 'PDF' : 'Excel'}`}</button>}
      </div>
      {prepared && <div className="ks-report__ready" role="group" aria-label="Pilihan untuk dokumen yang sudah siap">
        <p>File <strong>{prepared.filename}</strong> siap. Gunakan tautan Unduh di atas untuk menyimpan. Browser mungkin meminta lokasi penyimpanan.</p>
        <div className="ks-report__ready-actions">
          {format === 'pdf' && <a href={prepared.url} target="_blank" rel="noopener noreferrer" className="ks-report__ready-link">Buka PDF</a>}
          {shareAvailable && <button type="button" className="ks-report__ready-link" onClick={shareDocument}>Bagikan / Simpan ke File</button>}
          <button type="button" className="ks-report__ready-link" onClick={() => { discardPrepared(); setNotice(null) }}>Buat ulang dokumen</button>
        </div>
      </div>}
      <p className="ks-report__fineprint">Pratinjau dalam aplikasi saat ini hanya tersedia untuk Guru. Kepala Sekolah menggunakan ekspor terproteksi yang sudah tersedia; bukan pratinjau buatan. Kebijakan distribusi dokumen berisi identitas siswa/guru perlu mengikuti persetujuan sekolah.</p>
      {notice && <div className={`ks-report__feedback is-${notice.kind}`} role={notice.kind === 'error' ? 'alert' : 'status'}>{notice.text}</div>}
    </section>
    <div className="ks-report__footnote"><SvgIcon name="info" size={17}/><span>Rekap semester menggunakan nilai komponen yang tercatat dan catatan presensi tersimpan. Tidak membentuk rumus rapor atau menyimpulkan pembelajaran telah terlaksana.</span></div>
  </main>
}
