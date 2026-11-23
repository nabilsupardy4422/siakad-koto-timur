import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../../context/useAuth'
import SearchableSelect from '../../../components/ui/SearchableSelect'
import WorkspaceModal from '../../../components/ui/WorkspaceModal'
import ToastNotification from '../../../components/ui/ToastNotification'
import { academicYearApi } from '../../../services/academicYearApi'
import { homeroomApi } from '../../../services/homeroomApi'
import { classMemberApi } from '../../../services/classMemberApi'
import { teacherId, sameId } from '../../../utils/teachingScope'
import { loadHomeroomContext } from '../../homeroom/homeroomScope'
import { loadClassMembers } from './classMemberScope'
import './HomeroomClassMembers.css'

const statuses = {active:'Aktif',history:'Berakhir',scheduled:'Terjadwal'}
const tabs = [['active','Anggota aktif'],['history','Riwayat'],['scheduled','Terjadwal']]
const period = y => `${y.tahun_mulai}/${y.tahun_selesai} · ${y.semester==='GENAP'?'Genap':'Ganjil'}`
const date = d => d ? new Intl.DateTimeFormat('id-ID',{day:'numeric',month:'short',year:'numeric',timeZone:'UTC'}).format(new Date(d+'T00:00:00Z')) : '—'
const initials = n => n.trim().split(/\s+/).slice(0,2).map(x=>x[0]?.toUpperCase()).join('')
const paths = {users:'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M19 8a3 3 0 0 1 0 6m3 7v-2a4 4 0 0 0-3-4 M9 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8',reload:'M20 7v5h-5 M20 12a8 8 0 1 0-2 6',search:'M10 3a7 7 0 1 0 0 14 7 7 0 0 0 0-14 M15 15l6 6',shield:'M12 2l8 4v6c0 5-8 10-8 10s-8-5-8-10V6z M8 12l3 3 5-6',calendar:'M3 5h18v16H3z M7 3v4m10-4v4M3 10h18',info:'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20 M12 11v6m0-10v1'}
function Icon({name}) {return <svg className="hcm-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d={paths[name]}/></svg>}
function Status({status}) {return <span className={`hcm-status ${status}`}><i/>{statuses[status]}</span>}
function State({title,description,retry,error}){return <div className={`hcm-state${error?' is-error':''}`} role={error?'alert':'status'}><Icon name="info"/><div><strong>{title}</strong>{description&&<p>{description}</p>}{retry&&<button type="button" className="hcm-btn" onClick={retry}>Coba muat ulang</button>}</div></div>}

export default function HomeroomClassMembers(){
  const {user,assignments=[]}=useAuth()
  return <Workspace key={`${user?.id}:${teacherId(user)}:${JSON.stringify(assignments)}`} user={user} guru={teacherId(user)}/>
}
function Workspace({user,guru}){
  const [revision,setRevision]=useState(0), [context,setContext]=useState(null), [choice,setChoice]=useState(''), [result,setResult]=useState(null), [notice,setNotice]=useState(null)
  const dismiss=useCallback(()=>setNotice(null),[])
  const current=context?.revision===revision?context:null
  const year=current?.data?.year, assignments=current?.data?.assignments||[]
  const assignment=assignments.find(a=>sameId(a.id,choice))||assignments[0]
  const key=assignment?`${revision}:${year.id}:${assignment.id}`:''
  const view=result?.key===key?result:null
  const retry=()=>{dismiss();setRevision(n=>n+1)}
  useEffect(()=>{let live=true;loadHomeroomContext({academicYearApi,homeroomApi},guru).then(data=>{if(live)setContext({revision,data})}).catch(e=>{if(live)setContext({revision,error:e.message||'Penugasan gagal dimuat.'})});return()=>{live=false}},[guru,revision])
  useEffect(()=>{
    if(!key)return
    let live=true
    loadClassMembers(classMemberApi,assignment,year,guru).then(data=>{if(live){setResult({key,data});if(revision>0)setNotice({type:'success',title:'Data berhasil diperbarui',message:'Daftar anggota kelas telah dimuat.'})}}).catch(e=>{if(live)setResult({key,error:e.message||'Anggota kelas gagal dimuat.'})})
    return()=>{live=false}
  },[key,assignment,year,guru,revision])
  const busy=!current||Boolean(assignment&&!view)
  return <section className="hcm-workspace"><header className="hcm-head"><div><div className="hcm-eyebrow">— WALI KELAS / KEANGGOTAAN</div><h1>Anggota Kelas<span>.</span></h1><p>Pantau identitas dan riwayat keanggotaan siswa pada kelas binaan Anda.</p></div><button className="hcm-btn" type="button" disabled={busy} onClick={retry}><Icon name="reload"/> Muat ulang</button></header>
    {!current?<div className="hcm-panel"><State title="Memeriksa penugasan Wali Kelas..."/></div>:current.error?<div className="hcm-panel"><State title="Konteks kelas gagal dimuat" description={current.error} retry={retry} error/></div>:!assignment?<div className="hcm-panel"><State title={year?'Belum ada penugasan Wali Kelas aktif':'Belum ada periode akademik aktif'} description="Hubungi Tata Usaha untuk penugasan pada periode yang sedang berlaku."/><Link className="hcm-btn" to="/app/dashboard">Kembali ke dashboard</Link></div>:<>
      {assignments.length>1&&<div className="hcm-panel"><SearchableSelect label="Kelas binaan" value={String(assignment.id)} options={assignments.map(a=>({value:String(a.id),label:`${a.kelas.nama} · Tingkat ${a.kelas.tingkat}`,description:period(year)}))} onChange={v=>{dismiss();setChoice(v);setResult(null)}}/></div>}
      <div className="hcm-banner"><div className="hcm-identity"><div className="hcm-classicon"><Icon name="users"/></div><div><div className="hcm-eyebrow">KONTEKS KEANGGOTAAN</div><h2>{assignment.kelas.nama} <span>/ Tingkat {assignment.kelas.tingkat??'—'}</span></h2><p>{assignment.guru?.nama_lengkap||user?.guru?.nama_lengkap||user?.name||'Guru'} · Wali Kelas</p></div></div><div className="hcm-metadata"><div><small>Periode akademik</small><b>{period(year)}</b><span className="hcm-banner-active">● Penugasan aktif</span></div><div><small>Anggota aktif</small><strong>{view?.data?view.data.rows.filter(r=>r.status==='active').length:'—'}</strong><small>Dalam kelas binaan</small></div></div></div>
      {view?.error?<div className="hcm-panel"><State title="Daftar anggota gagal dimuat" description={view.error} retry={retry} error/></div>:!view?.data?<div className="hcm-panel"><State title="Memuat daftar anggota kelas..."/></div>:<Directory key={key} data={view.data} assignment={assignment} year={year}/>}
    </>}
    <ToastNotification notice={notice} onClose={dismiss}/>
  </section>
}
function Directory({data,assignment,year}){
  const [tab,setTab]=useState('active'),[search,setSearch]=useState(''),[page,setPage]=useState(1),[detail,setDetail]=useState(null)
  const counts=Object.fromEntries(tabs.map(([k])=>[k,data.rows.filter(r=>r.status===k).length]))
  const filtered=data.rows.filter(r=>r.status===tab&&`${r.name} ${r.nis} ${r.nisn}`.toLocaleLowerCase('id').includes(search.trim().toLocaleLowerCase('id')))
  const size=8,pages=Math.max(1,Math.ceil(filtered.length/size)),current=Math.min(page,pages),start=(current-1)*size
  return <><div className="hcm-grid"><article className="hcm-panel hcm-directory"><div className="hcm-panelhead"><div><h2>Daftar anggota kelas</h2><p>Direktori siswa kelas binaan pada periode akademik aktif.</p></div><span className="hcm-read"><Icon name="shield"/> AKSES BACA</span></div>
    <div className="hcm-tabs" role="group" aria-label="Status keanggotaan">{tabs.map(([k,label])=><button type="button" key={k} aria-pressed={tab===k} className={tab===k?'selected':''} onClick={()=>{setTab(k);setPage(1);setDetail(null)}}>{label} <span>{counts[k]}</span></button>)}</div>
    <div className="hcm-searchbar"><label className="hcm-search"><Icon name="search"/><input type="search" aria-label="Cari siswa" placeholder="Cari nama, NIS, atau NISN..." value={search} onChange={e=>{setSearch(e.target.value);setPage(1)}}/></label><small>{filtered.length} dari {counts[tab]} keanggotaan</small></div>
    {filtered.length?<div className="hcm-tablewrap"><table><thead><tr><th>Identitas siswa</th><th>Tanggal masuk</th><th>Keanggotaan</th><th>Detail</th></tr></thead><tbody>{filtered.slice(start,start+size).map(r=><tr key={r.id}><td><div className="hcm-person"><span className="hcm-avatar">{initials(r.name)}</span><div><b>{r.name}</b><small>{r.nis?`NIS ${r.nis}`:r.nisn?`NISN ${r.nisn}`:'NIS/NISN belum tersedia'}</small></div></div></td><td>{date(r.start)}</td><td><Status status={r.status}/></td><td><button className="hcm-btn hcm-detailbtn" type="button" onClick={()=>setDetail(r)}>Lihat detail</button></td></tr>)}</tbody></table></div>:<State title={search?'Tidak ada hasil yang cocok':tab==='active'?'Belum ada anggota aktif':tab==='history'?'Belum ada riwayat berakhir':'Belum ada keanggotaan terjadwal'} description={search?'Coba ubah kata pencarian.':'Data akan tampil sesuai administrasi Tata Usaha.'}/>}
    <div className="hcm-tablefoot"><small>{filtered.length?`Menampilkan ${start+1}–${Math.min(start+size,filtered.length)} dari ${filtered.length} keanggotaan`:'0 keanggotaan ditampilkan'}</small><div><button type="button" className="hcm-btn" aria-label="Halaman sebelumnya" disabled={current===1} onClick={()=>setPage(current-1)}>‹</button><small>{current} / {pages}</small><button type="button" className="hcm-btn" aria-label="Halaman berikutnya" disabled={current===pages} onClick={()=>setPage(current+1)}>›</button></div></div>
  </article><aside className="hcm-side"><article className="hcm-panel"><div className="hcm-eyebrow">RINGKASAN KEANGGOTAAN</div><h2>Komposisi kelas</h2><dl>{[['Siswa aktif','active'],['Keanggotaan berakhir','history'],['Belum dimulai','scheduled']].map(([label,k])=><div key={k}><dt>{label}</dt><dd>{counts[k]}</dd></div>)}</dl><div className="hcm-asof"><Icon name="calendar"/><div><small>Tanggal acuan server</small><b>{date(data.asOf)}</b></div></div></article><article className="hcm-panel"><div className="hcm-eyebrow">KEWENANGAN WALI KELAS</div><h2>Informasi keanggotaan</h2>{[['users','Pantau siswa kelas binaan','Lihat identitas, status, dan tanggal penempatan siswa.'],['shield','Penempatan dikelola TU','Hubungi Tata Usaha untuk penambahan, perpindahan, atau pengakhiran keanggotaan.'],['info','Riwayat tetap tersimpan','Catatan akademik siswa tidak dihapus ketika keanggotaannya berakhir.']].map(([icon,title,desc])=><div className="hcm-info" key={title}><Icon name={icon}/><div><b>{title}</b><p>{desc}</p></div></div>)}</article></aside></div><div className="hcm-asof hcm-bottom"><Icon name="info"/><p><b>Informasi:</b> Kelas dan periode mengikuti penugasan resmi. Riwayat di sini hanya mencakup kelas binaan pada periode aktif. Tanggal selesai keanggotaan berlaku inklusif.</p></div>
    {detail&&<WorkspaceModal compact eyebrow="WALI KELAS / AKSES BACA" title="Detail anggota kelas" description="Informasi keanggotaan sesuai data akademik." onClose={()=>setDetail(null)} footer={<button type="button" className="hcm-btn hcm-primary" onClick={()=>setDetail(null)}>Selesai</button>}><div className="hcm-detail"><div className="hcm-detailidentity"><span className="hcm-avatar">{initials(detail.name)}</span><div><h3>{detail.name}</h3><Status status={detail.status}/></div></div><dl>{[['NIS',detail.nis||'—'],['NISN',detail.nisn||'—'],['Kelas',`${assignment.kelas.nama} · Tingkat ${assignment.kelas.tingkat??'—'}`],['Periode',period(year)],['Tanggal mulai',date(detail.start)],['Tanggal selesai',detail.end?date(detail.end):'Belum ditentukan']].map(([k,v])=><div key={k}><dt>{k}</dt><dd>{v}</dd></div>)}</dl><div className="hcm-asof"><Icon name="shield"/><p>Perubahan keanggotaan hanya dapat dilakukan oleh Tata Usaha.</p></div></div></WorkspaceModal>}
  </>
}
