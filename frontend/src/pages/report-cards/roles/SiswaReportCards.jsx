import { useCallback, useEffect, useRef, useState } from 'react'
import { reportCardApi } from '../../../services/reportCardApi'
import { REPORT_TYPES, PAGE_SIZE, parseReportCardsResponse, academicPeriod, readableSize, displayDate, availablePeriods } from './studentReportCardsView'
import '../styles/SiswaReportCardsV2.css'

export default function SiswaReportCards() {
  const [records, setRecords] = useState([])
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(null)
  const [filters, setFilters] = useState({ type: '', academic_year_id: '' })
  const [periods, setPeriods] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [downloadError, setDownloadError] = useState('')
  const [downloadingId, setDownloadingId] = useState(null)
  const generation = useRef(0)

  const load = useCallback(async (requestedPage = 1, currentFilters = { type: '', academic_year_id: '' }) => {
    const requestId = ++generation.current
    setLoading(true)
    setError('')
    try {
      const response = await reportCardApi.getReportCards({ page: requestedPage, per_page: PAGE_SIZE, ...currentFilters })
      const result = parseReportCardsResponse(response)
      if (requestId !== generation.current) return
      setRecords(result.records)
      setPage(result.page)
      setLastPage(result.lastPage)
      setTotal(result.total)
    } catch (err) {
      if (requestId !== generation.current) return
      setRecords([])
      setPage(1)
      setLastPage(1)
      setTotal(null)
      setError(err?.message || 'Gagal mengambil rapor dari server.')
    } finally {
      if (requestId === generation.current) setLoading(false)
    }
  }, [])

  useEffect(() => {
    // Ambil opsi tahun dari endpoint yang sama: tidak meminta identitas siswa lain.
    let live = true
    async function getPeriods() {
      try {
        const found = []
        let cursor = 1
        let pages = 1
        do {
          const result = parseReportCardsResponse(await reportCardApi.getReportCards({ page: cursor, per_page: 100 }))
          if (!live) return
          found.push(...result.records)
          pages = result.lastPage
          cursor += 1
          if (pages > 1000) throw new Error('Daftar periode terlalu besar.')
        } while (cursor <= pages)
        if (live) setPeriods(availablePeriods(found))
      } catch { if (live) setPeriods([]) }
    }
    getPeriods()
    return () => { live = false }
  }, [])

  useEffect(() => {
    load(1, filters)
    return () => { generation.current += 1 }
  }, [filters, load])

  async function handleDownload(item) {
    setDownloadError('')
    setDownloadingId(item.id)
    try {
      const blob = await reportCardApi.downloadReportCard(item.id)
      if (!(blob instanceof Blob)) throw new Error('Berkas unduhan tidak valid.')
      const url = URL.createObjectURL(blob)
      const anchor = document.createElement('a')
      anchor.href = url
      anchor.download = item.file?.name || `rapor-${item.id}.pdf`
      document.body.appendChild(anchor)
      anchor.click()
      anchor.remove()
      window.setTimeout(() => URL.revokeObjectURL(url), 1500)
    } catch (err) { setDownloadError(err?.message || 'Gagal mengunduh rapor.') }
    finally { setDownloadingId(null) }
  }

  return (
    <section className="student-report-v2">
      <header className="srv-heading">
        <div><span className="srv-eyebrow">AKADEMIK / RAPOR SISWA</span><h1>Rapor akademik</h1><p>Dokumen rapor resmi yang diunggah Tata Usaha untuk akunmu.</p></div>
        <button type="button" className="srv-button" disabled={loading} onClick={() => load(page, filters)}>↻ Perbarui data</button>
      </header>
      <section className="srv-panel srv-filter" aria-label="Filter rapor">
        <div><label htmlFor="srv-year">Periode akademik</label><select id="srv-year" value={filters.academic_year_id} onChange={e => setFilters(old => ({ ...old, academic_year_id: e.target.value }))}><option value="">Semua periode</option>{periods.map(p => <option key={p.id} value={p.id}>{p.label}</option>)}</select></div>
        <div><label htmlFor="srv-type">Jenis rapor</label><select id="srv-type" value={filters.type} onChange={e => setFilters(old => ({ ...old, type: e.target.value }))}><option value="">Semua jenis</option>{Object.entries(REPORT_TYPES).map(([code, label]) => <option value={code} key={code}>{label}</option>)}</select></div>
        <div className="srv-filter-action"><button className="srv-button" type="button" onClick={() => setFilters({ type: '', academic_year_id: '' })}>Reset filter</button></div>
      </section>
      <div className="srv-subhead"><div><h2>Dokumen rapor</h2><p>{total === null ? 'Daftar dokumen berdasarkan filter' : `${total} dokumen ditemukan`}</p></div>{loading && <span className="srv-loading" role="status">Memuat data…</span>}</div>
      {error ? <div className="srv-alert" role="alert">{error} <button type="button" onClick={() => load(page, filters)}>Coba lagi</button></div> : null}
      {downloadError ? <div className="srv-alert" role="alert">{downloadError}<button type="button" onClick={() => setDownloadError('')}>Tutup</button></div> : null}
      {!error && !loading && records.length === 0 && <div className="srv-empty"><span aria-hidden="true">▤</span><h3>Belum ada dokumen rapor</h3><p>{filters.type || filters.academic_year_id ? 'Tidak ada rapor yang cocok dengan filter ini.' : 'Rapor akan muncul di sini setelah Tata Usaha mengunggahnya.'}</p></div>}
      {!error && records.length > 0 && <div className="srv-list" aria-busy={loading}>
        {records.map(item => <article className="srv-item" key={item.id}>
          <div className="srv-file-icon" aria-hidden="true">▤</div>
          <div className="srv-info"><span className="srv-type">{REPORT_TYPES[item.type]}</span><h3>{item.file.name || 'Dokumen rapor'}</h3><p>{academicPeriod(item)}</p><span className="srv-meta">{readableSize(item.file.size)} <span aria-hidden="true">·</span> Diunggah {displayDate(item.uploaded_at)}</span></div>
          <button className="srv-button srv-download" type="button" disabled={downloadingId === item.id} onClick={() => handleDownload(item)}>{downloadingId === item.id ? 'Mengunduh…' : '↓ Unduh'}</button>
        </article>)}
      </div>}
      {!error && lastPage > 1 && <nav className="srv-pagination" aria-label="Halaman dokumen"><button type="button" disabled={loading || page <= 1} onClick={() => load(page - 1, filters)}>← Sebelumnya</button><span>Halaman {page} dari {lastPage}</span><button type="button" disabled={loading || page >= lastPage} onClick={() => load(page + 1, filters)}>Berikutnya →</button></nav>}
      <p className="srv-footnote">Dokumen ini berasal dari unggahan Tata Usaha. Nilai pada halaman Penilaian tidak otomatis menjadi rapor resmi.</p>
    </section>
  )
}
