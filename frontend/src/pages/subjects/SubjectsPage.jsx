import { useCallback, useEffect, useRef, useState } from 'react'
import { useAuth } from '../../context/useAuth'
import { subjectApi } from '../../services/subjectApi'
import PageHeader from '../../components/ui/PageHeader'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import WorkspaceModal from '../../components/ui/WorkspaceModal.jsx'
import ToastNotification from '../../components/ui/ToastNotification.jsx'
import HorizontalScrollControls from '../../components/ui/HorizontalScrollControls.jsx'
import EmptyState from '../../components/ui/EmptyState'
import ErrorState from '../../components/ui/ErrorState'
import LoadingState from '../../components/ui/LoadingState'
import { DEFAULT_SUBJECT_FILTERS, subjectForm, validateSubjectForm, subjectPayload, kkmView, subjectDirectoryResponse } from './subjectDirectoryLogic.js'
import './SubjectsPage.css'

const dateLabel = value => {
  const date = value ? new Date(value) : null
  return date && Number.isFinite(date.getTime()) ? new Intl.DateTimeFormat('id-ID', { dateStyle: 'long', timeZone: 'Asia/Jakarta' }).format(date) : 'Belum tercatat'
}
const errorMessage = error => error?.message || 'Terjadi kesalahan. Silakan coba lagi.'

function SubjectIcon({ kind }) {
  return <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.65" strokeLinecap="round" strokeLinejoin="round">
    {kind === 'book' ? <><path d="M4 4h7a3 3 0 0 1 3 3v14a4 4 0 0 0-4-3H4zM14 7a3 3 0 0 1 3-3h3v14h-2a4 4 0 0 0-4 3"/><path d="M7 8h3m-3 4h3"/></> : kind === 'calendar' ? <><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 10h18m-13 5 3 3 5-5"/></> : <><rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V2h6v2M9 9h6m-6 4h6m-6 4h3"/></>}
  </svg>
}

function Filter({ label, value, onChange, options }) {
  return <label className="subject-filter"><span>{label}</span><select value={value} onChange={e => onChange(e.target.value)}>{options.map(([v, text]) => <option key={v} value={v}>{text}</option>)}</select></label>
}

function SubjectKkm({ value }) {
  const view = kkmView(value)
  return <span className={`subject-kkm ${view.state}`} title={view.raw ? `Nilai legacy: ${view.raw}` : undefined}>{view.label}{view.raw && <small> · {view.raw}</small>}</span>
}

function SubjectDialog({ modal, canManage, onClose, onSaved, notify }) {
  const [mode, setMode] = useState(modal.mode)
  const [subject, setSubject] = useState(null)
  const [form, setForm] = useState(subjectForm())
  const [errors, setErrors] = useState({})
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [loading, setLoading] = useState(modal.mode === 'detail')
  const [retry, setRetry] = useState(0)
  const guard = useRef(false)
  useEffect(() => {
    if (modal.mode !== 'detail') return undefined
    let live = true
    setLoading(true); setError(''); setSubject(null)
    subjectApi.getById(modal.id).then(response => {
      if (!live) return
      const row = response?.data
      if (!row || String(row.id) !== String(modal.id) || (canManage && (!Number.isInteger(row.schedule_count) || !row.version || !row.usage_context))) throw new Error('Detail penggunaan tidak tersedia. Periksa backend.')
      setSubject(row); setForm(subjectForm(row))
    }).catch(e => { if (live) setError(errorMessage(e)) }).finally(() => { if (live) setLoading(false) })
    return () => { live = false }
  }, [modal, canManage, retry])
  const locked = subject?.schedule_count > 0
  const change = (field, value) => { setForm(old => ({ ...old, [field]: value })); setErrors(old => ({ ...old, [field]: '' })); setError('') }
  const submit = async event => {
    event?.preventDefault()
    if (!canManage || guard.current || (mode !== 'create' && (!subject?.version || locked))) return
    if (mode !== 'delete') {
      const next = validateSubjectForm(form); setErrors(next)
      if (Object.keys(next).length) return
    }
    guard.current = true; setBusy(true); setError('')
    try {
      const result = mode === 'delete' ? await subjectApi.delete(subject.id, subject.version) : mode === 'edit' ? await subjectApi.update(subject.id, subjectPayload(form, subject.version)) : await subjectApi.create(subjectPayload(form))
      notify('success', result?.message || 'Perubahan berhasil disimpan.')
      onSaved(mode)
    } catch (e) {
      setError(errorMessage(e)); setErrors(e?.errors || {})
      if (e?.status === 409) notify('warning', 'Data telah berubah atau sudah digunakan. Buka ulang detail sebelum mencoba lagi.')
    } finally { guard.current = false; setBusy(false) }
  }
  const fieldError = key => Array.isArray(errors[key]) ? errors[key][0] : errors[key]
  const back = () => { setMode('detail'); setError(''); setErrors({}); setForm(subjectForm(subject)); setRetry(n => n + 1) }
  const title = { detail: 'Detail mata pelajaran', create: 'Tambah mata pelajaran', edit: 'Edit mata pelajaran', delete: 'Hapus mata pelajaran' }[mode]
  const formId = 'subject-directory-form'
  return <WorkspaceModal title={title} eyebrow="Administrasi akademik" description="Identitas katalog dan perlindungan riwayat akademik." busy={busy} onClose={onClose} compact={mode !== 'detail'} className="subject-dialog" footer={<>
    <Button variant="outline" disabled={busy} onClick={mode === 'edit' || mode === 'delete' ? back : onClose}>{mode === 'detail' ? 'Tutup' : 'Batal'}</Button>
    {mode === 'detail' && canManage && subject && !locked && !loading && <><Button variant="danger" onClick={() => setMode('delete')}>Hapus</Button><Button onClick={() => setMode('edit')}>Edit identitas</Button></>}
    {['create', 'edit'].includes(mode) && <Button type="submit" form={formId} loading={busy}>Simpan mata pelajaran</Button>}
    {mode === 'delete' && <Button variant="danger" loading={busy} onClick={submit}>Hapus permanen</Button>}
  </>}>
    {loading ? <LoadingState/> : mode === 'detail' ? !subject ? <ErrorState message={error} onRetry={() => setRetry(n => n + 1)}/> : <>
      <div className="subject-detail-banner"><span className="subject-mark"><SubjectIcon kind="book"/></span><div><strong>{subject.nama}</strong><span>{subject.kode}</span></div></div>
      <dl className="subject-detail-grid"><div><dt>KKM</dt><dd><SubjectKkm value={subject.kkm}/></dd></div><div><dt>Terakhir diperbarui</dt><dd>{dateLabel(subject.updated_at)}</dd></div></dl>
      {canManage && <><h3 className="subject-subtitle">Penggunaan seluruh periode</h3><div className="subject-usage-grid">{[['Jadwal', subject.schedule_count], ['Periode', subject.usage_context.periods], ['Kelas', subject.usage_context.classes], ['Guru', subject.usage_context.teachers]].map(([label, value]) => <div key={label}><strong>{value}</strong><span>{label}</span></div>)}</div><p className="subject-note">{locked ? 'Identitas dan KKM dikunci karena mapel telah digunakan dalam jadwal. Riwayat akademik tetap dipertahankan.' : 'Belum terhubung ke jadwal. Identitas masih dapat diubah atau dihapus.'}</p></>}
    </> : mode === 'delete' ? <><div className="subject-detail-banner"><div><strong>{subject.nama}</strong><span>{subject.kode}</span></div></div><p className="subject-note">Mapel yang belum digunakan dapat dihapus permanen. Backend memeriksa ulang relasi sebelum menyimpan perubahan.</p></> : <form id={formId} className="subject-form" onSubmit={submit} noValidate>
      <Input id="subject-code" label="Kode mata pelajaran" value={form.kode} maxLength={50} required disabled={busy} onChange={e => change('kode', e.target.value)} error={fieldError('kode')} placeholder="Contoh: KKA" hint="Kode unik; kode baru disimpan dalam huruf kapital ASCII."/>
      <Input id="subject-name" label="Nama mata pelajaran" value={form.nama} maxLength={255} required disabled={busy} onChange={e => change('nama', e.target.value)} error={fieldError('nama')} placeholder="Nama resmi sekolah"/>
      <Input id="subject-kkm" label="KKM (opsional)" value={form.kkm} inputMode="decimal" disabled={busy} onChange={e => change('kkm', e.target.value)} error={fieldError('kkm')} placeholder="0–100" hint="Kosongkan bila belum ditetapkan sekolah. Nol tetap merupakan nilai."/>
    </form>}
    {error && mode !== 'detail' && <p className="subject-error" role="alert">{error}</p>}
  </WorkspaceModal>
}

export default function SubjectsPage() {
  const { role } = useAuth()
  const canManage = role?.code === 'TU'
  const [draft, setDraft] = useState({ ...DEFAULT_SUBJECT_FILTERS })
  const [filters, setFilters] = useState({ ...DEFAULT_SUBJECT_FILTERS })
  const [page, setPage] = useState(1)
  const [revision, setRevision] = useState(0)
  const [result, setResult] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [modal, setModal] = useState(null)
  const [notice, setNotice] = useState(null)
  const scrollRef = useRef(null)
  const dismiss = useCallback(() => setNotice(null), [])
  const notify = (type, message) => setNotice({ type, title: type === 'success' ? 'Berhasil' : 'Perhatian', message })
  useEffect(() => {
    let live = true
    setLoading(true); setError(''); setResult(null)
    subjectApi.getAll({ ...filters, usage: canManage ? filters.usage : '', page }).then(response => {
      if (!live) return
      const next = subjectDirectoryResponse(response, canManage)
      if (page > next.meta.last_page) { setPage(next.meta.last_page); return }
      setResult(next)
    }).catch(e => { if (live) setError(errorMessage(e)) }).finally(() => { if (live) setLoading(false) })
    return () => { live = false }
  }, [filters, page, revision, canManage])
  const refresh = () => setRevision(n => n + 1)
  const apply = e => { e.preventDefault(); setFilters({ ...draft, search: draft.search.trim() }); setPage(1) }
  const reset = () => { setDraft({ ...DEFAULT_SUBJECT_FILTERS }); setFilters({ ...DEFAULT_SUBJECT_FILTERS }); setPage(1) }
  const filter = (key, value) => setDraft(old => ({ ...old, [key]: value }))
  const meta = result?.meta
  const summary = meta?.summary
  return <main className="subjects-v2">
    <PageHeader eyebrow="Administrasi akademik" title="Mata Pelajaran" description="Kelola katalog mata pelajaran, kriteria ketuntasan dan penggunaan akademik." actions={canManage && <Button onClick={() => setModal({ mode: 'create' })}>+ Tambah mapel</Button>}/>
    {canManage && <section aria-label="Ringkasan katalog" className="subject-overview" aria-busy={loading}><div className="subject-overview-head"><strong>Ringkasan katalog</strong><span>Seluruh data · di luar filter</span></div><div className="subject-stat-grid">{[['total','Total mata pelajaran','Katalog sekolah','book'],['used','Mapel terjadwal','Digunakan di seluruh periode','calendar'],['unset','KKM belum diisi','Belum ditetapkan sekolah','clipboard']].map(([key,label,note,kind]) => <article key={key} className="subject-stat"><div><h2>{label}</h2><strong>{loading ? '…' : summary ? summary[key].toLocaleString('id-ID') : '—'}</strong><p>{error ? 'Data tidak tersedia' : note}</p></div><span className={`subject-stat-icon ${kind}`}><SubjectIcon kind={kind}/></span></article>)}</div>{summary?.invalid > 0 && <p className="subject-note">{summary.invalid} mapel memiliki KKM legacy di luar rentang. Tinjau dengan filter KKM; tidak ada data yang diubah otomatis.</p>}</section>}
    <section className="subject-directory" aria-label="Daftar mata pelajaran">
      <header className="subject-section-head"><div><span>DATA AKADEMIK</span><h2>Daftar Mata Pelajaran</h2><p>Kode dan nama resmi mata pelajaran sekolah.</p></div><span className="subject-count">{meta ? `${meta.total} hasil` : '—'}</span></header>
      <form className="subject-filters" onSubmit={apply}>
        <label className="subject-filter subject-search"><span>Cari mata pelajaran</span><input value={draft.search} maxLength={255} placeholder="Kode atau nama mapel" onChange={e => filter('search', e.target.value)}/></label>
        <Filter label="Urutan nama" value={draft.sort_order} onChange={v => filter('sort_order',v)} options={ [['asc','A–Z'],['desc','Z–A']] }/>
        {canManage && <Filter label="Penggunaan" value={draft.usage} onChange={v => filter('usage',v)} options={ [['','Semua'],['used','Terjadwal'],['unused','Belum terjadwal']] }/>}
        <Filter label="Status KKM" value={draft.kkm_state} onChange={v => filter('kkm_state',v)} options={ [['','Semua'],['set','Sudah diisi'],['unset','Belum diisi'],['invalid','Perlu ditinjau']] }/>
        <div className="subject-filter-actions"><Button type="submit">Cari</Button><Button variant="outline" onClick={reset}>Reset</Button></div>
      </form>
      {loading ? <LoadingState message="Memuat katalog mata pelajaran…"/> : error ? <ErrorState message={error} onRetry={refresh}/> : !result?.rows.length ? <EmptyState title="Tidak ada mata pelajaran" message="Tidak ada data yang cocok. Ubah filter atau tambahkan mata pelajaran baru." action={<Button variant="outline" onClick={reset}>Reset filter</Button>}/> : <>
        <HorizontalScrollControls scrollRef={scrollRef} label="Tabel mata pelajaran" endLabel="Ke aksi"/>
        <div ref={scrollRef} className="subject-table-scroll" tabIndex={0} role="region" aria-label="Tabel mata pelajaran"><table className="subject-table"><thead><tr><th scope="col">Mata pelajaran</th><th scope="col">KKM</th>{canManage && <th scope="col">Penggunaan</th>}<th scope="col">Diperbarui</th><th scope="col">Aksi</th></tr></thead><tbody>{result.rows.map(row => <tr key={row.id}><td><div className="subject-identity"><span className="subject-mark"><SubjectIcon kind="book"/></span><div><strong>{row.nama}</strong><span>{row.kode}</span></div></div></td><td><SubjectKkm value={row.kkm}/></td>{canManage && <td><span className={`subject-status ${row.schedule_count > 0 ? 'used' : ''}`}>{row.schedule_count > 0 ? `${row.schedule_count} jadwal` : 'Belum terjadwal'}</span></td>}<td>{dateLabel(row.updated_at)}</td><td><Button variant="outline" size="small" onClick={() => setModal({ mode: 'detail', id: row.id })}>Lihat detail ›</Button></td></tr>)}</tbody></table></div>
      </>}
      <footer className="subject-pagination"><Filter label="Data per halaman" value={String(filters.page_size)} onChange={v => { setDraft(old => ({ ...old, page_size: Number(v) })); setFilters(old => ({ ...old, page_size: Number(v) })); setPage(1) }} options={[15,25,50,100].map(n => [String(n), String(n)])}/><span aria-live="polite">{meta ? `Halaman ${meta.current_page} dari ${meta.last_page} · ${meta.total} hasil` : '—'}</span><div><Button variant="outline" disabled={loading || !meta || page <= 1} onClick={() => setPage(n => n-1)}>Sebelumnya</Button><Button variant="outline" disabled={loading || !meta || page >= meta.last_page} onClick={() => setPage(n => n+1)}>Berikutnya</Button></div></footer>
    </section>
    {modal && <SubjectDialog modal={modal} canManage={canManage} onClose={() => setModal(null)} notify={notify} onSaved={mode => { setModal(null); if (mode === 'create') reset(); refresh() }}/>}
    <ToastNotification notice={notice} onClose={dismiss}/>
  </main>
}
