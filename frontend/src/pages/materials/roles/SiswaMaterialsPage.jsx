import { useCallback, useEffect, useRef, useState } from 'react'
import { academicYearApi } from '../../../services/academicYearApi'
import {apiClient} from '../../../services/apiClient'
import {periodCatalog} from '../../../utils/studentWorkspace.js'
import PreparedStudentFile,{usePreparedStudentFile} from '../../../components/ui/PreparedStudentFile.jsx'
import { studentMaterialWorkspaceApi } from '../../../services/studentMaterialWorkspaceApi'
import {
  fileKind, fileSize, formatDateWib, parseMaterialsWorkspace, uniqueTeachers,
} from './studentMaterialsView'
import './SiswaMaterialsPage.css'

function errorMessage(error) {
  return error?.payload?.message || error?.response?.data?.message || error?.message || 'Bahan ajar tidak berhasil dimuat.'
}

export default function SiswaMaterialsPage() {
  const [years, setYears] = useState([])
  const [yearId, setYearId] = useState('')
  const [subjectId, setSubjectId] = useState(null)
  const [workspace, setWorkspace] = useState(null)
  const [loading, setLoading] = useState(true)
  const [detailLoading, setDetailLoading] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [search, setSearch] = useState('')
  const [sort, setSort] = useState('newest')
  const [page, setPage] = useState(1)
  const [downloading, setDownloading] = useState(null)
  const generation = useRef(0)
  const fileState=usePreparedStudentFile(`${yearId}:${subjectId}:${page}:${search}:${sort}`)

  useEffect(() => {
    let live = true
    academicYearApi.getAll().then((r) => {
      if (!live) return
      const {years:list,activeId}=periodCatalog(r);setYears(list);setYearId(activeId);if(!activeId)setLoading(false)
    }).catch((err) => {
      if (live) { setError(errorMessage(err)); setLoading(false) }
    })
    return () => { live = false; generation.current += 1 }
  }, [])

  const load = useCallback(async (selectedYear, selectedSubject, selectedPage, term, order) => {
    const requestId = ++generation.current
    if (!selectedYear) { setWorkspace(null); setLoading(false); setDetailLoading(false); return }
    if (selectedSubject) setDetailLoading(true)
    else setLoading(true)
    setError('')
    try {
      const response = await studentMaterialWorkspaceApi.get({
        academic_year_id: selectedYear,
        ...(selectedSubject ? { subject_id: selectedSubject, page: selectedPage, per_page: 10, search: term.trim(), sort: order } : {}),
      })
      const result = parseMaterialsWorkspace(response)
      if (requestId !== generation.current) return
      if(String(result.academic_year.id)!==String(selectedYear))throw new Error('Periode respons berbeda.');setWorkspace(result)
    } catch (err) {
      if (requestId !== generation.current) return
      setError(errorMessage(err))
      setWorkspace(null)
    } finally {
      if (requestId === generation.current) { setLoading(false); setDetailLoading(false) }
    }
  }, [])

  useEffect(() => {
    setWorkspace(null)
    setSubjectId(null)
    setSearch('')
    setSort('newest')
    setPage(1)
    load(yearId, null, 1, '', 'newest')
  }, [yearId, load])

  // Clicking a card changes React state only; the page is never navigated/reloaded.
  function openSubject(id) {
    setSubjectId(id)
    setSearch('')
    setSort('newest')
    setPage(1)
    load(yearId, id, 1, '', 'newest')
  }
  function back() {
    setSubjectId(null)
    setSearch('')
    setPage(1)
    load(yearId, null, 1, '', sort)
  }
  function changePage(next) {
    setPage(next)
    load(yearId, subjectId, next, search, sort)
  }
  async function download(material, file, index) {
    const key = `${material.id}-${file.id ?? index}`
    setDownloading(key)
    setError('')
    setNotice('')
    try {
      const path=file.id!=null?`/materials/${material.id}/attachments/${file.id}/download`:`/materials/${material.id}/download`
      await fileState.prepare(()=>apiClient.download(path),file.name||'bahan-ajar')
    } catch (err) { setError(errorMessage(err)) }
    finally { setDownloading(null) }
  }

  const activeSubject = workspace?.subjects?.find((s) => s.id === subjectId)
  const materials = workspace?.materials
  const totalMaterials = workspace?.subjects?.reduce((sum, s) => sum + Number(s.material_count || 0), 0) ?? 0

  return (
    <main className="smv2-page">
      <div className="smv2-heading">
        <div><p className="smv2-eyebrow">PEMBELAJARAN / BAHAN AJAR</p><h1>Bahan ajar</h1><p>Materi dan lampiran dari guru pada kelas dan periode akademikmu.</p></div>
        <button type="button" className="smv2-button" onClick={() => {fileState.clear();load(yearId, subjectId, page, search, sort)}} disabled={!yearId || loading || detailLoading}>Perbarui data</button>
      </div>
      {error && <div className="smv2-alert" role="alert">{error}</div>}
      <PreparedStudentFile state={fileState}/>{notice && <div className="smv2-notice" role="status">{notice}</div>}
      <section className="smv2-context" aria-label="Konteks akademik">
        <label><span>Periode akademik</span><select aria-label="Periode akademik" value={yearId} onChange={(e) => setYearId(e.target.value)}>
          {!yearId && <option value="">Tidak ada periode aktif</option>}
          {years.map((y) => <option key={y.id} value={String(y.id)}>{y.tahun_mulai}/{y.tahun_selesai} · {y.semester === 'GANJIL' ? 'Ganjil' : 'Genap'}{y.is_active ? ' (Aktif)' : ''}</option>)}
        </select></label>
        <div><span>Kelas</span><strong>{workspace?.class?.nama ?? (workspace?.placement_status === 'scheduled' ? 'Belum efektif' : 'Sesuai periode')}</strong></div>
        <div><span>Status data</span><strong>{workspace?.placement_status === 'active' ? 'Penempatan aktif' : workspace?.academic_year?.is_active ? 'Tidak ada penempatan aktif' : 'Riwayat akademik'}</strong></div>
      </section>
      <section className="smv2-metrics" aria-label="Ringkasan bahan ajar">
        <div><span>Mata pelajaran</span><strong>{workspace?.subjects?.length ?? '—'}</strong></div>
        <div><span>Materi tersedia</span><strong>{workspace ? totalMaterials : '—'}</strong></div>
      </section>
      {loading && !workspace ? <section className="smv2-empty" role="status">Memuat bahan ajar…</section> : null}
      {workspace && subjectId === null && <section className="smv2-section">
        <div className="smv2-section-head"><div><h2>Mata pelajaran</h2><p>Pilih mata pelajaran untuk melihat materi.</p></div><small>{workspace.subjects.length} mata pelajaran</small></div>
        {workspace.subjects.length === 0 ? <div className="smv2-empty">Belum ada mata pelajaran yang tersedia untuk penempatan ini.</div> :
          <div className="smv2-grid">{workspace.subjects.map((subject) => <button type="button" key={subject.id} className="smv2-subject" onClick={() => openSubject(subject.id)}>
            <span className="smv2-icon" aria-hidden="true">▤</span><strong>{subject.name}</strong>
            <span className="smv2-muted">{uniqueTeachers(subject.teachers).join(' · ') || 'Guru belum tersedia'}</span>
            <span className="smv2-subject-footer">{subject.material_count} materi <span>Lihat materi →</span></span>
          </button>)}</div>}
      </section>}
      {workspace && subjectId !== null && <section className="smv2-section">
        <button type="button" className="smv2-button" onClick={back}>← Semua mata pelajaran</button>
        <div className="smv2-section-head"><div><p className="smv2-eyebrow">MATERI MATA PELAJARAN</p><h2>{activeSubject?.name || 'Bahan ajar'}</h2><p>{uniqueTeachers(activeSubject?.teachers).join(' · ')}</p></div><small>{materials?.total ?? 0} materi</small></div>
        <form className="smv2-filters" onSubmit={(e) => { e.preventDefault(); setPage(1); load(yearId, subjectId, 1, search, sort) }}>
          <label>Cari judul atau nama file<input type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Cari bahan ajar…" /></label>
          <label>Urutkan<select value={sort} onChange={(e) => { setSort(e.target.value); setPage(1); load(yearId, subjectId, 1, search, e.target.value) }}><option value="newest">Terbaru</option><option value="oldest">Terlama</option></select></label>
          <button type="submit" className="smv2-button smv2-primary">Cari</button>
        </form>
        {detailLoading && <p className="smv2-loading" role="status">Memuat daftar materi…</p>}
        {!detailLoading && materials?.data?.length === 0 && <div className="smv2-empty">Belum ada materi yang cocok. Materi kosong bukan berarti ada kesalahan.</div>}
        <div className="smv2-materials">{materials?.data?.map((m) => <article key={m.id} className="smv2-material">
          <div className="smv2-material-header"><div><h3>{m.title}</h3><p>{m.teacher || 'Guru pengampu'} · {formatDateWib(m.created_at)}</p></div><span className="smv2-count">{m.files.length} lampiran</span></div>
          {m.description && <p className="smv2-description">{m.description}</p>}
          {m.files.length ? <ul className="smv2-files">{m.files.map((file, index) => <li key={file.id ?? `legacy-${index}`}>
            <span className="smv2-file-kind">{fileKind(file.name, file.mime_type)}</span><span className="smv2-file-label"><strong>{file.name || 'Lampiran'}</strong><small>{fileSize(file.size)}</small></span>
            <button type="button" className="smv2-button" onClick={() => download(m, file, index)} disabled={downloading !== null || loading || detailLoading}>{downloading === `${m.id}-${file.id ?? index}` ? 'Menyiapkan…' : 'Siapkan file'}</button>
          </li>)}</ul> : <p className="smv2-muted">Materi ini tidak memiliki lampiran.</p>}
        </article>)}</div>
        {materials && materials.last_page > 1 && <div className="smv2-pagination"><button type="button" className="smv2-button" disabled={page <= 1 || detailLoading} onClick={() => changePage(page - 1)}>Sebelumnya</button><span>Halaman {page} dari {materials.last_page}</span><button type="button" className="smv2-button" disabled={page >= materials.last_page || detailLoading} onClick={() => changePage(page + 1)}>Berikutnya</button></div>}
      </section>}
      <p className="smv2-footnote">Akses materi mengikuti penempatan kelas dan periode akademik yang diverifikasi oleh sekolah. File diunduh melalui API terlindungi.</p>
    </main>
  )
}
