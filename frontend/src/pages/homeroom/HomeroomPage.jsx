import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../context/useAuth'
import SearchableSelect from '../../components/ui/SearchableSelect'
import ToastNotification from '../../components/ui/ToastNotification'
import { academicYearApi } from '../../services/academicYearApi'
import { homeroomApi } from '../../services/homeroomApi'
import { classMemberApi } from '../../services/classMemberApi'
import { attendanceApi } from '../../services/attendanceApi'
import { gradeApi } from '../../services/gradeApi'
import { teacherId, sameId } from '../../utils/teachingScope'
import { loadHomeroomContext, loadHomeroomData } from './homeroomScope'
import './HomeroomPage.css'

const icons = {
  school:'M3 21V9l9-6 9 6v12H3z M9 21v-6h6v6 M7 11h2m6 0h2',
  users:'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M19 8a3 3 0 0 1 0 6m3 7v-2a4 4 0 0 0-3-4 M9 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8',
  check:'M9 5H5v16h14V5h-4 M9 3h6v4H9z M8 14l3 3 5-6',
  chart:'M3 3v18h18 M7 15l4-4 4 2 6-7',
  grade:'M2 9l10-5 10 5-10 5-10-5z M6 11v6c4 4 8 4 12 0v-6 M22 9v7',
  refresh:'M20 7v5h-5 M20 12a8 8 0 1 0-2 6',
  calendar:'M3 5h18v16H3z M7 3v4m10-4v4M3 10h18',
  search:'M10 3a7 7 0 1 0 0 14 7 7 0 0 0 0-14 M15 15l6 6',
  arrow:'M4 12h16m-6-6 6 6-6 6',
  info:'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20 M12 11v6m0-10v1',
}
function Icon({name}) { return <svg className="hr-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d={icons[name]}/></svg> }
const num = v => v == null ? '—' : Number(v).toLocaleString('id-ID',{maximumFractionDigits:2})
const percent = v => v == null ? '—' : `${Number(v).toLocaleString('id-ID',{maximumFractionDigits:1})}%`
const period = y => y ? `${y.tahun_mulai}/${y.tahun_selesai} · ${y.semester === 'GENAP'?'Genap':'Ganjil'}`:'Periode belum tersedia'
const colors = ['#16805e','#3974c6','#c28b35','#c64b4b']
const labels = ['Hadir','Izin','Sakit','Alpa']
const keys = ['hadir','izin','sakit','alpa']
const pageSize=8
function PanelState({error,children,onRetry}) {return <div className={`hr-state${error?' hr-error':''}`} role={error?'alert':'status'}><Icon name="info"/><div>{error||children}{onRetry&&<div><button type="button" className="hr-btn" onClick={onRetry}>Coba muat ulang</button></div>}</div></div>}
function Kpi({icon,label,value,note}){return <article className="hr-panel hr-kpi"><div className="hr-kpi-top"><span>{label}</span><span className="hr-iconbox"><Icon name={icon}/></span></div><b>{value}</b><small>{note}</small></article>}
function Attendance({value,error,retry}){
  let start=0
  const segments=keys.map((k,i)=>{const end=start+(value?.total?value[k]/value.total*100:0);const segment=`${colors[i]} ${start}% ${end}%`;start=end;return segment})
  return <article className="hr-panel"><div className="hr-panelhead"><div><h2>Rekap kehadiran</h2><p>Distribusi catatan presensi per pertemuan.</p></div><span className="hr-tag">SEMESTER INI</span></div>
    {error?<PanelState error={error} onRetry={retry}/>:!value?<PanelState>Memuat rekap kehadiran...</PanelState>:<><div className="hr-attendance"><div className="hr-ring" style={{background:value.total?`conic-gradient(${segments.join(',')})`:'#e4ebf2'}} role="img" aria-label={value.total?`${percent(value.percent)} hadir dari catatan presensi`:'Belum ada catatan presensi'}><div><b>{percent(value.percent)}</b><small>{value.total?'Hadir tercatat':'Belum tercatat'}</small></div></div><div className="hr-legend">{keys.map((key,i)=><div key={key}><i style={{background:colors[i]}}/><span>{labels[i]}</span><b>{num(value[key])}</b></div>)}</div></div><div className="hr-note"><Icon name="info"/>{value.total?'Persentase berdasarkan catatan yang tersedia, bukan seluruh pertemuan yang seharusnya berlangsung.':'Belum ada catatan presensi untuk periode ini. Persentase belum dapat dihitung.'}</div></>}
  </article>
}
function Grades({value,error,retry}){return <article className="hr-panel"><div className="hr-panelhead"><div><h2>Rekap penilaian</h2><p>Statistik catatan nilai komponen.</p></div><span className="hr-tag">SEMESTER INI</span></div>{error?<PanelState error={error} onRetry={retry}/>:!value?<PanelState>Memuat rekap penilaian...</PanelState>:<><div className="hr-gradebody"><div className="hr-avg"><div><strong>Rata-rata nilai tercatat</strong><small>{num(value.total)} catatan nilai</small></div><b>{num(value.average)}</b></div><div className="hr-gradeprops"><div><small>Tertinggi</small><b>{num(value.high)}</b></div><div><small>Terendah</small><b>{num(value.low)}</b></div></div></div><div className="hr-note"><Icon name="info"/>Statistik nilai komponen, bukan nilai akhir rapor atau peringkat siswa.</div></>}</article>}
function Students({rows,error,retry}) {
  const [query,setQuery]=useState(''),[page,setPage]=useState(1)
  const filtered=(rows||[]).filter(s=>`${s.name} ${s.nis} ${s.nisn}`.toLocaleLowerCase('id').includes(query.trim().toLocaleLowerCase('id')))
  const pages=Math.max(1,Math.ceil(filtered.length/pageSize)), current=Math.min(page,pages), start=(current-1)*pageSize
  return <article className="hr-panel hr-students"><div className="hr-panelhead"><div><h2>Anggota kelas</h2><p>Ringkasan siswa aktif pada kelas binaan.</p></div><Link className="hr-textlink" to="/app/class-members">Lihat semua anggota <Icon name="arrow"/></Link></div>
    {error?<PanelState error={error} onRetry={retry}/>:!rows?<PanelState>Memuat anggota kelas...</PanelState>:<><div className="hr-searchrow"><label className="hr-search"><Icon name="search"/><input type="search" aria-label="Cari siswa" placeholder="Cari nama, NIS, atau NISN..." value={query} onChange={e=>{setQuery(e.target.value);setPage(1)}}/></label><small>{num(filtered.length)} dari {num(rows.length)} siswa</small></div>
    {!filtered.length?<PanelState>{rows.length?'Tidak ada siswa yang cocok dengan pencarian.':'Belum ada anggota kelas aktif.'}</PanelState>:<div className="hr-tablewrap"><table><thead><tr><th>No.</th><th>Nama siswa</th><th>NIS / NISN</th><th>Keanggotaan</th></tr></thead><tbody>{filtered.slice(start,start+pageSize).map((s,i)=><tr key={s.id}><td>{start+i+1}</td><td><div className="hr-studentname"><span className="hr-avatar">{s.name.trim().split(/\s+/).slice(0,2).map(n=>n[0]?.toUpperCase()).join('')}</span><div><b>{s.name}</b><small>{s.nis||s.nisn||'—'}</small></div></div></td><td>{s.nis||s.nisn||'—'}</td><td><span className="hr-membership"><i/> Aktif</span></td></tr>)}</tbody></table></div>}
    <div className="hr-tablefoot"><small>{filtered.length?`Menampilkan ${start+1}–${Math.min(start+pageSize,filtered.length)} dari ${filtered.length} siswa`:'0 siswa ditampilkan'}</small><div className="hr-pagination"><button className="hr-btn" type="button" aria-label="Halaman sebelumnya" disabled={current===1} onClick={()=>setPage(current-1)}>‹</button><small>{current} / {pages}</small><button className="hr-btn" type="button" aria-label="Halaman berikutnya" disabled={current===pages} onClick={()=>setPage(current+1)}>›</button></div></div></>}
  </article>
}
export default function HomeroomPage(){
  const {user,role,assignments}=useAuth()
  if (String(typeof role==='string'?role:(role?.code||role?.name||role?.nama||user?.role?.code||'')).trim().toUpperCase()!=='GURU') return <section className="hr-workspace"><PanelState error="Akses Wali Kelas tidak tersedia."/></section>
  return <Workspace key={`${user?.id}:${teacherId(user)}:${JSON.stringify(assignments)}`} guru={teacherId(user)} user={user}/>
}
function Workspace({guru,user}){
  const [rev,setRev]=useState(0),[context,setContext]=useState(null),[choice,setChoice]=useState(''),[result,setResult]=useState(null),[notice,setNotice]=useState(null)
  const closeNotice=useCallback(()=>setNotice(null),[])
  const current=context?.rev===rev?context:null
  const year=current?.data?.year, assignments=current?.data?.assignments||[]
  const assignment=assignments.find(a=>sameId(a.id,choice))||assignments[0]||null
  const key=assignment?`${rev}:${year.id}:${assignment.id}`:''
  const retry=()=>{setNotice(null);setRev(v=>v+1)}
  useEffect(()=>{let alive=true;loadHomeroomContext({academicYearApi,homeroomApi},guru).then(data=>{if(alive)setContext({rev,data,error:''})}).catch(e=>{if(alive)setContext({rev,data:null,error:e.message||'Penugasan gagal dimuat.'})});return()=>{alive=false}},[guru,rev])
  useEffect(()=>{if(!key)return;let alive=true;loadHomeroomData({classMemberApi,attendanceApi,gradeApi},assignment,year,guru).then(data=>{if(!alive)return;setResult({key,data,error:''});if(rev>0)setNotice(Object.values(data).some(v=>v.error)?{type:'warning',title:'Sebagian data gagal dimuat',message:'Periksa bagian terkait.'}:{type:'success',title:'Data kelas diperbarui',message:'Rekap akademik berhasil dimuat.'})}).catch(e=>{if(alive)setResult({key,data:null,error:e.message||'Data kelas gagal dimuat.'})});return()=>{alive=false}},[key,guru,assignment,year,rev])
  const view=result?.key===key?result:null, data=view?.data, attendance=data?.attendance?.data, grades=data?.grades?.data, students=data?.students?.data
  const ready=!!assignment&&!!view?.data&&!view?.error
  return <section className="hr-workspace"><header className="hr-head"><div><div className="hr-eyebrow">— WALI KELAS / MONITORING AKADEMIK</div><h1>Kelas Saya<span>.</span></h1><p>Pantau kehadiran dan perkembangan belajar siswa dalam kelas binaan Anda.</p></div><div className="hr-actions"><button className="hr-btn" type="button" onClick={retry} disabled={!current|| (!!assignment&&!view)}><Icon name="refresh"/> Muat ulang</button>{ready&&<Link className="hr-btn hr-primary" to="/app/class-members"><Icon name="users"/> Lihat anggota</Link>}</div></header>
    {!current?<div className="hr-panel"><PanelState>Memuat penugasan Wali Kelas...</PanelState></div>:current.error?<div className="hr-panel"><PanelState error={current.error} onRetry={retry}/></div>:!assignment?<div className="hr-panel hr-empty"><Icon name="school"/><h2>{year?'Belum ada penugasan aktif':'Belum ada periode akademik aktif'}</h2><p>{year?'Hubungi Tata Usaha jika Anda telah ditugaskan sebagai Wali Kelas.':'Hubungi Tata Usaha untuk mengaktifkan periode akademik.'}</p></div>:<>
      {assignments.length>1&&<div className="hr-panel"><SearchableSelect label="Pilih kelas binaan" value={String(assignment.id)} options={assignments.map(a=>({value:String(a.id),label:`${a.kelas.nama} · Tingkat ${a.kelas.tingkat}`,description:period(year)}))} onChange={v=>{setNotice(null);setResult(null);setChoice(v)}}/></div>}
      {view?.error?<div className="hr-panel"><PanelState error={view.error} onRetry={retry}/></div>:<><section className="hr-context"><div className="hr-classidentity"><div className="hr-classicon"><Icon name="school"/></div><div><div className="hr-eyebrow">KELAS BINAAN ANDA</div><h2>{assignment.kelas.nama} <span>/ Tingkat {assignment.kelas.tingkat??'—'}</span></h2><p>{assignment.guru?.nama_lengkap||user?.guru?.nama_lengkap||user?.name||'Guru'} · Wali Kelas</p></div></div><div className="hr-contextmeta"><div><small>Periode akademik</small><b>{period(year)}</b></div><div><small>Status penugasan</small><b className="hr-active"><i/> Aktif</b></div></div></section>
      <div className="hr-sectionhead"><div><h2>Ringkasan kelas</h2><p>Seluruh mata pelajaran pada kelas binaan.</p></div><div className="hr-period"><Icon name="calendar"/> {period(year)}</div></div>
      <div className="hr-kpis"><Kpi icon="users" label="Siswa aktif" value={num(students?.length)} note={data?.students?.error?'Gagal dimuat':'Anggota kelas saat ini'}/><Kpi icon="check" label="Catatan presensi" value={num(attendance?.total)} note={data?.attendance?.error?'Gagal dimuat':'Seluruh pertemuan tercatat'}/><Kpi icon="chart" label="Kehadiran tercatat" value={percent(attendance?.percent)} note={attendance?.total===0?'Belum ada presensi':'Dari catatan yang tersedia'}/><Kpi icon="grade" label="Catatan nilai" value={num(grades?.total)} note={data?.grades?.error?'Gagal dimuat':'Seluruh komponen tercatat'}/></div>
      <div className="hr-analyses"><Attendance value={attendance} error={data?.attendance?.error} retry={retry}/><Grades value={grades} error={data?.grades?.error} retry={retry}/></div>
      <Students key={key} rows={students} error={data?.students?.error} retry={retry}/>
      <div className="hr-panel hr-report"><div><h2>Laporan akademik kelas</h2><p>Buka ruang laporan untuk ekspor sesuai kewenangan.</p></div><Link className="hr-btn" to="/app/reports">Buka laporan <Icon name="arrow"/></Link></div></>}
    </>}
    <ToastNotification notice={notice} onClose={closeNotice}/>
  </section>
}
