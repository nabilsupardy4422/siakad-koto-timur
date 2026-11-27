import {useMemo,useRef,useState} from 'react'
import Modal from '../../../components/ui/Modal'
import Button from '../../../components/ui/Button'
import SchedulePicker from './SchedulePicker.jsx'
import {scheduleApi} from '../../../services/scheduleApi'

const label=c=>`${c.nama} — Tingkat ${c.tingkat}`
export default function ScheduleContinuationDialog({source,years,classes,rows,onClose,onDone}) {
  const targets=years.filter(y=>y.semester==='GENAP'&&String(y.tahun_mulai)===String(source.tahun_mulai)&&String(y.tahun_selesai)===String(source.tahun_selesai))
  const [target,setTarget]=useState(targets.length===1?String(targets[0].id):'')
  const [mapping,setMapping]=useState({}),[preview,setPreview]=useState(null),[error,setError]=useState(''),[busy,setBusy]=useState(false)
  const pending=useRef(false)
  const origins=useMemo(()=>[...new Map(rows.map(r=>[String(r.kelas_id),r.kelas])).values()].sort((a,b)=>Number(a.tingkat)-Number(b.tingkat)||a.nama.localeCompare(b.nama,'id')),[rows])
  const dest=classes.filter(c=>String(c.tahun_akademik_id)===target)
  const valid=Boolean(target&&origins.length&&rows.length<=1000&&origins.length<=100&&origins.every(c=>dest.some(d=>String(d.id)===mapping[c.id]&&String(d.tingkat)===String(c.tingkat)))&&new Set(Object.values(mapping)).size===origins.length)
  function clear(){setPreview(null);setError('')}
  async function submit(commit=false){
    if(!valid||pending.current||(commit&&!preview?.token))return
    pending.current=true;setBusy(true);setError('')
    try{
      const result=await scheduleApi.copySemester({mode:commit?'commit':'preview',source_period_id:Number(source.id),target_period_id:Number(target),mappings:origins.map(c=>({source_class_id:Number(c.id),target_class_id:Number(mapping[c.id])})),...(commit?{token:preview.token}:{})})
      if(commit){if(result?.data?.count!==preview.count)throw new Error('Respons penyalinan tidak lengkap. Muat ulang jadwal tujuan sebelum mencoba lagi.');onDone(result.data.count)}
      else {const p=result?.data;if(!p?.token||!Array.isArray(p.rows)||p.count!==rows.length||p.rows.length!==p.count)throw new Error('Data asal berubah atau preview tidak lengkap. Muat ulang jadwal.');setPreview(p)}
    }catch(e){setPreview(null);const detail=e?.validationErrors||e?.payload?.errors||e?.payload?.data?.errors;setError([e?.payload?.message||e.message||'Penyalinan gagal.',...Object.entries(detail||{}).flatMap(([k,v])=>[].concat(v).map(m=>`${k}: ${m}`))].join('\n'))}
    finally{pending.current=false;setBusy(false)}
  }
  return <Modal open title="Salin Jadwal Semester" description="Salin struktur jadwal Ganjil ke Genap. Periode tidak diaktifkan otomatis." size="large" onClose={()=>{if(!pending.current)onClose()}} footer={<><Button variant="secondary" disabled={busy} onClick={onClose}>Batal</Button><Button disabled={!valid||busy} loading={busy} onClick={()=>submit(Boolean(preview))}>{preview?'Konfirmasi Salin Jadwal':'Preview Penyalinan'}</Button></>}>
    <div className="sch-copy-form">
      <div className="sch-excel-context"><strong>Asal: {source.tahun_mulai}/{source.tahun_selesai} · Ganjil</strong><br/>{rows.length} jadwal pada {origins.length} rombel. Filter hari/guru tidak membatasi penyalinan.</div>
      <SchedulePicker label="Semester tujuan" name="target" value={target} placeholder="Pilih Genap" allowEmpty={false} disabled={busy} options={targets.map(y=>({value:String(y.id),label:`${y.tahun_mulai}/${y.tahun_selesai} · Genap`}))} onChange={e=>{setTarget(e.target.value);setMapping({});clear()}}/>
      {!targets.length&&<p role="alert">Semester Genap pada tahun yang sama belum tersedia. Buat melalui Tahun Akademik terlebih dahulu.</p>}
      <p className="sch-copy-note">Petakan semua rombel asal ke rombel tujuan dengan tingkat yang sama. Tidak ada penyalinan nilai, presensi, bahan ajar atau tugas.</p>
      <div className="sch-copy-mapping">{origins.map(c=><div className="sch-copy-row" key={c.id}><div><small>ROMBEL ASAL</small><strong>{label(c)}</strong></div><SchedulePicker label="Rombel tujuan" name={`map-${c.id}`} value={mapping[c.id]||''} placeholder="Pilih rombel" disabled={busy||!target} options={dest.filter(d=>String(d.tingkat)===String(c.tingkat)&&(!Object.values(mapping).includes(String(d.id))||mapping[c.id]===String(d.id))).map(d=>({value:String(d.id),label:label(d)}))} onChange={e=>{setMapping(m=>({...m,[c.id]:e.target.value}));clear()}}/></div>)}</div>
      {error&&<div role="alert" className="admin-schedule__form-error" style={{whiteSpace:'pre-wrap',overflowWrap:'anywhere'}}>{error}</div>}
      {preview&&<section className="admin-schedule__preview"><h3>{preview.count} jadwal siap disalin</h3><p>Belum disimpan. Konfirmasi dalam 5 menit.</p><div className="admin-schedule__preview-scroll" role="region" aria-label="Preview salin semester" tabIndex={0}><table><thead><tr>{['Asal','Tujuan','Guru','Mapel','Hari','Jam','Status'].map(h=><th key={h}>{h}</th>)}</tr></thead><tbody>{preview.rows.map(r=><tr key={r.source_id}><td>{r.source_class}</td><td>{r.target_class}</td><td>{r.teacher}</td><td>{r.subject}</td><td>{r.data.hari}</td><td>{r.data.jam_mulai}–{r.data.jam_selesai}</td><td>{r.account_state==='pending'?'Menunggu aktivasi':'Siap'}</td></tr>)}</tbody></table></div><Button variant="secondary" disabled={busy} onClick={clear}>Ubah pemetaan</Button></section>}
    </div>
  </Modal>
}
