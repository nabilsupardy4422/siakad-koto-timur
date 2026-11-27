import { useCallback, useEffect, useRef, useState } from 'react'
import { useAuth } from '../../../context/useAuth'
import { reportApi } from '../../../services/reportApi'
import ToastNotification from '../../../components/ui/ToastNotification'
import WorkspaceModal from '../../../components/ui/WorkspaceModal'
import { TYPES, permittedTypes, blankFilters, filterOptions, reportParams, validateContext, validatePreview } from './reportWorkspace.js'
import {makePreparedDownload} from './principalReportDownload.js'
import './GuruReports.css'

const paths={book:'M4 4h6a3 3 0 0 1 3 3v14a4 4 0 0 0-4-3H4z M20 4h-4a3 3 0 0 0-3 3v14a4 4 0 0 1 4-3h3z',users:'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M19 8a3 3 0 0 1 0 6m3 7v-2a4 4 0 0 0-3-4 M9 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8',calendar:'M3 5h18v16H3z M7 3v4m10-4v4M3 10h18',check:'M9 5H5v16h14V5h-4 M9 3h6v4H9z M8 14l3 3 5-6',chart:'M3 3v18h18 M7 15l4-4 4 2 6-7',file:'M5 3h10l4 4v14H5z M14 3v5h5 M8 12h8m-8 4h8',shield:'M12 3l8 3v6c0 4-4 8-8 10-4-2-8-6-8-10V6z M8 12l3 3 5-6',lock:'M6 10h12v11H6z M8 10V7a4 4 0 0 1 8 0v3',eye:'M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6',excel:'M4 3h16v18H4z M4 9h16 M4 15h16 M10 9v12',info:'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20 M12 11v6m0-10v1',download:'M12 3v12m-5-5 5 5 5-5 M4 16v5h16v-5'}
function Icon({name}){return <svg className="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d={paths[name]||paths.file}/></svg>}
function Step({number,title,description}){return <div className="sectiontitle"><span className="step">{number}</span><div><h2>{title}</h2><p>{description}</p></div></div>}
function Field({label,children}){return <label className="field"><span>{label}</span>{children}</label>}
function Locked({value}){return <div className="locked">{value}<Icon name="lock"/></div>}
function SelectField({label,value,options,all,onChange,disabled}){return <Field label={label}><select value={value} disabled={disabled} onChange={e=>onChange(e.target.value)}><option value="">{all}</option>{options.map(o=><option key={o.id} value={o.id}>{o.label}</option>)}</select></Field>}
const display=v=>v===null||v===undefined||v===''?'—':typeof v==='number'?v.toLocaleString('id-ID',{maximumFractionDigits:2}):String(v)
const time=v=>v?String(v).slice(0,5):'—'
function Cell({children,sub}){return <><strong>{children}</strong>{sub&&<small>{sub}</small>}</>}
const identity=r=><Cell sub={`NIS ${display(r.nis)} · NISN ${display(r.nisn)}`}>{display(r.nama_siswa??r.siswa)}</Cell>
const cls=r=><Cell sub={`${display(r.tahun_akademik)} · ${display(r.semester)}`}>{display(r.kelas)} · Tingkat {display(r.tingkat)}</Cell>
const subject=r=><Cell sub={`${display(r.guru)} · ${r.hari?`${r.hari} `:''}${time(r.jam_mulai)}–${time(r.jam_selesai)}`}>{display(r.mata_pelajaran)}</Cell>
const status=v=><span className={`status ${['ALPA','BERAKHIR'].includes(String(v).toUpperCase())?'bad':['HADIR','AKTIF'].includes(String(v).toUpperCase())?'':'warn'}`}>{display(v)}</span>
function columns(type){switch(type){
case 'students':return [['Siswa / identitas',identity],['Kelas',cls],['Mulai',r=>display(r.tanggal_mulai)],['Selesai',r=>display(r.tanggal_selesai)],['Status',r=>status(r.status_keanggotaan)]]
case 'schedules':return [['Hari',r=>display(r.hari)],['Jam',r=>`${time(r.jam_mulai)}–${time(r.jam_selesai)}`],['Mata pelajaran',r=>display(r.mata_pelajaran)],['Guru',r=>display(r.guru)],['Kelas',cls]]
case 'attendance':return [['Tanggal',r=>display(r.tanggal)],['Siswa / identitas',identity],['Mata pelajaran / guru',subject],['Kelas',cls],['Status',r=>status(r.status)],['Catatan',r=>display(r.catatan)]]
case 'grades':return [['Siswa / identitas',identity],['Mata pelajaran / guru',subject],['Kelas',cls],['Komponen',r=>display(r.komponen)],['Bobot',r=>display(r.bobot)],['Nilai',r=><strong>{display(r.nilai)}</strong>],['Catatan',r=>display(r.catatan)]]
default:return [['Siswa / identitas',identity],['Kelas',cls],... [['Hadir','hadir'],['Izin','izin'],['Sakit','sakit'],['Alpa','alpa'],['Total catatan','total_presensi'],['Hadir tercatat (%)','persentase_hadir'],['Jumlah nilai','jumlah_nilai'],['Rata-rata komponen','rata_rata_nilai']].map(([label,key])=>[label,r=>display(r[key])])]}}
function Empty({title,description}){return <div className="empty" role="status"><Icon name="file"/><h3>{title}</h3><p>{description}</p></div>}

export default function GuruReports(){
 const {assignments,user}=useAuth()
 const assignmentKey=JSON.stringify([user?.id,assignments||[]])
 const [context,setContext]=useState(null),[contextError,setContextError]=useState(''),[contextLoading,setContextLoading]=useState(true)
 const [scope,setScope]=useState('teaching'),[type,setType]=useState('grades'),[filters,setFilters]=useState(()=>blankFilters('teaching'))
 const [preview,setPreview]=useState(null),[busy,setBusy]=useState(''),[confirm,setConfirm]=useState(null),[notice,setNotice]=useState(null)
 const [prepared,setPrepared]=useState(null),preparedRef=useRef(null)
 function clearPrepared(){if(preparedRef.current)URL.revokeObjectURL(preparedRef.current.url);preparedRef.current=null;setPrepared(null)}
 const generation=useRef(0),contextRef=useRef(null),mounted=useRef(false),refreshRef=useRef(null)
 const closeNotice=useCallback(()=>setNotice(null),[])
 function invalidate(){generation.current++;clearPrepared();setPreview(null);setBusy('');setConfirm(null)}
 useEffect(()=>{mounted.current=true;return()=>{mounted.current=false;generation.current++;if(preparedRef.current)URL.revokeObjectURL(preparedRef.current.url);preparedRef.current=null}},[])
 useEffect(()=>{
   let alive=true,pending=false
   async function load(){
     if(!alive||pending)return
     pending=true
     if(!contextRef.current)setContextLoading(true)
     try{
       const next=validateContext((await reportApi.context()).data)
       if(!alive)return
       if(JSON.stringify(next)!==JSON.stringify(contextRef.current)){
         contextRef.current=next;invalidate();setContext(next);setScope('teaching');setType('grades');setFilters(blankFilters('teaching',next))
       }
       setContextError('')
     }catch(error){if(alive){contextRef.current=null;invalidate();setContext(null);setContextError(error.message||'Konteks laporan gagal dimuat.')}}
     finally{pending=false;if(alive)setContextLoading(false)}
   }
   // Assignment change from AuthContext invalidates prior preview immediately.
   contextRef.current=null;invalidate();setContext(null);setContextLoading(true);refreshRef.current=load;load()
   const visible=()=>{if(!document.hidden)load()}
   window.addEventListener('focus',visible);document.addEventListener('visibilitychange',visible);const timer=setInterval(visible,30000)
   return()=>{alive=false;window.removeEventListener('focus',visible);document.removeEventListener('visibilitychange',visible);clearInterval(timer);refreshRef.current=null;generation.current++}
 },[assignmentKey])
 function switchScope(value){invalidate();setScope(value);setType('grades');setFilters(blankFilters(value,context))}
 function selectType(value){invalidate();setType(value);setFilters(blankFilters(scope,context))}
 function change(key,value){invalidate();setFilters(old=>({...old,[key]:value,...(key==='class_id'?{subject_id:'',schedule_id:''}:key==='subject_id'?{schedule_id:''}:{})}))}
 function notifyError(error){setNotice({type:'error',title:'Laporan tidak tersedia',message:error.status===409?'Data atau konteks laporan berubah. Tampilkan pratinjau kembali.':error.message||'Terjadi kesalahan. Coba kembali.'})}
 async function loadPreview(page=1){
   if(busy)return
   let params
   try{params=reportParams(context,scope,type,filters)}catch(error){notifyError(error);return}
   const previousHash=page===1?null:preview?.preview_hash
   const id=++generation.current;setBusy('preview');setConfirm(null)
   clearPrepared();if(!previousHash)setPreview(null)
   try{
     const data=validatePreview((await reportApi.preview(type,{...params,page})).data,page)
     if(!mounted.current||id!==generation.current)return
     if(previousHash&&previousHash!==data.preview_hash){invalidate();setNotice({type:'warning',title:'Data laporan berubah',message:'Tampilkan pratinjau kembali untuk melihat data terbaru.'});return}
     setPreview(data)
   }catch(error){if(mounted.current&&id===generation.current){setPreview(null);notifyError(error);if([401,403,409].includes(error.status))refreshRef.current?.()}}
   finally{if(mounted.current&&id===generation.current)setBusy('')}
 }
 async function download(){
   if(!confirm||!preview||busy)return
   clearPrepared();const format=confirm, hash=preview.preview_hash
   let params;try{params=reportParams(context,scope,type,filters)}catch(error){invalidate();notifyError(error);return}
   const id=++generation.current;setBusy('download')
   try{
     const blob=await reportApi.downloadReport(type,{...params,format,preview_hash:hash})
     if(!mounted.current||id!==generation.current)return
     clearPrepared();const next=makePreparedDownload(blob,`laporan-${type}-${scope}.${format==='excel'?'xlsx':'pdf'}`,format)
     preparedRef.current=next;setPrepared(next)
     setConfirm(null);setNotice({type:'success',title:'File siap diunduh',message:'Gunakan tautan Unduh file yang tersedia.'})
   }catch(error){if(mounted.current&&id===generation.current){invalidate();notifyError(error);if([401,403,409].includes(error.status))refreshRef.current?.()}}
   finally{if(mounted.current&&id===generation.current)setBusy('')}
 }
 const available=context?.year&&context.homeroom.classes.length>0
 const options=filterOptions(context,scope,filters), reports=TYPES.filter(t=>permittedTypes(scope).includes(t[0]))
 const disabled=!context?.year||Boolean(busy)||contextLoading||Boolean(contextError)
 const cols=columns(type), exportDisabled=disabled||!preview||preview.total===0
 const label=(scope==='homeroom'?'Kelas binaan':'Kelas'), selectedClass=options.classes.find(c=>String(c.id)===filters.class_id)
 return <main className="guru-reports">
   <div className="intro"><div><div className="eyebrow">— PELAPORAN AKADEMIK</div><h1>Laporan akademik<span>.</span></h1><p>Siapkan dokumen pembelajaran dan rekap akademik sesuai penugasan Anda.</p></div><div className="period"><Icon name="calendar"/><div><div className="eyebrow">Periode akademik aktif</div><strong>{context?.year?.label||(contextLoading?'Memuat…':'Belum tersedia')}</strong><small>Ditentukan oleh sistem</small></div></div></div>
   {contextLoading?<section className="panel"><Empty title="Memuat penugasan" description="Memeriksa periode dan hak akses laporan Anda."/></section>:contextError?<section className="panel" role="alert"><h2>Konteks laporan tidak tersedia</h2><p>{contextError}</p><button onClick={()=>refreshRef.current?.()} type="button">Muat ulang</button></section>:!context?.year?<section className="panel"><Empty title="Belum ada periode akademik aktif" description="Hubungi Tata Usaha sebelum membuat laporan."/><button type="button" onClick={()=>refreshRef.current?.()}>Muat ulang</button></section>:<>
   <div className="scopebar"><div className="tabs"><button type="button" className={scope==='teaching'?'active':''} aria-pressed={scope==='teaching'} disabled={Boolean(busy)} onClick={()=>switchScope('teaching')}><Icon name="book"/>Laporan mengajar</button>{available&&<button type="button" className={scope==='homeroom'?'active':''} aria-pressed={scope==='homeroom'} disabled={Boolean(busy)} onClick={()=>switchScope('homeroom')}><Icon name="users"/>Laporan kelas binaan</button>}</div><div className="scopehint"><Icon name="shield"/>{scope==='teaching'?'Hanya penugasan mengajar Anda':`${selectedClass?.label||'Kelas binaan aktif'} · Seluruh mapel binaan`}</div></div>
   <section className="panel"><div className="sectionhead"><Step number="01" title="Jenis laporan" description="Pilih dokumen yang ingin disiapkan."/><span className="small muted">{reports.length} laporan tersedia</span></div><div className="types" style={{'--count':reports.length}}>{reports.map(([id,icon,name,desc])=><button type="button" key={id} className={`type ${type===id?'active':''}`} aria-pressed={type===id} disabled={Boolean(busy)} onClick={()=>selectType(id)}><span className="iconrow"><Icon name={icon}/><span className="radio"/></span><strong>{scope==='homeroom'?(id==='students'?'Anggota kelas':id==='schedules'?'Jadwal kelas':name):name}</strong><small>{desc}</small></button>)}</div></section>
   <section className="panel"><div className="sectionhead"><Step number="02" title="Filter laporan" description="Sesuaikan cakupan data sebelum menampilkan laporan."/><span className="tag"><span className="dot"/> Akses terbatas</span></div><form onSubmit={e=>{e.preventDefault();loadPreview()}}><div className="filters">
     <div className="field"><span>Periode akademik</span><Locked value={context.year.label}/></div>
     {scope==='homeroom'&&options.classes.length===1?<div className="field"><span>{label}</span><Locked value={options.classes[0].label}/></div>:<SelectField label={label} value={filters.class_id} options={options.classes} all={scope==='homeroom'?'Pilih kelas binaan':'Semua kelas yang diajar'} onChange={v=>change('class_id',v)} disabled={disabled}/>}
     {type!=='semester-recap'&&<><SelectField label="Mata pelajaran" value={filters.subject_id} options={options.subjects} all="Semua mata pelajaran" onChange={v=>change('subject_id',v)} disabled={disabled}/><SelectField label="Jadwal spesifik (opsional)" value={filters.schedule_id} options={options.schedules.map(s=>({...s,label:`${s.day} · ${s.start}–${s.end} · ${s.subject} · ${s.class}`}))} all="Semua jadwal dalam cakupan" onChange={v=>change('schedule_id',v)} disabled={disabled}/></>}
     {type==='students'&&<Field label="Cari siswa (opsional)"><input value={filters.search} maxLength={100} placeholder="Nama, NIS, atau NISN" disabled={disabled} onChange={e=>change('search',e.target.value)}/></Field>}
     {type==='attendance'&&<><Field label="Tanggal mulai"><input type="date" value={filters.date_from} disabled={disabled} onChange={e=>change('date_from',e.target.value)}/></Field><Field label="Tanggal selesai"><input type="date" min={filters.date_from||undefined} value={filters.date_to} disabled={disabled} onChange={e=>change('date_to',e.target.value)}/></Field></>}
   </div><div className="filter-actions"><span className="small muted">{options.classes.length?'Filter hanya memuat data sesuai konteks akses.':'Belum ada penugasan pada periode aktif.'}</span><div className="actions"><button type="button" className="textbutton" disabled={disabled} onClick={()=>{invalidate();setFilters(blankFilters(scope,context))}}>Reset filter</button><button type="submit" className="primary" disabled={disabled}><Icon name="eye"/>{busy==='preview'?'Memuat…':'Tampilkan pratinjau'}</button></div></div></form></section>
   <section className="panel preview"><div className="sectionhead"><Step number="03" title="Pratinjau laporan" description="Periksa isi dokumen sebelum mengunduh."/><div className="actions"><button type="button" className="exportpdf" disabled={exportDisabled} onClick={()=>setConfirm('pdf')}><Icon name="file"/>Unduh PDF</button><button type="button" className="exportexcel" disabled={exportDisabled} onClick={()=>setConfirm('excel')}><Icon name="excel"/>Unduh Excel</button></div></div>
   {preview?<><div className="previewcontext"><div><strong>{preview.metadata.title}</strong><p>{preview.metadata.scope} · {preview.metadata.period}</p><p>{preview.metadata.filters}</p></div><div className="metrics"><div className="metric"><small>Jumlah baris</small><b>{preview.total}</b></div></div></div>{preview.total===0?<Empty title="Tidak ada data sesuai filter" description="Ubah filter atau tunggu data akademik dicatat sebelum mengunduh."/>:<><div className="tablewrap" tabIndex={0} role="region" aria-label="Tabel pratinjau laporan"><table><thead><tr>{cols.map(([name])=><th key={name} scope="col">{name}</th>)}</tr></thead><tbody>{preview.rows.map((r,index)=><tr key={`${preview.page}-${index}`}>{cols.map(([name,render])=><td key={name} data-label={name}><span className="gr-mobile-label" aria-hidden="true">{name}</span><div>{render(r)}</div></td>)}</tr>)}</tbody></table></div><div className="previewfoot"><span className="small muted">{(preview.page-1)*preview.per_page+1}–{Math.min(preview.page*preview.per_page,preview.total)} dari {preview.total} baris</span><div className="pager"><button type="button" disabled={Boolean(busy)||preview.page<=1} onClick={()=>loadPreview(preview.page-1)} aria-label="Halaman sebelumnya">‹</button><span className="small">{preview.page} / {preview.last_page}</span><button type="button" disabled={Boolean(busy)||preview.page>=preview.last_page} onClick={()=>loadPreview(preview.page+1)} aria-label="Halaman selanjutnya">›</button></div></div></>}<div className="previewfoot"><span className="small muted">{preview.metadata.note}</span></div></>:<Empty title={busy==='preview'?'Memuat pratinjau…':'Pratinjau belum ditampilkan'} description="Tentukan jenis laporan dan filter, kemudian tampilkan pratinjau."/>}</section><div className="notice"><Icon name="info"/><span>Data diambil dari server sesuai penugasan. Ekspor memeriksa ulang hak akses dan perubahan data. Dokumen rekap bukan rapor resmi.</span></div></>}
   {prepared&&<section className="panel gr-prepared" role="status"><strong>Dokumen siap diunduh</strong><p>{prepared.filename}</p><div className="actions"><a href={prepared.url} download={prepared.filename}>Unduh file {prepared.format==='excel'?'Excel':'PDF'}</a>{prepared.format==='pdf'&&<a href={prepared.url} target="_blank" rel="noopener noreferrer">Buka PDF</a>}<button type="button" onClick={clearPrepared}>Tutup</button></div></section>}
   {confirm&&preview&&<WorkspaceModal compact eyebrow="KONFIRMASI EKSPOR" title={`Unduh laporan ${confirm==='pdf'?'PDF':'Excel'}?`} description="Pastikan konteks dan filter laporan sudah benar." busy={busy==='download'} onClose={()=>setConfirm(null)} footer={<><button type="button" disabled={Boolean(busy)} onClick={()=>setConfirm(null)}>Batal</button><button type="button" className="primary" disabled={Boolean(busy)} onClick={download}>{busy==='download'?'Memproses…':'Unduh laporan'}</button></>}><div className="gr-confirm"><strong>{preview.metadata.title}</strong><p>{preview.metadata.scope} · {preview.metadata.period}</p><p>{preview.metadata.filters}</p><p>{preview.total} baris · seluruh hasil filter, bukan hanya halaman ini</p></div></WorkspaceModal>}
   <ToastNotification notice={notice} onClose={closeNotice}/>
 </main>
}
