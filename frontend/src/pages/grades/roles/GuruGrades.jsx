import {useCallback,useEffect,useRef,useState} from 'react'
import {useAuth} from '../../../context/useAuth'
import {scheduleApi} from '../../../services/scheduleApi'
import {academicYearApi} from '../../../services/academicYearApi'
import {gradeApi} from '../../../services/gradeApi'
import {classMemberApi} from '../../../services/classMemberApi'
import SearchableSelect from '../../../components/ui/SearchableSelect'
import WorkspaceModal from '../../../components/ui/WorkspaceModal'
import ToastNotification from '../../../components/ui/ToastNotification'
import ConfirmDialog from '../../../components/ui/ConfirmDialog'
import {allPages,activeStudents,buildRows,classId,dirty,errorText,gradeParams,idOf,makeRow,ownComponents,ownSchedules,parseDecimal,patchStudentRow,same,scopedGrades,validScore} from './gradeScope'
import {loadOwnTeachingSchedules,teacherId} from '../../../utils/teachingScope'
import '../styles/GuruGrades.css'

const params = page=>({page,per_page:100})
const label = s=>`${s?.kelas?.nama||'Kelas belum tersedia'} · Tingkat ${s?.kelas?.tingkat??'belum tersedia'}`
const subject = s=>s?.mapel?.nama||'Mata pelajaran belum tersedia'
const time = s=>`${s?.hari||'–'} · ${String(s?.jam_mulai||'').slice(0,5)||'–'}–${String(s?.jam_selesai||'').slice(0,5)||'–'}`
const period = y=>y?`${y.tahun_mulai??'–'}/${y.tahun_selesai??'–'} · ${y.semester||'–'}`:'Belum tersedia'
const initialForm = {nama:'',bobot:''}
const optionsStatus=[{value:'all',label:'Semua siswa'},{value:'saved',label:'Sudah dinilai'},{value:'empty',label:'Belum dinilai'},{value:'dirty',label:'Belum disimpan'}]
function State({children,error=false,onRetry}){return <div className={`gg-state ${error?'error':''}`} role={error?'alert':'status'}>{children}{onRetry&&<button type="button" className="gg-btn" onClick={onRetry}>Coba lagi</button>}</div>}
function useLoad(key,loader){
  const [state,setState]=useState({key:null,data:null,error:'',loading:false})
  useEffect(()=>{let alive=true;if(!key)return;setState({key,data:null,error:'',loading:true});Promise.resolve().then(loader).then(data=>{if(alive)setState({key,data,error:'',loading:false})}).catch(e=>{if(alive)setState({key,data:null,error:errorText(e),loading:false})});return()=>{alive=false}},[key,loader])
  return state.key===key?state:{data:null,error:'',loading:Boolean(key)}
}
export default function GuruGrades(){
  const {user}=useAuth()
  const guru=teacherId(user)
  // Remount on identity change; a user ID is not interchangeable with guru.id.
  return <GradeWorkspace key={`${user?.id??''}:${guru??''}`} user={user} guru={guru}/>
}
function GradeWorkspace({user,guru}){
  const [revision,setRevision]=useState(0),[scheduleChoice,setScheduleChoice]=useState(''),[componentChoice,setComponentChoice]=useState('')
  const [componentRevision,setComponentRevision]=useState(0),[rosterRevision,setRosterRevision]=useState(0)
  const [notice,setNotice]=useState(null),[confirm,setConfirm]=useState(null),[editor,setEditor]=useState(null),[form,setForm]=useState(initialForm),[formError,setFormError]=useState(''),[deleteTarget,setDeleteTarget]=useState(null),[deleteError,setDeleteError]=useState('')
  const [busy,setBusy]=useState(''),lock=useRef(false)
  const [draft,setDraft]=useState({key:'',rows:[]}),[search,setSearch]=useState(''),[status,setStatus]=useState('all')
  const notify=(type,title,message)=>setNotice({type,title,message})
  const dismissNotice=useCallback(()=>setNotice(null),[])
  const loadSchedules=useCallback(async()=>{
    if(guru==null)throw new Error('Identitas Guru belum tersedia. Masuk kembali atau hubungi Tata Usaha.')
    const years=await allPages(page=>academicYearApi.getAll(params(page)))
    const active=years.filter(y=>[true,1,'1'].includes(y.is_active))
    if(active.length>1)throw new Error('Lebih dari satu periode aktif. Hubungi Tata Usaha.')
    const year=active[0]||null
    if(!year)return {year:null,schedules:[]}
    const data=await loadOwnTeachingSchedules(scheduleApi,guru,year.id)
    return {year,schedules:ownSchedules(data,guru,year.id)}
  },[guru])
  const sr=useLoad(`schedule:${revision}`,loadSchedules)
  const schedules=sr.data?.schedules||[],year=sr.data?.year
  const schedule=schedules.find(s=>same(s.id,scheduleChoice))||schedules[0]||null
  const sid=idOf(schedule?.id),cidClass=classId(schedule)
  const loadComponents=useCallback(async()=>ownComponents(await allPages(page=>gradeApi.getComponents(gradeParams(year.id,sid,params(page)))),schedule),[sid,year?.id,schedule])
  const cr=useLoad(sid?`${sid}:${componentRevision}`:'',loadComponents)
  const components=cr.data||[]
  const component=components.find(c=>same(c.id,componentChoice))||components[0]||null
  const cid=idOf(component?.id)
  const rosterKey=sid&&cid?`${sid}:${cid}:${rosterRevision}:${componentRevision}`:''
  const loadRoster=useCallback(async()=>{
    const [members,grades]=await Promise.all([allPages(page=>classMemberApi.getAll(cidClass,params(page))),allPages(page=>gradeApi.getGrades(gradeParams(year.id,sid,{...params(page),component_id:cid})))])
    return buildRows(activeStudents(members,cidClass),grades,cid,schedule)
  },[sid,cid,cidClass,year?.id,schedule])
  const rr=useLoad(rosterKey,loadRoster)
  const rows=draft.key===rosterKey && rr.data?draft.rows:rr.data||[]
  const pending=rows.filter(dirty).length
  const savedCount=rows.filter(r=>r.gradeId!=null).length
  const visible=rows.filter(r=>`${r.student.nama_lengkap||''} ${r.student.nis||''} ${r.student.nisn||''}`.toLocaleLowerCase('id').includes(search.trim().toLocaleLowerCase('id'))&&(status==='all'||(status==='saved'&&r.gradeId!=null)||(status==='empty'&&r.gradeId==null)||(status==='dirty'&&dirty(r))))
  const canManage=Boolean(schedule&&same(schedule.id,sid)&&ownSchedules([schedule],guru,year?.id).length)
  const kkm=schedule?.mapel?.kkm
  const scheduleOptions=schedules.map(s=>({value:idOf(s.id),label:label(s),description:`${subject(s)} · ${time(s)}`}))
  function discardDraft(){setDraft({key:'',rows:[]});setSearch('');setStatus('all')}
  function guard(action){if(lock.current)return;if(pending)setConfirm({action});else action()}
  function changeSchedule(value){if(same(value,sid)||!schedules.some(s=>same(s.id,value)))return;guard(()=>{discardDraft();setScheduleChoice(value);setComponentChoice('')})}
  function changeComponent(value){if(same(value,cid)||!components.some(c=>same(c.id,value)))return;guard(()=>{discardDraft();setComponentChoice(value)})}
  function refresh(){guard(()=>{discardDraft();setScheduleChoice('');setComponentChoice('');setComponentRevision(v=>v+1);setRosterRevision(v=>v+1);setRevision(v=>v+1)})}
  function reloadRoster(){guard(()=>{discardDraft();setRosterRevision(v=>v+1)})}
  function updateRow(id,patch){setDraft(old=>({key:rosterKey,rows:patchStudentRow(old.key===rosterKey?old.rows:rows,id,patch)}))}
  function start(){if(lock.current)return false;lock.current=true;return true}
  function finish(){lock.current=false;setBusy('')}
  function openEditor(item=null){if(!canManage||lock.current)return;guard(()=>{setEditor({item,sid});setForm(item?{nama:String(item.nama||''),bobot:String(item.bobot??'')}:initialForm);setFormError('')})}
  async function saveComponent(e){e.preventDefault();if(!canManage||!same(editor?.sid,sid)||lock.current)return
    const nama=form.nama.trim(),bobot=parseDecimal(form.bobot)
    if(!nama||nama.length>100){setFormError('Nama komponen wajib diisi, maksimal 100 karakter.');return}
    if(!Number.isFinite(bobot)||bobot<0||bobot>999.99){setFormError('Bobot harus berada pada rentang 0–999,99 sesuai kapasitas penyimpanan.');return}
    if(editor.item&&!components.some(c=>same(c.id,editor.item.id)))return
    if(!start())return;setBusy('component');setFormError('')
    try{if(editor.item)await gradeApi.updateComponent(editor.item.id,{nama,bobot});else await gradeApi.createComponent({jadwal_pelajaran_id:Number(sid),nama,bobot});setEditor(null);discardDraft();setComponentRevision(v=>v+1);notify('success','Komponen berhasil disimpan','Daftar komponen dimuat ulang.')}
    catch(e){setFormError(errorText(e))}finally{finish()}
  }
  function openDelete(item){if(!canManage||lock.current)return;guard(()=>{setDeleteTarget({item,sid});setDeleteError('')})}
  async function removeComponent(){if(!canManage||!same(deleteTarget?.sid,sid)||!components.some(c=>same(c.id,deleteTarget.item.id))||!start())return;setBusy('delete');setDeleteError('');try{await gradeApi.deleteComponent(deleteTarget.item.id);setDeleteTarget(null);discardDraft();setComponentChoice('');setComponentRevision(v=>v+1);notify('success','Komponen berhasil dihapus','Daftar komponen diperbarui.')}catch(e){setDeleteError(errorText(e))}finally{finish()}}
  async function saveRow(row){
    if(!canManage||!component||!same(component.id,cid)||!rows.some(r=>same(r.student.id,row.student.id))||lock.current||row.uncertain||!dirty(row))return
    const id=row.student.id
    if(!validScore(row.nilai)){updateRow(id,{error:'Masukkan nilai 0–100 dengan angka yang valid.'});return}
    if(!start())return;setBusy(`grade:${id}`);updateRow(id,{error:''})
    const payload={nilai:parseDecimal(row.nilai),catatan:row.catatan.trim()||null}
    try{
      if(row.gradeId!=null){await gradeApi.updateGrade(row.gradeId,payload)}else{
        await gradeApi.createGrade({...payload,komponen_nilai_id:Number(cid),siswa_id:Number(id)})
      }
    }catch(e){const uncertain=!e?.status||e.status>=500;updateRow(id,{uncertain,error:uncertain?'Hasil penyimpanan belum pasti. Muat ulang sebelum mencoba kembali.':errorText(e)});notify(uncertain?'warning':'error',uncertain?'Perlu konfirmasi ulang':'Gagal menyimpan nilai',uncertain?'Periksa nilai tersimpan sebelum mengulang.':errorText(e));finish();return}
    // Reload only this student's record, never the whole draft. If the read fails,
    // prevent duplicate POST until authoritative data has been refreshed.
    try{
      const result=scopedGrades(await allPages(page=>gradeApi.getGrades(gradeParams(year.id,sid,{...params(page),component_id:cid,student_id:id}))),cid,[row.student],schedule)
      if(result.length!==1)throw new Error('Nilai belum dapat dikonfirmasi dari server.')
      updateRow(id,makeRow(row.student,result[0]));notify('success','Nilai berhasil disimpan',`${row.student.nama_lengkap||'Siswa'} · ${component.nama}`)
    }catch(e){updateRow(id,{uncertain:true,error:'Server menerima penyimpanan, tetapi data terbaru belum dapat dikonfirmasi. Muat ulang sebelum menyunting nilai ini.'});notify('warning','Perlu konfirmasi ulang','Muat ulang daftar setelah menyimpan perubahan siswa lain.')}
    finally{finish()}
  }
  const disabled=Boolean(busy)||!canManage
  return <main className="gg-v2">
    <header className="gg-head"><div><p className="gg-eyebrow">KEGIATAN MENGAJAR</p><h1>Penilaian Siswa<span>.</span></h1><p>Kelola komponen penilaian dan hasil belajar sesuai penugasan Anda.</p></div><button type="button" className="gg-btn" disabled={!!busy||sr.loading} onClick={refresh}>↻ Muat ulang</button></header>
    <section className="gg-band"><div><p className="gg-eyebrow">RUANG PENILAIAN AKADEMIK</p><h2>{schedule?subject(schedule):'Buku nilai akademik'}</h2><p>{schedule?label(schedule):'Pilih jadwal mengajar Anda'} · {user?.guru?.nama_lengkap||user?.nama||user?.name||'Guru'}</p></div><div><small>Periode aktif</small><strong>{period(year)}</strong></div><div><small>KKM</small><strong className="gg-kkm">{kkm??'—'}</strong></div></section>
    <section className="gg-panel gg-context"><div className="gg-section-head"><div><p className="gg-eyebrow">01 / KONTEKS PEMBELAJARAN</p><h2>Jadwal mengajar</h2><p>Hanya jadwal yang ditugaskan kepada Anda pada periode aktif.</p></div>{sr.data&&<span className="gg-chip">{schedules.length} jadwal Anda</span>}</div>{sr.loading?<State>Memuat jadwal…</State>:sr.error?<State error onRetry={refresh}>{sr.error}</State>:!schedule?<State>{year?'Belum ada jadwal mengajar milik Anda pada periode aktif.':'Belum ada periode akademik aktif.'}</State>:<SearchableSelect label="Pilih jadwal mengajar / kelas" value={sid} options={scheduleOptions} onChange={changeSchedule} disabled={!!busy} required/>}</section>
    {schedule&&canManage&&<div className="gg-layout"><section className="gg-panel gg-components"><div className="gg-section-head"><div><p className="gg-eyebrow">02 / KOMPONEN</p><h2>Komponen penilaian</h2><p>Pilih komponen untuk membuka buku nilai.</p></div></div><button type="button" className="gg-btn primary gg-add" disabled={disabled||cr.loading||!!cr.error} onClick={()=>openEditor()}>+ Tambah komponen</button>{cr.loading?<State>Memuat komponen…</State>:cr.error?<State error onRetry={()=>setComponentRevision(v=>v+1)}>{cr.error}</State>:!components.length?<State>Belum ada komponen untuk jadwal ini.</State>:<><div className="gg-component-list">{components.map(c=><article key={c.id} className={`gg-component ${same(c.id,cid)?'selected':''}`}><button type="button" className="gg-component-select" aria-pressed={same(c.id,cid)} disabled={disabled} onClick={()=>changeComponent(idOf(c.id))}><span><strong>{c.nama}</strong><small>Bobot {c.bobot}</small></span><b>{same(c.id,cid)?'✓':'›'}</b></button><div className="gg-component-actions"><button type="button" disabled={disabled} onClick={()=>openEditor(c)}>Edit</button><button type="button" disabled={disabled} className="danger-text" onClick={()=>openDelete(c)}>Hapus</button></div></article>)}</div><div className="gg-total"><span>Total bobot tercatat</span><strong>{components.reduce((sum,c)=>sum+(Number(c.bobot)||0),0).toLocaleString('id-ID')}</strong></div><p className="gg-help">Bobot mengikuti ketentuan penilaian sekolah.</p></>}</section>
    <section className="gg-panel gg-book"><div className="gg-section-head"><div><p className="gg-eyebrow">03 / BUKU NILAI</p><h2>Input nilai siswa</h2><p>{component?`${component.nama} · Bobot ${component.bobot}`:'Pilih komponen penilaian terlebih dahulu.'}</p></div><button type="button" className="gg-btn" disabled={!component||!!busy||rr.loading} onClick={reloadRoster}>↻ Muat ulang</button></div>{!component?<State>{cr.loading?'Menyiapkan komponen…':'Buku nilai akan tampil setelah komponen dipilih.'}</State>:rr.loading?<State>Memuat anggota kelas dan nilai…</State>:rr.error?<State error onRetry={reloadRoster}>{rr.error}</State>:<><div className="gg-summary">{[['Siswa aktif',rows.length],['Sudah dinilai',savedCount],['Belum dinilai',rows.length-savedCount],['Belum disimpan',pending]].map(([l,n],i)=><div key={l} className={`metric-${i}`}><small>{l}</small><strong>{n}</strong></div>)}</div><div className="gg-filters"><label className="gg-field">Cari siswa<input type="search" value={search} onChange={e=>setSearch(e.target.value)} placeholder="Nama atau NIS/NISN…"/></label><SearchableSelect label="Status nilai" options={optionsStatus} value={status} onChange={setStatus}/></div><div className="gg-table-label"><span>DAFTAR NILAI · {visible.length} siswa</span><span>{pending?`${pending} perubahan belum disimpan`:'Tidak ada perubahan'}</span></div>{!visible.length?<State>{rows.length?'Tidak ada siswa yang cocok dengan filter.':'Tidak ada anggota kelas aktif.'}</State>:<div className="gg-table"><div className="gg-table-head"><span>Siswa / NIS</span><span>Nilai (0–100)</span><span>Catatan</span><span>Aksi</span></div>{visible.map(r=>{const changed=dirty(r),v=parseDecimal(r.nilai),hasValue=r.nilai.trim()!=='';const invalid=hasValue&&!validScore(r.nilai);const name=r.student.nama_lengkap||'Siswa';return <div className="gg-row" key={r.student.id}><div className="gg-student"><span className="gg-avatar">{name.slice(0,1).toUpperCase()}</span><div><strong>{name}</strong><small>NIS {r.student.nis||r.student.nisn||'—'}</small><small className={r.uncertain||changed?'warning-text':r.gradeId!=null?'success-text':''}>{r.uncertain?'Perlu konfirmasi':changed?'Belum disimpan':r.gradeId!=null?'Tersimpan':'Belum dinilai'}</small></div></div><label className="gg-cell"><span>Nilai</span><input aria-label={`Nilai ${name}`} inputMode="decimal" value={r.nilai} disabled={disabled||r.uncertain} aria-invalid={invalid||!!r.error} onChange={e=>updateRow(r.student.id,{nilai:e.target.value,error:''})}/>{invalid?<small className="danger-text">Nilai 0–100</small>:hasValue&&kkm!=null&&Number.isFinite(Number(kkm))&&<small className={v>=Number(kkm)?'success-text':'warning-text'}>{v>=Number(kkm)?'Mencapai KKM':'Di bawah KKM'}</small>}</label><label className="gg-cell"><span>Catatan</span><input aria-label={`Catatan ${name}`} value={r.catatan} disabled={disabled||r.uncertain} onChange={e=>updateRow(r.student.id,{catatan:e.target.value,error:''})} placeholder="Opsional"/></label><div className="gg-row-actions"><button type="button" className="gg-btn primary" onClick={()=>saveRow(r)} disabled={disabled||!changed||!validScore(r.nilai)||r.uncertain}>{busy===`grade:${r.student.id}`?'Menyimpan…':r.gradeId!=null?'Perbarui':'Simpan'}</button>{changed&&!r.uncertain&&<button type="button" className="gg-link" disabled={disabled} onClick={()=>updateRow(r.student.id,{...r.original,error:''})}>Reset</button>}</div>{r.error&&<p className="gg-row-error" role="alert">{r.error}</p>}</div>})}</div>}<div className="gg-book-foot"><span className={pending?'warning-text':'success-text'}>{pending?'● Ada perubahan belum disimpan':'✓ Tidak ada perubahan yang belum disimpan'}</span><span>Nilai disimpan per siswa.</span></div></>}</section></div>}
    {editor&&<WorkspaceModal compact className="gg-modal" eyebrow="PENGELOLAAN PENILAIAN" title={editor.item?'Edit komponen penilaian':'Tambah komponen penilaian'} description="Atur komponen untuk jadwal mengajar terpilih." busy={!!busy} onClose={()=>setEditor(null)} footer={<><button type="button" className="gg-btn" disabled={!!busy} onClick={()=>setEditor(null)}>Batal</button><button type="submit" className="gg-btn primary" form="gg-component-form" disabled={!!busy}>{busy?'Menyimpan…':'Simpan komponen'}</button></>}><form id="gg-component-form" noValidate onSubmit={saveComponent}><div className="gg-context-box"><small>KONTEKS PEMBELAJARAN</small><strong>{subject(schedule)}</strong><span>{label(schedule)} · {time(schedule)}</span></div><label className="gg-field">Nama komponen *<input value={form.nama} maxLength={100} required disabled={!!busy} onChange={e=>{setForm(f=>({...f,nama:e.target.value}));setFormError('')}} placeholder="Contoh: Tugas Harian"/></label><label className="gg-field">Bobot penilaian *<input value={form.bobot} inputMode="decimal" required disabled={!!busy} onChange={e=>{setForm(f=>({...f,bobot:e.target.value}));setFormError('')}} placeholder="Contoh: 20"/><small>Bobot mengikuti ketentuan sekolah.</small></label>{formError&&<p className="gg-row-error" role="alert">{formError}</p>}</form></WorkspaceModal>}
    {deleteTarget&&<ConfirmDialog title="Hapus komponen penilaian?" description="Periksa komponen sebelum menghapus." danger confirmLabel="Hapus komponen" busy={!!busy} error={deleteError} onClose={()=>setDeleteTarget(null)} onConfirm={removeComponent}><p><strong>{deleteTarget.item.nama}</strong> · {subject(schedule)} · {label(schedule)}</p><p>Komponen yang masih memiliki nilai atau tugas terkait tidak dapat dihapus. Data yang sudah tercatat tetap aman.</p></ConfirmDialog>}
    {confirm&&<ConfirmDialog title="Perubahan belum disimpan" description={`${pending} baris nilai memiliki perubahan.`} confirmLabel="Buang perubahan & lanjutkan" onClose={()=>setConfirm(null)} onConfirm={()=>{const action=confirm.action;setConfirm(null);discardDraft();action()}}><p>Simpan nilai terlebih dahulu jika ingin mempertahankan perubahan. Jika dilanjutkan, perubahan yang belum disimpan akan dibuang.</p></ConfirmDialog>}
    <ToastNotification notice={notice} onClose={dismissNotice}/>
  </main>
}
