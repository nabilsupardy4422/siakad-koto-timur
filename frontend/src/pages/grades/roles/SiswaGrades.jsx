import { useCallback, useEffect, useRef, useState } from 'react'
import { academicYearApi } from '../../../services/academicYearApi'
import { studentGradeWorkspaceApi } from '../../../services/studentGradeWorkspaceApi'
import { formatGrade, formatWeight, uniqueTeacherNames, validateWorkspace } from './studentGradeView'
import '../styles/SiswaGrades.css'
import {periodCatalog,strictGradeWorkspace} from '../../../utils/studentWorkspace.js'
import TeacherListPager,{useTeacherPage} from '../../../components/ui/TeacherListPager.jsx'
import '../../../components/ui/student-workspace.css'
import HorizontalScrollControls from '../../../components/ui/HorizontalScrollControls.jsx'

export default function SiswaGrades() {
  const [years, setYears] = useState([])
  const [yearId, setYearId] = useState('')
  const [workspace, setWorkspace] = useState(null)
  const [subjectKey, setSubjectKey] = useState(null)
  const [loading, setLoading] = useState(true)
  const [updating, setUpdating] = useState(false)
  const [error, setError] = useState('')
  const seq = useRef(0)
  const gradesScrollRef = useRef(null)
  const initialYear = years.find((year) => String(year.id) === yearId)
  const selected = workspace?.subjects.find((subject) => subject.key === subjectKey) ?? null
  const [search,setSearch]=useState('')
  const rows=(selected?.components??[]).filter(c=>`${c.name} ${c.teacher} ${c.note??''}`.toLowerCase().includes(search.trim().toLowerCase()))
  const paging=useTeacherPage(rows,`${yearId}:${subjectKey}:${search}`)
  useEffect(()=>setSearch(''),[subjectKey])

  useEffect(() => {
    let current = true
    academicYearApi.getAll().then((response) => {
      if (!current) return
      const {years:list,activeId}=periodCatalog(response)
      setYears(list);setYearId(activeId);if(!activeId)setLoading(false)
    }).catch((err) => { if (current) { setError(err.message || 'Gagal memuat periode.'); setLoading(false) } })
    return () => { current = false }
  }, [])

  const refresh = useCallback(async () => {
    if (!yearId) return
    const requestId = ++seq.current
    setUpdating(true)
    setError('')
    try {
      const response = await studentGradeWorkspaceApi.get(yearId)
      const data = strictGradeWorkspace(validateWorkspace(response))
      if (requestId !== seq.current) return
      if(String(data.year.id)!==yearId)throw new Error('Periode respons berbeda.')
      setWorkspace(data)
      setSubjectKey((key) => key && data.subjects.some((s) => s.key === key) ? key : null)
    } catch (err) {
      if (requestId !== seq.current) return
      setWorkspace(null)
      setError(err.message || 'Data nilai gagal dimuat.')
    } finally {
      if (requestId === seq.current) { setUpdating(false); setLoading(false) }
    }
  }, [yearId])

  useEffect(() => {
    // Old request results are invalidated as soon as academic context changes.
    seq.current += 1
    setWorkspace(null)
    setSubjectKey(null)
    if (yearId) refresh()
    else {setLoading(false);setUpdating(false)}
    return () => { seq.current += 1 }
  }, [yearId, refresh])

  const summary = workspace?.summary

  return (
    <div className="student-grade-v2" aria-busy={updating}>
      <header className="sg-head">
        <div><span className="sg-eyebrow">AKADEMIK / NILAI SISWA</span><h1>Nilai akademik</h1>
          <p>Pantau komponen penilaian dan catatan guru berdasarkan mata pelajaran.</p></div>
        <button type="button" className="sg-button" onClick={refresh} disabled={updating || !yearId}>{updating ? 'Memperbarui…' : 'Perbarui data'}</button>
      </header>

      <section className="sg-period" aria-label="Periode akademik">
        <label><span>PERIODE AKADEMIK</span>
          <select value={yearId} onChange={(e) => setYearId(e.target.value)} disabled={years.length === 0}>
            <option value="">Pilih periode akademik</option>{years.map((year) => <option key={year.id} value={year.id}>{year.tahun_mulai}/{year.tahun_selesai} · {year.semester === 'GANJIL' ? 'Ganjil' : 'Genap'}{year.is_active ? ' (Aktif)' : ''}</option>)}
          </select>
        </label>
        <div><span>KELAS TERKAIT</span><strong>{workspace?.classes.map((c) => c.name).join(', ') || '—'}</strong></div>
        <div><span>STATUS DATA</span><strong>{workspace?.membership_status === 'active' ? 'Penempatan aktif' : workspace?.membership_status === 'historical' ? 'Riwayat kelas' : workspace?.membership_status === 'ended' ? 'Penempatan berakhir' : 'Belum ditempatkan'}</strong></div>
      </section>

      {error && <div className="sg-error" role="alert">{error} <button type="button" onClick={refresh}>Coba lagi</button></div>}
      {loading && <div className="sg-message" role="status">Memuat data nilai siswa…</div>}
      {workspace && <>
        <section className="sg-stat-grid" aria-label="Ringkasan nilai">
          {[['Mata pelajaran', summary.subjects], ['Komponen penilaian', summary.components], ['Sudah dinilai', summary.assessed], ['Belum dinilai', summary.pending]].map(([label, value]) =>
            <div className="sg-stat" key={label}><span>{label}</span><strong>{value}</strong></div>)}
        </section>
        {!selected ? <section className="sg-subject-section">
          <div className="sg-section-heading"><div><h2>Mata pelajaran</h2><p>Pilih satu mata pelajaran untuk melihat rincian nilai.</p></div><small>{workspace.subjects.length} mapel / kelas</small></div>
          {workspace.subjects.length === 0 ? <div className="sg-empty">Belum ada komponen nilai pada periode dan penempatan yang dipilih.</div> :
            <div className="sg-cards">{workspace.subjects.map((subject) => <button key={subject.key} className="sg-subject-card" type="button" onClick={() => setSubjectKey(subject.key)}>
              <span className="sg-card-icon" aria-hidden="true">▤</span><span className="sg-card-arrow" aria-hidden="true">›</span>
              <strong>{subject.subject}</strong><span className="sg-muted">{uniqueTeacherNames(subject.teachers) || 'Guru belum tersedia'}</span>
              <span className="sg-muted">{subject.class_name} · {subject.components.length} komponen</span>
              <span className="sg-card-bottom">{subject.assessed} dinilai · {subject.pending} belum dinilai <span>Rincian →</span></span>
            </button>)}</div>}
        </section> : <section className="sg-detail">
          <button className="sg-button sg-back" type="button" onClick={() => setSubjectKey(null)}>← Semua mata pelajaran</button>
          <div className="sg-section-heading"><div><span className="sg-eyebrow">RINCIAN NILAI</span><h2>{selected.subject}</h2><p>{selected.class_name} · {uniqueTeacherNames(selected.teachers)}</p></div><small>{selected.components.length} komponen</small></div>
          <label className="student-search">Cari komponen atau guru<input type="search" value={search} onChange={e=>setSearch(e.target.value)}/></label>
          <TeacherListPager data={paging} label="komponen"/>
          <div className="student-mobile-cards">{paging.rows.map(c=><article key={c.id}><strong>{c.name}</strong><p>{c.teacher} · {c.day} {c.start}–{c.end}</p><dl><dt>Bobot</dt><dd>{formatWeight(c.weight)}</dd><dt>Nilai</dt><dd>{formatGrade(c.score)}</dd><dt>Status</dt><dd>{c.assessed?'Sudah dinilai':'Belum dinilai'}</dd><dt>Catatan guru</dt><dd>{c.note||'—'}</dd></dl></article>)}</div>
          <div className="sg-table-shell student-desktop-table">
            <HorizontalScrollControls scrollRef={gradesScrollRef} label="Geser tabel rincian nilai hingga kolom Status" stepSelector="th" />
            <div className="sg-table-scroll" ref={gradesScrollRef} role="region" tabIndex={0} aria-label="Tabel rincian nilai, dapat digeser horizontal"><table><thead><tr><th>Komponen</th><th>Guru pengampu</th><th>Bobot</th><th>Nilai</th><th>Catatan guru</th><th>Status</th></tr></thead>
            <tbody>{paging.rows.map((component) => <tr key={component.id}><td><strong>{component.name}</strong><small>{component.day} · {component.start}–{component.end}</small></td><td>{component.teacher}</td><td>{formatWeight(component.weight)}</td><td><strong>{formatGrade(component.score)}</strong></td><td>{component.note || '—'}</td><td><span className={component.assessed ? 'sg-chip sg-chip-success' : 'sg-chip'}>{component.assessed ? 'Sudah dinilai' : 'Belum dinilai'}</span></td></tr>)}</tbody></table></div>
          </div>
        </section>}
        <p className="sg-note">Nilai 0 adalah nilai sah. Tanda — berarti belum ada nilai. Bobot hanya informasi; halaman ini tidak menghitung nilai akhir atau rapor otomatis.</p>
      </>}
      {!initialYear && !loading && !error && <div className="sg-empty">Pilih periode akademik untuk melihat nilai.</div>}
    </div>
  )
}
