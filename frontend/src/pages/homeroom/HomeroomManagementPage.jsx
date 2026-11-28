import { useEffect, useRef, useState } from 'react'
import PageHeader from '../../components/ui/PageHeader'
import Card from '../../components/ui/Card'
import Table from '../../components/ui/Table'
import Select from '../../components/ui/Select'
import Button from '../../components/ui/Button'
import Badge from '../../components/ui/Badge'
import Modal from '../../components/ui/Modal'
import EmptyState from '../../components/ui/EmptyState'
import ErrorState from '../../components/ui/ErrorState'
import LoadingState from '../../components/ui/LoadingState'
import Input from '../../components/ui/Input'
import ToastNotification from '../../components/ui/ToastNotification.jsx'
import HomeroomCandidatePicker from './HomeroomCandidatePicker.jsx'
import HomeroomContinuationDialog from './HomeroomContinuationDialog.jsx'
import { homeroomApi } from '../../services/homeroomApi'
import { academicYearApi } from '../../services/academicYearApi'
import { collectPages } from '../../utils/teachingScope.js'
import { readClassTarget } from '../classes/classDirectoryLogic.js'
import { statusLabels, statusVariants, accountLabels, yearLabel, classLabel, dateLabel, workspaceData, filterAssignments, paginate, continuationTargets, eligibleCandidates } from './homeroomManagementLogic.js'
import './HomeroomManagementPage.css'

const columns = [{key:'teacher',label:'Guru / NIP'},{key:'class',label:'Rombel'},{key:'date',label:'Masa penugasan'},{key:'status',label:'Status'},{key:'actions',label:'Aksi'}]
const option = (value,label) => ({value:String(value),label})
const stateText = {active:'Periode aktif',inactive:'Periode nonaktif',none:'Tidak ada periode aktif',conflict:'Periode aktif ganda'}
function Mark({kind='person'}) {return <span className="hm-mark"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round">{kind==='calendar'?<><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 10h18m-13 5h8"/></>:kind==='school'?<path d="M3 10l9-6 9 6v10H3zM9 20v-6h6v6M3 10h18"/>:kind==='alert'?<><path d="M12 3 2 20h20zM12 9v4m0 3v1"/></>:<><circle cx="10" cy="7" r="3"/><path d="M3 21v-3a7 7 0 0 1 14 0v3m2-10v6m-3-3h6"/></>}</svg></span>}
function Status({value}) { return <Badge variant={statusVariants[value]} dot>{statusLabels[value]}</Badge> }
function errorText(error) { return Object.values(error?.payload?.errors || error?.validationErrors || {}).flat().find(Boolean) || error?.message || 'Permintaan gagal. Coba lagi.' }

export default function HomeroomManagementPage() {
  const [target] = useState(()=>readClassTarget(window.location.search))
  const [years,setYears] = useState(null), [year,setYear] = useState(target.academic_year_id)
  const [classId,setClassId] = useState(target.class_id), [tab,setTab] = useState('current'), [status,setStatus] = useState(''), [search,setSearch] = useState(''), [page,setPage] = useState(1)
  const [snapshot,setSnapshot] = useState(null), [loadError,setLoadError] = useState(''), [yearsError,setYearsError] = useState(''), [revision,setRevision] = useState(0), [yearRevision,setYearRevision] = useState(0)
  const [dialog,setDialog] = useState(null), [candidateState,setCandidateState] = useState(null), [formError,setFormError] = useState(''), [notice,setNotice] = useState(''), [busy,setBusy] = useState(false)
  const busyRef = useRef(false)
  const [continuation,setContinuation]=useState(false)
  const period=years?.find(y=>String(y.id)===year)
  const data = snapshot?.year === year && snapshot?.revision === revision ? snapshot.data : null
  useEffect(()=>{
    let alive = true
    collectPages(p=>academicYearApi.getAll({page:p,per_page:100})).then(rows=>{
      if (!alive) return
      if(target.academic_year_id && !rows.some(y=>String(y.id)===target.academic_year_id))setClassId('')
      setYears(rows); setYearsError('')
      setYear(previous=>{
        if (previous && rows.some(y=>String(y.id)===previous)) return previous
        const active = rows.filter(y=>[true,1,'1'].includes(y.is_active))
        return String((active.length===1 ? active[0] : null)?.id || '')
      })
    }).catch(e=>{if(alive)setYearsError(errorText(e))})
    return ()=>{alive=false}
  },[yearRevision])
  useEffect(()=>{
    if(!year)return
    let alive=true
    homeroomApi.workspace(year).then(r=>{
      if(alive){setSnapshot({year,revision,data:workspaceData(r,year)});setLoadError('')}
    }).catch(e=>{if(alive)setLoadError(errorText(e))})
    return ()=>{alive=false}
  },[year,revision])
  const mode=dialog?.mode, candidateClass=dialog?.classId, candidateStart=dialog?.start, replaceId=dialog?.mode==='replace'?dialog.record.id:null
  const candidateKey = JSON.stringify([mode,candidateClass,candidateStart,replaceId,revision])
  useEffect(()=>{
    if(!['create','replace'].includes(mode)||!candidateClass||!candidateStart)return
    let alive=true
    homeroomApi.candidates({class_id:candidateClass,tanggal_mulai:candidateStart,replace_id:replaceId}).then(r=>{
      const rows=eligibleCandidates(r)
      if(alive)setCandidateState({key:candidateKey,rows,error:''})
    }).catch(e=>{if(alive)setCandidateState({key:candidateKey,rows:[],error:errorText(e)})})
    return ()=>{alive=false}
  },[mode,candidateClass,candidateStart,replaceId,candidateKey])
  const candidates=candidateState?.key===candidateKey?candidateState:null
  const recordMap=new Map((data?.assignments||[]).map(a=>[String(a.record.id),a.record]))
  const list=paginate(filterAssignments(data?.assignments||[],{tab,classId,status,search}),page)
  const selectedClass=data?.classes.find(c=>String(c.id)===dialog?.classId)
  const eligibleClass=data?.classes.filter(c=>c.status==='unassigned')||[]
  function refresh() {setLoadError('');setRevision(v=>v+1);setYearRevision(v=>v+1)}
  function filter(setter,value){setter(value);setPage(1)}
  function selectYear(value){if(busyRef.current||value===year)return;setYear(value);setClassId('');setPage(1);setStatus('');setNotice('');setLoadError('');setSnapshot(null)}
  function open(mode,record=null,cls=null){
    if(!data||busyRef.current)return
    setCandidateState(null);setFormError('');setNotice('')
    setDialog({mode,record,reason:'',classId:String(record?.kelas_id||cls?.id||(eligibleClass.some(c=>String(c.id)===classId)?classId:'')),start:mode==='edit'?record.tanggal_mulai:mode==='create'&&data.active_state!=='active'?'':data.as_of,guruId:mode==='edit'?String(record.guru_id):''})
  }
  function changeDialog(field,value){setFormError('');setDialog(d=>({...d,[field]:value,...(['classId','start'].includes(field)?{guruId:field==='start'&&d.mode==='edit'?d.guruId:''}:{})}))}
  async function submit(e){
    e?.preventDefault();if(busyRef.current||!dialog||!data)return
    const {mode,record,classId,start,guruId}=dialog
    if(['create','replace'].includes(mode)&&(!classId||!start||!candidates?.rows.some(g=>String(g.id)===guruId))){setFormError('Pilih rombel, tanggal mulai, dan calon wali yang tersedia.');return}
    if(mode==='cancel'&&!dialog.reason.trim()){setFormError('Alasan pembatalan wajib diisi.');return}
    if(mode==='edit'&&!start){setFormError('Tanggal mulai wajib diisi.');return}
    busyRef.current=true;setBusy(true);setFormError('')
    try{
      if(mode==='create')await homeroomApi.create({guru_id:Number(guruId),kelas_id:Number(classId),tahun_akademik_id:Number(year),tanggal_mulai:start,tanggal_selesai:null})
      else if(mode==='replace')await homeroomApi.replace(record.id,{guru_id:Number(guruId),version:record.version,as_of:data.as_of})
      else if(mode==='cancel')await homeroomApi.cancel(record.id,{version:record.version,as_of:data.as_of,reason:dialog.reason.trim()})
      else if(mode==='end')await homeroomApi.end(record.id,record.version,data.as_of)
      else await homeroomApi.update(record.id,{tanggal_mulai:start,version:record.version})
      setDialog(null);setNotice(mode==='cancel'?'Penugasan dibatalkan. Riwayat tersimpan.':mode==='replace'?'Wali Kelas diganti. Riwayat lama tetap tersimpan.':mode==='end'?'Penugasan diakhiri. Riwayat tetap tersimpan.':'Penugasan berhasil disimpan.');setPage(1);refresh()
    }catch(e){setFormError(errorText(e));if(e?.status===409){setDialog(null);setNotice('Data berubah. Daftar dimuat ulang; ulangi tindakan setelah memeriksa data terbaru.');refresh()}}
    finally{busyRef.current=false;setBusy(false)}
  }
  function renderCell({record:r,status:s,account_state},column){
    if(column.key==='teacher')return <div className="hm-identity"><strong>{r.guru?.nama_lengkap||'Guru tidak tersedia'}</strong><span>{r.guru?.nip||'NIP belum tersedia'}</span>{account_state==='inactive'&&<Badge variant="warning">Akun nonaktif</Badge>}{account_state==='pending'&&<Badge variant="neutral">Belum aktivasi</Badge>}</div>
    if(column.key==='class')return <div className="hm-identity"><strong>{r.kelas?classLabel(r.kelas):'Kelas tidak tersedia'}</strong>{String(r.tahun_akademik_id)!==year&&<span>Periode tidak sesuai</span>}</div>
    if(column.key==='date')return <div className="hm-identity"><strong>{dateLabel(r.tanggal_mulai)}</strong><span>{r.cancelled_at?`Dibatalkan ${new Intl.DateTimeFormat('id-ID',{dateStyle:'short',timeZone:'Asia/Jakarta'}).format(new Date(r.cancelled_at))}`:r.tanggal_selesai?`Selesai ${dateLabel(r.tanggal_selesai)}`:'Terbuka'}</span>{r.cancel_reason&&<span>{r.cancel_reason}</span>}</div>
    if(column.key==='status')return <Status value={s}/>
    return <div className="hm-actions">{s==='effective'?<><Button size="small" variant="secondary" disabled={busy} onClick={()=>open('replace',r)}>Ganti</Button><Button size="small" variant="secondary" disabled={busy} onClick={()=>open('end',r)}>Akhiri</Button></>:s==='scheduled'?<><Button size="small" variant="secondary" disabled={busy} onClick={()=>open('edit',r)}>Koreksi tanggal</Button><Button size="small" variant="secondary" disabled={busy} onClick={()=>open('cancel',r)}>Batalkan</Button></>:<span className="hm-muted">{s==='conflict'?'Perlu audit':'Tersimpan'}</span>}</div>
  }
  return <section className="homeroom-management-page">
    <PageHeader eyebrow="ADMINISTRASI AKADEMIK" title="Manajemen Wali Kelas" description="Pemetaan rombongan belajar dan riwayat penugasan guru per semester." actions={<>{continuationTargets(period,years||[]).length>0&&<Button variant="secondary" disabled={!data||busy||!data.assignments.some(a=>a.status==='effective')} onClick={()=>setContinuation(true)}>Lanjutkan semester</Button>}<Button disabled={!data||!eligibleClass.length||busy} onClick={()=>open('create')}>+ Tambah penugasan</Button></>}/>
    <ToastNotification notice={notice?{type:notice.startsWith('Data berubah')?'warning':'success',title:notice.startsWith('Data berubah')?'Perlu muat ulang':'Penugasan Wali Kelas',message:notice}:null} onClose={()=>setNotice('')}/>
    <Card><div className="hm-context"><div className="hm-context-heading"><Mark kind="calendar"/><div><strong>Periode akademik</strong><div className="hm-context-status">{data&&<><Badge variant={data.active_state==='active'?'success':data.active_state==='inactive'?'neutral':'warning'}>{stateText[data.active_state]}</Badge><span>Data per {dateLabel(data.as_of)} WIB</span></>}</div></div></div><Select id="hm-year" label="Pilih periode" options={(years||[]).map(y=>option(y.id,yearLabel(y)+([true,1,'1'].includes(y.is_active)?' · Aktif':'')))} value={year} disabled={busy||!years} onChange={e=>selectYear(e.target.value)}/><Button variant="secondary" disabled={busy||!year} onClick={refresh}>Muat ulang</Button></div>{data&&data.active_state!=='active'&&<p className="hm-note">Periode terpilih bukan periode aktif. Penugasan di sini tidak memberikan akses binaan saat ini.</p>}</Card>
    {yearsError?<><ErrorState message={yearsError}/><Button onClick={()=>{setYearsError('');setYearRevision(v=>v+1)}}>Coba lagi</Button></>:!years?<LoadingState message="Memuat periode…"/>:!year?<EmptyState title={years.length?"Pilih periode akademik":"Belum ada periode akademik"} message={years.length?"Pilih periode untuk melihat pemetaan rombel.":"Buat periode di modul Tahun Akademik."}/>:loadError?<ErrorState message={loadError}/>:!data?<LoadingState message="Memuat pemetaan Wali Kelas…"/>:<>
      <div className="hm-summary-section"><div className="hm-summary-heading"><strong>Ringkasan penugasan</strong><span>Seluruh rombel · periode terpilih</span></div><div className="hm-summary" aria-label="Ringkasan rombel">{[['total','Total rombel','Seluruh tingkat','school'],['effective','Wali berlaku','Penugasan efektif','person'],['unassigned','Belum ditugaskan','Memerlukan wali','person'],['scheduled','Terjadwal','Belum mulai','calendar'],['conflict','Konflik data','Perlu pemeriksaan','alert']].map(([key,label,hint,kind])=><div key={key} className={`hm-metric hm-metric--${key}`}><div><span>{label}</span><strong>{data.summary[key]}</strong><small>{hint}</small></div><Mark kind={kind}/></div>)}</div><div className="hm-coverage"><span>Cakupan penugasan berlaku</span><progress value={data.summary.effective} max={data.summary.total||1}/><strong>{data.summary.total?Math.round(data.summary.effective/data.summary.total*100)+'%':'—'}</strong></div></div>
      {data.assignments.some(a=>['effective','scheduled'].includes(a.status)&&a.account_state==='inactive')&&<div className="hm-warning" role="alert">Ada Wali Kelas dengan akun nonaktif. Penugasan tetap tercatat; tinjau pergantian atau pengakhiran.</div>}
      {data.assignments.some(a=>a.status==='conflict')&&<div className="hm-warning" role="alert">Ada konflik penugasan. Periksa daftar dan riwayat; data konflik tidak diperbaiki otomatis. Hak akses lama tidak diubah oleh workspace ini.</div>}
      <Card><div className="hm-section-heading"><div><span className="hm-eyebrow">ROMBONGAN BELAJAR</span><h2>Pemetaan Wali Kelas</h2><p>Pilih rombel untuk melihat penugasannya atau menambahkan wali.</p></div>{classId&&<Button variant="secondary" size="small" onClick={()=>filter(setClassId,'')}>Tampilkan semua</Button>}</div>
      {!data.classes.length?<EmptyState title="Belum ada rombel" message="Tambahkan kelas pada periode ini terlebih dahulu."/>:<div className="hm-map">{[...new Set(data.classes.map(c=>c.tingkat))].map(grade=><section className="hm-grade" key={grade}><h3>Kelas {({10:'X',11:'XI',12:'XII'})[grade]||grade} <small>{data.classes.filter(c=>c.tingkat===grade).length} rombel</small></h3><div className="hm-grade-grid">{data.classes.filter(c=>c.tingkat===grade).map(c=><div key={c.id} className={`hm-class ${String(c.id)===classId?'hm-class--selected':''}`}><button type="button" className="hm-class-main" onClick={()=>{filter(setClassId,String(c.id));setTab('current');setStatus('');setSearch('')}}><div className="hm-class-title"><Mark kind="school"/><strong>{c.nama}</strong></div><span>{c.assignment_ids.map(id=>recordMap.get(String(id))?.guru?.nama_lengkap).filter(Boolean).join(', ')||'Belum ada guru pembina'}</span><Status value={c.status}/>{data.assignments.some(a=>c.assignment_ids.some(id=>String(id)===String(a.record.id))&&a.account_state==='inactive')&&<Badge variant="warning">Akun nonaktif</Badge>}</button>{c.status!=='unassigned'&&<Button size="small" variant="secondary" disabled={busy} onClick={()=>{filter(setClassId,String(c.id));setTab('current');setStatus('');setSearch('');document.getElementById('hm-directory')?.scrollIntoView({block:'start',behavior:'smooth'})}}>Lihat penugasan ›</Button>}{c.status==='unassigned'&&<Button size="small" variant="secondary" disabled={busy} onClick={()=>open('create',null,c)}>Tugaskan</Button>}</div>)}</div></section>)}</div>}</Card>
      <Card><div id="hm-directory" className="hm-section-heading"><div><span className="hm-eyebrow">DATA AKADEMIK</span><h2>Daftar Penugasan</h2><p>Riwayat tidak dihapus ketika wali berganti.</p></div><span className="hm-muted">{list.total} hasil</span></div><div className="hm-tabs" aria-label="Tampilan penugasan">{[['current','Penugasan'],['history','Riwayat']].map(([value,label])=><button type="button" key={value} aria-pressed={tab===value} onClick={()=>{setTab(value);setStatus('');setPage(1)}}>{label}</button>)}</div><div className="hm-filters"><Input id="hm-search" label="Cari guru / NIP / kelas" value={search} onChange={e=>filter(setSearch,e.target.value)} placeholder="Cari penugasan"/><Select id="hm-class" label="Rombel" value={classId} options={data.classes.map(c=>option(c.id,classLabel(c)))} placeholder="Semua rombel" onChange={e=>filter(setClassId,e.target.value)}/><Select id="hm-status" label="Status" value={status} options={(tab==='history'?['history','cancelled','conflict']:['effective','scheduled','conflict']).map(s=>option(s,statusLabels[s]))} placeholder="Semua status" onChange={e=>filter(setStatus,e.target.value)}/><Button variant="secondary" onClick={()=>{setSearch('');setClassId('');setStatus('');setPage(1)}}>Reset</Button></div><Table columns={columns} data={list.rows.map(a=>({...a,id:a.record.id}))} renderCell={renderCell} scrollLabel="Tabel penugasan Wali Kelas" emptyMessage="Tidak ada penugasan sesuai filter."/><div className="hm-pagination"><span>Halaman {list.current} dari {list.last} · {list.total} penugasan</span><div><Button variant="secondary" size="small" disabled={list.current<=1} onClick={()=>setPage(list.current-1)}>Sebelumnya</Button><Button variant="secondary" size="small" disabled={list.current>=list.last} onClick={()=>setPage(list.current+1)}>Berikutnya</Button></div></div></Card>
    </>}
    <Modal open={Boolean(dialog)} title={{create:'Tambah penugasan',replace:'Ganti Wali Kelas',end:'Akhiri penugasan',edit:'Koreksi tanggal mulai',cancel:'Batalkan penugasan'}[mode]} description="Periksa konteks dan identitas sebelum menyimpan." onClose={()=>{if(!busyRef.current)setDialog(null)}} footer={<><Button variant="secondary" disabled={busy} onClick={()=>setDialog(null)}>Batal</Button><Button form="hm-form" type="submit" loading={busy} disabled={!data||(mode==='cancel'&&!dialog?.reason.trim())||( ['create','replace'].includes(mode)&&!candidates?.rows.some(g=>String(g.id)===dialog?.guruId))}>{mode==='cancel'?'Konfirmasi pembatalan':mode==='end'?'Akhiri':mode==='replace'?'Konfirmasi pergantian':'Simpan penugasan'}</Button></>}>
      {dialog&&<form id="hm-form" onSubmit={submit} className="hm-form">
        <div className="hm-form-context"><span>Periode penugasan · mengikuti filter halaman</span><strong>{years?.find(y=>String(y.id)===year)?yearLabel(years.find(y=>String(y.id)===year)):'Periode tidak tersedia'}</strong>{dialog.record&&<span>{dialog.record.guru?.nama_lengkap} · {dialog.record.kelas?classLabel(dialog.record.kelas):'Rombel tidak tersedia'}</span>}</div>
        {mode==='cancel'?<><p>Penugasan belum mulai. Pembatalan tidak menghapus record dan tidak memberikan hak binaan.</p><label className="hm-reason">Alasan pembatalan<textarea required maxLength={500} value={dialog.reason} disabled={busy} onChange={e=>changeDialog('reason',e.target.value)}/></label></>:mode==='end'?<p>Penugasan ditutup hari ini ({dateLabel(data?.as_of)} WIB). Hak binaan berakhir langsung; akun, hak mengajar, dan riwayat tetap tersimpan.</p>:<>
          {mode==='create'&&<Select id="hm-form-class" label="Rombel" required value={dialog.classId} disabled={busy} options={eligibleClass.map(c=>option(c.id,classLabel(c)))} onChange={e=>changeDialog('classId',e.target.value)}/>}
          <Input id="hm-start" label="Tanggal mulai" type="date" value={dialog.start} disabled={busy||mode==='replace'} required onChange={e=>changeDialog('start',e.target.value)}/>
          {['create','replace'].includes(mode)&&<><HomeroomCandidatePicker key={candidateKey} id="hm-guru" label={mode==='replace'?'Guru pengganti':'Calon Wali Kelas'} required disabled={busy||!candidates||!candidates.rows.length} value={dialog.guruId} options={(candidates?.rows||[]).map(g=>({value:String(g.id),label:g.nama_lengkap,description:`${g.nip||'NIP belum tersedia'} · ${accountLabels[g.account_state]||'Status tidak diketahui'}`,searchText:g.nip||''}))} onChange={v=>changeDialog('guruId',v)} placeholder={!candidateClass||!candidateStart?'Pilih rombel dan tanggal':!candidates?'Memuat calon…':'Pilih guru'}/>{candidates?.error?<p role="alert" className="hm-form-error">{candidates.error}</p>:candidates&&!candidates.rows.length?<p className="hm-note">Tidak ada calon yang memenuhi konteks penugasan ini.</p>:null}{candidates?.rows.find(g=>String(g.id)===dialog.guruId)?.account_state&&candidates.rows.find(g=>String(g.id)===dialog.guruId).account_state!=='ready'&&<p className="hm-warning">Akun guru belum siap. Penugasan tidak mengaktifkan akun atau melewati proses aktivasi.</p>}</>}
          {mode==='replace'&&<div className="hm-replacement"><div><small>Wali lama</small><strong>{dialog.record.guru?.nama_lengkap}</strong></div><span aria-hidden="true">→</span><div><small>Wali pengganti</small><strong>{candidates?.rows.find(g=>String(g.id)===dialog.guruId)?.nama_lengkap||'Belum dipilih'}</strong></div></div>}{mode==='replace'&&<p className="hm-note">Penugasan lama ditutup dan penugasan baru dibuat pada hari ini dalam satu transaksi. Tidak ada penggantian identitas pada riwayat.</p>}
          {mode==='edit'&&<p className="hm-note">Koreksi hanya tanggal mulai. Identitas guru, rombel, dan periode tidak berubah.</p>}
          {mode==='create'&&selectedClass&&<p className="hm-note">{classLabel(selectedClass)} · Penugasan terbuka sampai TU mengakhirinya.</p>}
        </>}{formError&&<p className="hm-form-error" role="alert">{formError}</p>}
      </form>}
    </Modal>
    {continuation&&period&&data&&<HomeroomContinuationDialog source={period} years={years} data={data} onClose={()=>setContinuation(false)} onSaved={target=>{setContinuation(false);selectYear(target);setNotice('Kelanjutan semester tersimpan. Periode aktif tidak berubah.')}}/>}
  </section>
}
