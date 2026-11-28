import { useCallback, useEffect, useRef, useState } from 'react';
import Button from '../../components/ui/Button';
import Input from '../../components/ui/Input';
import Select from '../../components/ui/Select';
import PageHeader from '../../components/ui/PageHeader';
import WorkspaceModal from '../../components/ui/WorkspaceModal';
import ToastNotification from '../../components/ui/ToastNotification';
import LoadingState from '../../components/ui/LoadingState';
import ErrorState from '../../components/ui/ErrorState';
import EmptyState from '../../components/ui/EmptyState';
import { academicYearApi } from '../../services/academicYearApi';
import { yearLabel, semesterLabel, validStart, endYear, activePeriod, lockedPeriod, formPayload, activationPayload, canImport, errorMessages } from './academicYearLogic';
import './AcademicYearsPage.css';

const icons = {
  plus: <path d="M12 5v14M5 12h14" />,
  calendar: <><rect x="3" y="5" width="18" height="16" rx="2" /><path d="M7 3v4m10-4v4M3 10h18m-13 5h2m4 0h2m-8 3h2" /></>,
  download: <path d="M12 3v12m-5-5 5 5 5-5M4 16v4h16v-4" />,
  upload: <path d="M12 16V4m-5 5 5-5 5 5M4 16v4h16v-4" />,
  lock: <><rect x="5" y="10" width="14" height="11" rx="2" /><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 4v3" /></>,
  arrow: <path d="M4 12h15m-6-6 6 6-6 6" />,
  info: <><circle cx="12" cy="12" r="9" /><path d="M12 11v6m0-10h.01" /></>,
};
function Icon({ name, size = 18 }) { return <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">{icons[name]}</svg>; }
function PeriodStatus({ period }) { return <span className={`ay-status ${activePeriod(period) ? 'is-active' : ''}`}><i />{activePeriod(period) ? 'Aktif' : 'Tidak aktif'}</span>; }
function Errors({ messages }) { return messages.length > 0 && <div className="ay-alert ay-alert--error" role="alert"><Icon name="info" /><div>{messages.map((message, i) => <p key={i}>{message}</p>)}</div></div>; }
const semesters = [{ value: 'GANJIL', label: 'Ganjil' }, { value: 'GENAP', label: 'Genap' }];

export default function AcademicYearsPage() {
  const [periods, setPeriods] = useState([]);
  const [loading, setLoading] = useState(true);
  const [listError, setListError] = useState(null);
  const [dialog, setDialog] = useState(null);
  const [form, setForm] = useState({ tahun_mulai: '', semester: 'GANJIL' });
  const [fieldErrors, setFieldErrors] = useState({});
  const [messages, setMessages] = useState([]);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState(null);
  const [downloading, setDownloading] = useState(false);
  const [file, setFile] = useState(null);
  const [preview, setPreview] = useState(null);
  const [previewFilter, setPreviewFilter] = useState('all');
  const [confirmImport, setConfirmImport] = useState(false);
  const generation = useRef(0);
  const operation = useRef(false);
  const downloadLock = useRef(false);
  const clearNotice = useCallback(() => setNotice(null), []);
  const active = periods.filter(activePeriod);
  const current = active.length === 1 ? active[0] : null;
  const inconsistent = periods.length > 0 && active.length !== 1;

  const load = useCallback(async () => {
    const request = ++generation.current;
    setLoading(true); setListError(null);
    try {
      const response = await academicYearApi.getAll();
      if (!Array.isArray(response?.data)) throw new Error('Respons periode dari server tidak sesuai.');
      if (request === generation.current) setPeriods(response.data);
    } catch (error) { if (request === generation.current) setListError(error); }
    finally { if (request === generation.current) setLoading(false); }
  }, []);
  useEffect(() => { load(); return () => { generation.current++; }; }, [load]);
  function open(kind, period = null) {
    setMessages([]); setFieldErrors({}); setNotice(null);
    setForm({ tahun_mulai: period ? String(period.tahun_mulai) : '', semester: period?.semester || 'GANJIL' });
    setFile(null); setPreview(null); setPreviewFilter('all'); setConfirmImport(false);
    setDialog({ kind, period, activeSnapshot: active });
  }
  function close() { if (!operation.current) setDialog(null); }
  async function run(action) {
    if (operation.current) return;
    operation.current = true; setBusy(true); setMessages([]);
    try { await action(); }
    catch (error) { setMessages(errorMessages(error)); setFieldErrors(error?.validationErrors || {}); }
    finally { operation.current = false; setBusy(false); }
  }
  async function finish(title, message) {
    setDialog(null); setNotice({ type: 'success', title, message }); await load();
  }
  function updateForm(event) {
    const { name, value } = event.target;
    setForm(old => ({ ...old, [name]: value })); setFieldErrors({}); setMessages([]);
  }
  function save(event) {
    event.preventDefault();
    const payload = formPayload(form, dialog.kind === 'create' && periods.length === 0);
    if (!payload) { setFieldErrors({ tahun_mulai: !validStart(form.tahun_mulai) ? ['Masukkan tahun mulai antara 1000 dan 9998.'] : [], semester: !semesters.some(s => s.value === form.semester) ? ['Pilih semester.'] : [] }); return; }
    run(async () => {
      const result = dialog.kind === 'edit' ? await academicYearApi.update(dialog.period.id, payload) : await academicYearApi.create(payload);
      await finish(dialog.kind === 'edit' ? 'Periode diperbarui' : 'Periode berhasil dibuat', `${yearLabel(result.data)} · Semester ${semesterLabel(result.data.semester)} · ${activePeriod(result.data) ? 'Aktif' : 'Tidak aktif'}`);
    });
  }
  function activate() {
    run(async () => {
      try {
        await academicYearApi.update(dialog.period.id, activationPayload(dialog.period, dialog.activeSnapshot));
      } catch (error) {
        if (error?.status !== 409) throw error;
        setDialog(null);
        setNotice({ type: 'warning', title: 'Periode aktif telah berubah', message: 'Tinjau data terbaru, lalu konfirmasi ulang aktivasi.' });
        await load();
        return;
      }
      await finish('Periode berhasil diaktifkan', `${yearLabel(dialog.period)} · Semester ${semesterLabel(dialog.period.semester)} sekarang aktif.`);
    });
  }
  function remove() {
    run(async () => {
      await academicYearApi.delete(dialog.period.id);
      await finish('Periode dihapus', `${yearLabel(dialog.period)} · Semester ${semesterLabel(dialog.period.semester)} telah dihapus.`);
    });
  }
  async function download() {
    if (downloadLock.current) return;
    downloadLock.current = true; setDownloading(true);
    try {
      const blob = await academicYearApi.downloadTemplate();
      const url = URL.createObjectURL(blob); const link = document.createElement('a');
      link.href = url; link.download = 'template_tahun_akademik.xlsx'; document.body.appendChild(link); link.click(); link.remove(); setTimeout(() => URL.revokeObjectURL(url), 1000);
      setNotice({ type: 'success', title: 'Template siap diunduh', message: 'Gunakan empat kolom pada template untuk mengisi periode.' });
    } catch (error) { setNotice({ type: 'error', title: 'Template gagal diunduh', message: errorMessages(error)[0] }); }
    finally { downloadLock.current = false; setDownloading(false); }
  }
  function changeFile(event) {
    const next = event.target.files?.[0] || null; setFile(null); setPreview(null); setPreviewFilter('all'); setConfirmImport(false); setMessages([]);
    if (!next) return;
    if (!/\.xlsx$/i.test(next.name) || next.size > 2 * 1024 * 1024) { setMessages(['Gunakan file .xlsx dengan ukuran maksimal 2 MB.']); event.target.value = ''; return; }
    setFile(next);
  }
  function multipart() { const data = new FormData(); data.append('file', file); return data; }
  function checkFile() {
    if (!file) return;
    run(async () => {
      setPreview(null); setConfirmImport(false); setPreviewFilter('all');
      const response = await academicYearApi.previewImport(multipart()); const result = response?.data;
      if (!result?.summary || !Array.isArray(result.rows)) throw new Error('Respons preview tidak sesuai.');
      setPreview(result);
    });
  }
  function importFile() {
    if (!file || !canImport(preview) || !confirmImport) return;
    run(async () => {
      try { const response = await academicYearApi.importExcel(multipart()); await finish('Import berhasil', `${response.data.imported_rows} periode berhasil disimpan.`); }
      catch (error) { setPreview(null); setConfirmImport(false); throw error; }
    });
}
  const visibleRows = preview?.rows.filter(row => previewFilter === 'all' || (previewFilter === 'valid' ? row.valid : !row.valid)) || [];
  const disabled = loading || Boolean(listError) || busy;
  return <div className="ay-v2">
    <PageHeader eyebrow="Data Akademik / Administrasi" title="Tahun Akademik" description="Atur periode pembelajaran dan jaga kesinambungan riwayat akademik sekolah." actions={<Button size="large" disabled={disabled || inconsistent} onClick={() => open('create')}><Icon name="plus" /> Tambah Periode</Button>} />
    {loading ? <LoadingState message="Memuat periode akademik..." /> : listError ? <ErrorState message={listError.message} onRetry={load} /> : <>
      <section className="ay-hero" aria-label="Periode akademik aktif">
        <div className="ay-hero-copy"><p className="ay-kicker"><span className={current ? 'ay-live-dot' : 'ay-muted-dot'} /> PERIODE AKADEMIK AKTIF</p><h2>{current ? yearLabel(current) : 'Belum ditetapkan'}</h2><p className="ay-hero-semester">{current ? `Semester ${semesterLabel(current.semester)}` : active.length > 1 ? 'Lebih dari satu periode aktif' : 'Tidak ada periode aktif'}</p><p className="ay-hero-caption">{current ? 'Konteks pembelajaran yang sedang digunakan oleh sistem.' : 'Tentukan satu periode aktif untuk menetapkan konteks akademik.'}</p></div>
        <div className="ay-hero-art" aria-hidden="true"><svg viewBox="0 0 220 160" fill="none"><path d="M20 135 110 15l90 120M40 135 110 40l70 95" stroke="currentColor" opacity=".15" /><rect x="62" y="32" width="108" height="100" rx="10" stroke="currentColor" strokeWidth="2" /><path d="M62 60h108M87 23v19m57-19v19" stroke="currentColor" strokeWidth="3" strokeLinecap="round" />{[0, 1, 2, 3, 4, 5].map(i => <rect key={i} x={81 + i % 3 * 28} y={76 + Math.floor(i / 3) * 24} width="13" height="13" rx="3" fill="currentColor" opacity={i === 4 ? 1 : .26} />)}</svg></div>
      </section>
      {inconsistent && <div className="ay-alert" role="alert"><Icon name="info" /><div><strong>Periode perlu dipulihkan</strong><p>Pilih satu periode melalui tindakan Aktifkan. Pembuatan dan import ditahan sampai konteks aktif kembali konsisten.</p></div></div>}
      <section className="ay-panel" aria-label="Daftar periode">
        <div className="ay-panel-head"><div><h2>Daftar periode</h2><p>{periods.length} periode terdaftar · Riwayat tetap tersimpan.</p></div><div className="ay-tools"><Button variant="outline" loading={downloading} onClick={download}><Icon name="download" /> Download Template</Button><Button variant="outline" disabled={inconsistent} onClick={() => open('import')}><Icon name="upload" /> Import Excel</Button></div></div>
        {periods.length === 0 ? <EmptyState title="Belum ada periode akademik" message="Tambahkan periode pertama untuk memulai pengelolaan data sekolah." action={<Button onClick={() => open('create')}>Tambah periode pertama</Button>} /> : <div className="ay-table-scroll" tabIndex={0} role="region" aria-label="Tabel periode akademik, dapat digulir"><table className="ay-table"><thead><tr><th>Tahun akademik</th><th>Semester</th><th>Status</th><th>Pengelolaan</th><th>Tindakan</th></tr></thead><tbody>{periods.map(period => <tr key={period.id} className={activePeriod(period) ? 'ay-current-row' : ''}><td><strong>{yearLabel(period)}</strong>{Number(period.tahun_selesai) !== Number(period.tahun_mulai) + 1 && <small className="ay-invalid-year">Format tahun lama tidak berurutan</small>}</td><td>{semesterLabel(period.semester)}</td><td><PeriodStatus period={period} /></td><td>{lockedPeriod(period) ? <span className="ay-lock"><Icon name="lock" size={15} /> {activePeriod(period) ? 'Periode aktif' : period.identity_locked === true ? 'Sudah digunakan' : 'Status kunci belum diketahui'}</span> : <span className="ay-editable">Dapat diedit</span>}</td><td><div className="ay-row-actions">{(!activePeriod(period) || inconsistent) && <Button size="small" variant="outline" onClick={() => open('activate', period)}>Aktifkan</Button>}{lockedPeriod(period) ? <Button size="small" variant="ghost" onClick={() => open('detail', period)}>Detail</Button> : <><Button size="small" variant="ghost" disabled={inconsistent} onClick={() => open('edit', period)}>Edit</Button><Button size="small" variant="ghost" className="ay-delete" onClick={() => open('delete', period)}>Hapus</Button></>}</div></td></tr>)}</tbody></table></div>}
        {periods.length > 0 && <p className="ay-scroll-hint">Geser tabel ke samping untuk melihat seluruh tindakan.</p>}
      </section>
      <aside className="ay-footnote"><Icon name="info" /><div><strong>Riwayat akademik tetap terlindungi.</strong><p>Aktivasi tidak memindahkan kelas, jadwal, atau data akademik. Identitas periode aktif dan periode yang sudah digunakan tidak dapat diubah.</p></div></aside>
    </>}
    {dialog && ['create', 'edit'].includes(dialog.kind) && <WorkspaceModal compact className="ay-dialog" eyebrow="DATA AKADEMIK" title={dialog.kind === 'edit' ? 'Edit periode' : 'Tambah periode'} description="Tentukan tahun ajaran dan semester." busy={busy} onClose={close} footer={<><Button variant="secondary" disabled={busy} onClick={close}>Batal</Button><Button type="submit" form="ay-form" loading={busy}>Simpan periode</Button></>}>
      <form id="ay-form" noValidate onSubmit={save} className="ay-form"><Errors messages={messages} /><div className="ay-fields"><Input id="ay-start" name="tahun_mulai" label="Tahun mulai" value={form.tahun_mulai} inputMode="numeric" maxLength={4} required disabled={busy} error={fieldErrors.tahun_mulai?.[0]} onChange={updateForm} /><Input id="ay-end" label="Tahun selesai" value={endYear(form.tahun_mulai)} readOnly hint="Dihitung otomatis" error={fieldErrors.tahun_selesai?.[0]} /></div><Select id="ay-semester" name="semester" label="Semester" value={form.semester} options={semesters} required disabled={busy} onChange={updateForm} error={fieldErrors.semester?.[0]} /><div className="ay-inset"><strong>{endYear(form.tahun_mulai) ? `${form.tahun_mulai}/${endYear(form.tahun_mulai)}` : 'Tahun belum valid'}</strong><p>Semester {semesterLabel(form.semester)} · {dialog.kind === 'create' && periods.length === 0 ? 'Periode pertama otomatis aktif.' : 'Disimpan sebagai periode tidak aktif.'}</p></div></form>
    </WorkspaceModal>}
    {dialog && ['activate', 'delete', 'detail'].includes(dialog.kind) && <WorkspaceModal compact className="ay-dialog" eyebrow="DATA AKADEMIK" title={dialog.kind === 'activate' ? 'Aktifkan periode akademik?' : dialog.kind === 'delete' ? 'Hapus periode?' : 'Detail periode'} busy={busy} onClose={close} footer={<><Button variant="secondary" disabled={busy} onClick={close}>{dialog.kind === 'detail' ? 'Tutup' : 'Batal'}</Button>{dialog.kind === 'activate' && <Button loading={busy} onClick={activate}>Aktifkan Periode</Button>}{dialog.kind === 'delete' && <Button variant="danger" loading={busy} onClick={remove}>Hapus Periode</Button>}</>}>
      <div className="ay-form"><Errors messages={messages} />{dialog.kind === 'activate' && <><div className="ay-inset"><small>PERIODE SAAT INI</small><strong>{current ? yearLabel(current) : active.length > 1 ? 'Beberapa periode aktif' : 'Tidak ada periode aktif'}</strong>{current && <p>Semester {semesterLabel(current.semester)}</p>}</div><div className="ay-transition"><Icon name="arrow" /> Beralih ke</div></>}<div className="ay-inset ay-target"><small>{dialog.kind === 'activate' ? 'PERIODE TUJUAN' : 'PERIODE'}</small><strong>{yearLabel(dialog.period)}</strong><p>Semester {semesterLabel(dialog.period.semester)}</p><PeriodStatus period={dialog.period} /></div>{dialog.kind === 'activate' ? <><p>Periode lain akan dinonaktifkan. Konteks kelas, jadwal, presensi, penilaian dan Wali Kelas mengikuti periode tujuan. Riwayat tidak dihapus atau disalin.</p><div className="ay-alert"><Icon name="info" /><p>Pastikan data akademik periode tujuan telah diperiksa. Periode historis juga dapat diaktifkan kembali.</p></div></> : dialog.kind === 'delete' ? <p>Hanya periode tidak aktif yang belum digunakan dapat dihapus. Tindakan ini tidak dapat dibatalkan.</p> : <p>Identitas periode {activePeriod(dialog.period) ? 'aktif' : 'yang sudah digunakan atau belum dapat diverifikasi'} dilindungi. Tahun dan semester tidak dapat diedit melalui halaman ini.</p>}</div>
    </WorkspaceModal>}
    {dialog?.kind === 'import' && <WorkspaceModal className="ay-dialog" eyebrow="IMPORT EXCEL" title={confirmImport ? 'Konfirmasi import' : 'Import tahun akademik'} description="Validasi seluruh baris sebelum menyimpan data." busy={busy} onClose={close} footer={<><Button variant="secondary" disabled={busy} onClick={confirmImport ? () => setConfirmImport(false) : close}>{confirmImport ? 'Kembali' : 'Batal'}</Button>{confirmImport ? <Button loading={busy} disabled={!canImport(preview)} onClick={importFile}>Simpan {preview?.summary.total_rows} periode</Button> : <><Button variant="outline" disabled={!file || busy} onClick={checkFile} loading={busy}>Validasi File</Button><Button disabled={!canImport(preview) || busy} onClick={() => setConfirmImport(true)}>Lanjutkan</Button></>}</>}>
      <div className="ay-form"><Errors messages={messages} />{confirmImport ? <div className="ay-inset"><strong>{preview.summary.total_rows} periode siap disimpan</strong><p>{periods.length === 0 ? 'Satu periode pertama sesuai kolom is_active akan aktif.' : 'Periode baru disimpan tidak aktif. Periode berjalan tidak berubah.'} Semua baris divalidasi ulang oleh server.</p></div> : <><div className="ay-upload"><Icon name="upload" size={26} /><div><strong>Unggah template yang sudah diisi</strong><p>.xlsx · Maksimal 2 MB dan 1.000 baris · Satu worksheet</p></div><label htmlFor="ay-file">Pilih file Excel</label><input id="ay-file" type="file" accept=".xlsx" disabled={busy} onChange={changeFile} />{file && <p className="ay-filename">{file.name}</p>}</div><p className="ay-help">{periods.length === 0 ? 'Import pertama: isi tepat satu is_active = 1, sisanya 0.' : 'Isi is_active = 0 pada seluruh baris. Aktivasi dilakukan setelah import.'} Tahun selesai harus satu tahun setelah tahun mulai.</p>{preview && <><div className="ay-preview-stats">{[['Total', preview.summary.total_rows], ['Valid', preview.summary.valid_rows], ['Bermasalah', preview.summary.invalid_rows]].map(([label, value]) => <div key={label}><small>{label}</small><strong>{value}</strong></div>)}</div><div className="ay-preview-filter" aria-label="Filter hasil validasi">{[['all', 'Semua'], ['valid', 'Valid'], ['invalid', 'Bermasalah']].map(([value, label]) => <button type="button" key={value} aria-pressed={previewFilter === value} onClick={() => setPreviewFilter(value)}>{label}</button>)}</div><div className="ay-table-scroll" tabIndex={0} role="region" aria-label="Preview import"><table className="ay-table ay-preview-table"><thead><tr><th>Baris</th><th>Periode</th><th>Status</th><th>Validasi</th></tr></thead><tbody>{visibleRows.map(row => <tr key={row.row}><td>{row.row}</td><td><strong>{yearLabel(row)}</strong><small>{semesterLabel(row.semester)}</small></td><td>{String(row.is_active) === '1' ? 'Aktif' : String(row.is_active) === '0' ? 'Tidak aktif' : 'Tidak valid'}</td><td>{row.valid ? <span className="ay-valid">Valid</span> : <div className="ay-row-errors"><strong>Tidak valid</strong><ul>{row.errors.map((error, i) => <li key={i}>{error}</li>)}</ul></div>}</td></tr>)}{visibleRows.length === 0 && <tr><td colSpan={4}>Tidak ada baris dalam filter ini.</td></tr>}</tbody></table></div><p className="ay-help">{canImport(preview) ? 'Seluruh data valid. Lanjutkan ke konfirmasi penyimpanan.' : 'Perbaiki baris bermasalah, lalu pilih ulang file. Tidak ada data yang disimpan.'}</p></>}</>}</div>
    </WorkspaceModal>}
    <ToastNotification notice={notice} onClose={clearNotice} />
  </div>;
}
