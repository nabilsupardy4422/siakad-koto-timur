import { useEffect, useRef, useState } from 'react'
import Modal from '../../components/ui/Modal'
import Button from '../../components/ui/Button'
import Select from '../../components/ui/Select'
import Input from '../../components/ui/Input'
import { homeroomApi } from '../../services/homeroomApi'
import { classLabel, yearLabel, workspaceData, continuationTargets, suggestMappings } from './homeroomManagementLogic.js'

export default function HomeroomContinuationDialog({source,years,data,onClose,onSaved}) {
  const targets=continuationTargets(source,years)
  const [target,setTarget]=useState(String(targets[0]?.id||'')),[start,setStart]=useState(''),[dest,setDest]=useState(null),[mapping,setMapping]=useState({}),[preview,setPreview]=useState(null),[error,setError]=useState(''),[loading,setLoading]=useState(false),[busy,setBusy]=useState(false)
  const lock=useRef(false)
  const rows=data.assignments.filter(a=>a.status==='effective')
  useEffect(()=>{
    let alive=true;setDest(null);setMapping({});setPreview(null);setError('');setLoading(Boolean(target))
    if(target)homeroomApi.workspace(target).then(r=>{if(alive){const d=workspaceData(r,target);setDest(d);setMapping(suggestMappings(rows,d.classes));setLoading(false)}}).catch(e=>{if(alive){setError(e.message);setLoading(false)}})
    return()=>{alive=false}
  },[target])
  const destinationReady=dest&&String(dest.period_id)===target
  const selected=rows.filter(a=>mapping[a.record.id]).map(a=>({assignment_id:Number(a.record.id),class_id:Number(mapping[a.record.id])}))
  const payload={source_id:Number(source.id),target_id:Number(target),tanggal_mulai:start,rows:selected}
  async function submit(e){e.preventDefault();if(lock.current||!destinationReady||!start||start<data.as_of||!selected.length)return;lock.current=true;setBusy(true);setError('')
    try{const result=await homeroomApi.continuation({...payload,...(preview?.valid&&preview.token?{token:preview.token}:{})});if(preview?.valid&&preview.token){if(result?.data?.created!==selected.length||String(result.data.target_id)!==target)throw new Error('Respons commit tidak sesuai; muat ulang sebelum mencoba lagi.');onSaved(target)}else{if(!Array.isArray(result?.data?.rows)||result.data.rows.length!==selected.length||result.data.rows.some((r,i)=>String(r.assignment_id)!==String(selected[i].assignment_id)||String(r.class_id)!==String(selected[i].class_id)))throw new Error('Preview tidak lengkap.');setPreview(result.data)}}catch(e){setError(e.message||'Permintaan gagal.');setPreview(null)}finally{lock.current=false;setBusy(false)}}
  return <Modal open size="large" title="Lanjutkan penugasan semester" description="Pilih pemetaan rombel, periksa preview, lalu konfirmasi." onClose={()=>{if(!lock.current)onClose()}} footer={<><Button variant="secondary" disabled={busy} onClick={onClose}>Tutup</Button><Button form="hm-continuation" type="submit" loading={busy} disabled={loading||!destinationReady||!start||start<data.as_of||!selected.length}>{preview?.valid&&preview.token?'Konfirmasi penugasan':'Periksa preview'}</Button></>}>
    <form id="hm-continuation" className="hm-form" onSubmit={submit}>
      <div className="hm-form-context"><span>Periode asal · Ganjil</span><strong>{yearLabel(source)}</strong></div>
      <Select label="Semester tujuan" id="hm-target" value={target} disabled={busy} options={targets.map(y=>({value:String(y.id),label:yearLabel(y)}))} onChange={e=>{setTarget(e.target.value);setDest(null);setMapping({});setPreview(null)}}/>
      <Input label="Tanggal mulai semester tujuan" id="hm-cont-start" type="date" required value={start} disabled={busy} onChange={e=>{setStart(e.target.value);setPreview(null)}}/>
      <p className="hm-note">Rombel dengan nama dan tingkat yang sama disarankan. Konfirmasi setiap pasangan; pilihan kosong berarti dikecualikan. Semester tujuan tidak otomatis aktif.</p>
      {start&&start<data.as_of&&<p className="hm-form-error" role="alert">Tanggal mulai tidak boleh sebelum hari ini WIB.</p>}{loading&&<p role="status">Memuat rombel tujuan…</p>}
      {dest&&rows.map(({record:r,account_state})=><div className="hm-cont-row" key={r.id}><div className="hm-identity"><strong>{r.guru?.nama_lengkap}</strong><span>{classLabel(r.kelas)} · {account_state==='inactive'?'Akun nonaktif':account_state==='pending'?'Menunggu aktivasi':'Penugasan asal'}</span></div><Select id={`hm-map-${r.id}`} label="Rombel tujuan" value={mapping[r.id]||''} placeholder="Tidak dilanjutkan" disabled={busy||account_state==='inactive'||account_state==='unknown'} options={dest.classes.filter(c=>String(c.tingkat)===String(r.kelas?.tingkat)&&c.status==='unassigned'&&!Object.entries(mapping).some(([id,value])=>id!==String(r.id)&&String(value)===String(c.id))).map(c=>({value:String(c.id),label:classLabel(c)}))} onChange={e=>{setMapping(m=>({...m,[r.id]:e.target.value}));setPreview(null)}}/></div>)}
      {dest&&!rows.length&&<p>Tidak ada penugasan asal yang berlaku.</p>}
      {preview&&<div className="hm-preview"><strong>{preview.valid?'Preview valid — siap dikonfirmasi':'Preview memerlukan perbaikan'}</strong>{preview.rows.map(r=><div key={r.assignment_id}><span>{r.teacher?.nama_lengkap||rows.find(a=>String(a.record.id)===String(r.assignment_id))?.record.guru?.nama_lengkap} · {r.target_class?classLabel(r.target_class):'Tujuan tidak valid'}</span><span className={r.error?'hm-form-error':'hm-note'}>{r.error||'Memenuhi syarat'}</span></div>)}<p className="hm-note">{selected.length} penugasan dipilih. Preview berlaku 5 menit; data akan diperiksa kembali.</p></div>}
      {error&&<p className="hm-form-error" role="alert">{error}</p>}
    </form>
  </Modal>
}
