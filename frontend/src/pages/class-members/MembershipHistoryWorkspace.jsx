import {useEffect,useState} from 'react'
import Card from '../../components/ui/Card'
import Button from '../../components/ui/Button'
import Table from '../../components/ui/Table'
import {classMemberApi} from '../../services/classMemberApi'
import {StudentPicker,Notice} from './MembershipWorkspaceUI.jsx'
import {actionTitle,classTitle,dateLabel,periodTitle} from './membershipWorkspaceLogic.js'
export default function MembershipHistoryWorkspace({initialStudent}){
 const [selected,setSelected]=useState(initialStudent?[initialStudent]:[]),[data,setData]=useState(null),[error,setError]=useState(''),[busy,setBusy]=useState(false),[showPicker,setShowPicker]=useState(!initialStudent)
 const id=selected[0]?.id
 useEffect(()=>{let live=true;setData(null);setError('');if(!id)return;setBusy(true);classMemberApi.history(id).then(r=>{if(live)setData(r.data)}).catch(e=>{if(live)setError(e.message||'Riwayat tidak dapat dimuat.')}).finally(()=>{if(live)setBusy(false)});return()=>{live=false}},[id])
 return <Card className="mw-panel"><div className="mw-heading"><div><small>RIWAYAT SISWA</small><h2>Perjalanan akademik</h2><p>Seluruh periode dan keputusan yang tercatat. Tidak mengubah data lama.</p></div><Button variant="outline" onClick={()=>setShowPicker(v=>!v)}>{showPicker?'Tutup pencarian':'Cari siswa lain'}</Button></div>{showPicker&&<StudentPicker selected={selected} onChange={s=>{setSelected(s);if(s.length)setShowPicker(false)}} limit={1}/>} {busy&&id&&<p>Memuat riwayat...</p>}{error&&<Notice error>{error}</Notice>}{data&&<><div className="mw-destination"><div><small>IDENTITAS SISWA</small><strong>{data.student.nama_lengkap}</strong><span>NISN {data.student.nisn} · {data.academic_status?actionTitle[data.academic_status]:'Belum ada keputusan terminal tercatat'}</span></div></div><Table columns={[{key:'period',label:'Periode'},{key:'class',label:'Rombel'},{key:'start',label:'Mulai'},{key:'end',label:'Selesai'}]} data={data.memberships} scrollLabel="Riwayat keanggotaan" renderCell={(r,c)=>c.key==='period'?periodTitle(r.period):c.key==='class'?classTitle(r):c.key==='start'?dateLabel(r.tanggal_mulai):r.tanggal_selesai?dateLabel(r.tanggal_selesai):'Terbuka'}/><h3>Keputusan transisi tercatat</h3>{data.events.length?data.events.map(e=><div key={e.id} className="mw-event"><strong>{actionTitle[e.action]}</strong><small>Efektif {dateLabel(e.effective_on)}</small></div>):<Notice>Belum ada keputusan transisi yang tercatat. Riwayat lama tetap ditampilkan di atas.</Notice>}</>}</Card>
}
