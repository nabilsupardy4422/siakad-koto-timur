import { useEffect, useRef, useState } from 'react'
import WorkspaceModal from '../../components/ui/WorkspaceModal.jsx'
import Button from '../../components/ui/Button.jsx'
import ErrorState from '../../components/ui/ErrorState.jsx'
import { userApi } from '../../services/userApi.js'
import { validateBulkPreview, bulkPayload } from './bulkAccountLogic.js'

export default function BulkAccountModal({action, ids: initialIds, onClose, onCompleted}) {
  const [ids,setIds]=useState(initialIds)
  const [preview,setPreview]=useState(null)
  const [busy,setBusy]=useState(false)
  const [error,setError]=useState('')
  const [confirmed,setConfirmed]=useState(false)
  const [reissue,setReissue]=useState(false)
  const [result,setResult]=useState(null)
  const [acknowledged,setAcknowledged]=useState(false)
  const [revision,setRevision]=useState(0)
  const operation=useRef(false)
  const urlRef=useRef(null)
  const liveRef=useRef(true)
  useEffect(()=>{liveRef.current=true;return()=>{liveRef.current=false;if(urlRef.current)URL.revokeObjectURL(urlRef.current)}},[])
  useEffect(()=>{
    let live=true
    setPreview(null);setConfirmed(false);setReissue(false);setError('')
    if (!ids.length){setBusy(false);return}
    setBusy(true)
    userApi.previewBulk({action,user_ids:ids}).then(r=>{if(live)setPreview(validateBulkPreview(r,action,ids))})
      .catch(e=>{if(live)setError(e.message||'Preview gagal.')}).finally(()=>{if(live)setBusy(false)})
    return()=>{live=false}
  },[action,ids,revision])
  async function submit(){
    if(busy||operation.current||result)return
    operation.current=true;setBusy(true);setError('')
    try{
      const payload=bulkPayload(preview,ids,confirmed,reissue)
      if(action==='issue'){
        const blob=await userApi.issueBulk(payload)
        if(!(blob instanceof Blob)||blob.type.split(';')[0]!=='application/pdf'||blob.size<5||await blob.slice(0,5).text()!=='%PDF-')throw new Error('Respons PDF tidak valid.')
        if(!liveRef.current)return
        urlRef.current=URL.createObjectURL(blob)
        setResult({count:ids.length,url:urlRef.current})
      }else{
        const response=await userApi.deactivateBulk(payload)
        if(response?.data?.total!==ids.length)throw new Error('Jumlah hasil nonaktivasi tidak sesuai. Periksa daftar akun.')
        if(!liveRef.current)return
        setResult({count:response.data.total})
      }
      setPreview(null);setConfirmed(false);setReissue(false);onCompleted()
    }catch(e){
      if(liveRef.current){setPreview(null);setConfirmed(false);setReissue(false);setError(`${e.message||'Permintaan gagal.'} Periksa kondisi akun kembali. Jika respons terputus, kode mungkin sudah diterbitkan; jangan mengulang tanpa preview dan konfirmasi baru.`)}
    }finally{operation.current=false;if(liveRef.current)setBusy(false)}
  }
  function closeResult(){if(!busy&&(!result?.url||acknowledged))onClose()}
  const title=action==='issue'?'Terbitkan kode & slip aktivasi':'Nonaktifkan akun akademik'
  return <WorkspaceModal title={result?'Hasil proses akun':title} eyebrow="ADMINISTRASI & KEAMANAN" description="Satu batch maksimal 100 akun Guru/Siswa. Tidak menghapus riwayat akademik." busy={busy||!!(result?.url&&!acknowledged)} onClose={closeResult} footer={<><Button variant="secondary" disabled={busy||!!(result?.url&&!acknowledged)} onClick={closeResult}>{result?'Tutup hasil':'Batal'}</Button>{!result&&<Button disabled={busy||!preview||preview.eligible!==ids.length||!confirmed||(preview.reissue_count>0&&!reissue)} onClick={submit}>{busy?'Memproses…':action==='issue'?'Terbitkan & siapkan PDF':'Konfirmasi nonaktivasi'}</Button>}</>}>
    {error&&<ErrorState message={error}/>}
    {result?<div className="bulk-result" role="status"><h3>{result.count} akun berhasil diproses</h3>{result.url?<><p>Slip hanya tersedia selama dialog ini terbuka. Simpan secara aman dan distribusikan secara individual. QR pada slip hanya membuka halaman aktivasi resmi.</p><a className="bulk-download" href={result.url} download="slip-aktivasi-siakad.pdf">Unduh slip PDF ({result.count} akun)</a><p>Menutup dialog akan menghapus akses unduh dari sesi ini. Kode lama tidak dapat ditampilkan ulang.</p><label className="bulk-confirm"><input type="checkbox" checked={acknowledged} onChange={e=>setAcknowledged(e.target.checked)}/> Saya telah menyimpan slip, atau memahami slip tidak bisa diunduh ulang setelah ditutup.</label></>:<p>Token akses dan kode aktivasi yang belum digunakan telah dicabut.</p>}</div>:<>
      {busy&&<p role="status">Memeriksa atau memproses akun...</p>}
      {!preview&&!busy&&ids.length>0&&<Button variant="secondary" onClick={()=>setRevision(v=>v+1)}>Periksa ulang</Button>}
      {!ids.length&&<p>Tidak ada akun yang dipilih. Tutup dialog dan pilih kembali.</p>}
      {preview&&<><div className="bulk-preview-summary"><span><strong>{preview.total}</strong> dipilih</span><span><strong>{preview.eligible}</strong> memenuhi syarat</span><span><strong>{preview.total-preview.eligible}</strong> perlu dikeluarkan</span></div>
        <div className="bulk-preview-table"><table><thead><tr><th>Identitas</th><th>Role</th><th>Status / keterangan</th><th>Aksi</th></tr></thead><tbody>{preview.rows.map(row=><tr key={row.user_id}><td><strong>{row.name||`ID ${row.user_id}`}</strong><small>{row.username||'—'}</small></td><td>{row.role_code||'—'}</td><td>{row.eligible?(action==='issue'&&row.has_active_code?'Kode aktif akan diganti':'Memenuhi syarat'):row.reason}</td><td><Button size="small" variant="secondary" disabled={busy} onClick={()=>setIds(old=>old.filter(id=>id!==row.user_id))}>Keluarkan</Button></td></tr>)}</tbody></table></div>
        {preview.reissue_count>0&&<label className="bulk-confirm"><input type="checkbox" checked={reissue} onChange={e=>setReissue(e.target.checked)}/> Saya memahami {preview.reissue_count} kode lama yang masih berlaku akan dibatalkan.</label>}
        <label className="bulk-confirm"><input type="checkbox" checked={confirmed} onChange={e=>setConfirmed(e.target.checked)}/> {action==='issue'?'Saya telah memeriksa identitas dan akan menyimpan PDF secara aman.':'Saya memahami akun tidak dapat login hingga diaktifkan kembali.'}</label><p className="bulk-note">Preview berlaku 5 menit. Backend memeriksa ulang seluruh akun sebelum transaksi.</p>
      </>}
    </>}
  </WorkspaceModal>
}
