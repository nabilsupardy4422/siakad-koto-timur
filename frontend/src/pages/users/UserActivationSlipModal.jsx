import {useEffect,useRef,useState} from 'react'
import WorkspaceModal from '../../components/ui/WorkspaceModal.jsx'
import Button from '../../components/ui/Button.jsx'
import ErrorState from '../../components/ui/ErrorState.jsx'
import {userApi} from '../../services/userApi.js'
import {userActivationApi} from '../../services/userActivationApi.js'
import {identityInitials} from '../identity/identityDirectoryPresentation.js'
import {codeLabel,wibDate,validateCodeDetail} from './activationMonitorLogic.js'

// Individual issuance shares the bulk PDF workflow; never renders a plaintext code in the page.
export default function UserActivationSlipModal({user,onClose,onIssue,onChanged,canManageActivation}){
  const [detail,setDetail]=useState(null),[status,setStatus]=useState(null),[error,setError]=useState(''),[notice,setNotice]=useState('')
  const [loading,setLoading]=useState(true),[busy,setBusy]=useState(false),[confirm,setConfirm]=useState(false),[revision,setRevision]=useState(0)
  const operation=useRef(false)
  useEffect(()=>{
    let live=true;setLoading(true);setError('');setDetail(null);setStatus(null);setConfirm(false)
    Promise.all([userApi.getById(user.id),canManageActivation?userActivationApi.getCodeStatus(user.id):Promise.resolve(null)]).then(([r,s])=>{
      if(!live)return
      if(r?.data?.id!==user.id)throw new Error('Identitas akun tidak sesuai.')
      const verified=s?validateCodeDetail(s):null
      setDetail(r.data);setStatus(verified)
    }).catch(e=>{if(live)setError(e.message||'Detail gagal dimuat.')}).finally(()=>{if(live)setLoading(false)})
    return()=>{live=false}
  },[user.id,revision,canManageActivation])
  const canIssue=canManageActivation&&detail?.account_status==='active'&&detail?.requires_activation===true&&['GURU','SISWA'].includes(detail?.role?.code)&&detail?.academic_profile?.status==='linked'&&status!==null
  const profileLabel={linked:'Terhubung',unlinked:'Belum terhubung',conflict:'Konflik relasi',not_required:'Tidak diperlukan'}
  async function cancel(){
    if(operation.current||!confirm||!status?.can_cancel||!canManageActivation)return
    operation.current=true;setBusy(true);setError('');setNotice('')
    try{const r=await userActivationApi.cancelCode(user.id,status.code_version);if(typeof r?.data?.revoked!=='boolean')throw new Error('Respons pembatalan tidak valid.');setNotice(r.data.revoked?'Kode dibatalkan. Akun dan profil tetap tersimpan.':'Tidak ada kode yang dibatalkan.');onChanged?.()}
    catch(e){setNotice(`${e.message||'Pembatalan gagal.'} Periksa kondisi terbaru sebelum mengulangi tindakan.`)}
    finally{operation.current=false;setBusy(false);setConfirm(false);setRevision(v=>v+1)}
  }
  return <WorkspaceModal title="Detail & keamanan akun" eyebrow="ADMINISTRASI AKUN" description="Identitas, hak akses dan siklus aktivasi." busy={busy} onClose={()=>{if(!busy)onClose()}} footer={<><Button variant="secondary" disabled={busy} onClick={onClose}>Tutup</Button>{canIssue&&<Button disabled={busy||confirm||loading} onClick={onIssue}>Buat kode & slip PDF</Button>}</>}>
    {error&&<ErrorState message={error}/>}{notice&&<p role="status" className="account-security-notice">{notice}</p>}
    {loading&&<p role="status">Memuat detail akun…</p>}
    {!loading&&!detail&&<Button variant="secondary" onClick={()=>setRevision(v=>v+1)}>Periksa ulang</Button>}
    {detail&&<div className="account-security">
      <div className="account-security-hero"><span className="identity-avatar">{identityInitials(detail.name)}</span><div><h3>{detail.name||'Nama belum tersedia'}</h3><p>@{detail.username} · {detail.role?.name||'Role belum diketahui'}</p></div></div>
      <section><h4>Identitas & akses</h4><dl className="account-security-grid">{[['Username',detail.username],['Email akun',detail.email],['Role',detail.role?.name],['Profil akademik',profileLabel[detail.academic_profile?.status]],['Status akun',detail.account_status==='inactive'?'Nonaktif':detail.account_status==='active'?(detail.requires_activation===true?'Belum aktivasi':detail.requires_activation===false?'Siap digunakan':null):null],['Google',typeof detail.google_linked==='boolean'?(detail.google_linked?'Terhubung':'Belum terhubung'):null],['Aktivasi tercatat',wibDate(detail.activated_at)],['Login terakhir tercatat',wibDate(detail.last_login_at)]].map(([label,value])=><div key={label}><dt>{label}</dt><dd>{value||'Belum tersedia'}</dd></div>)}</dl></section>
      <section><h4>Pengelolaan kode aktivasi</h4><p className="account-security-state">{codeLabel(status?.state??detail.activation_status?.state)}</p><p>Kedaluwarsa: {wibDate(status?.expires_at??detail.activation_status?.expires_at)}</p><p className="account-security-muted">Kode asli tidak dapat dilihat ulang. Penerbitan baru menggunakan preview dan slip PDF yang sama dengan batch.</p>
      {status?.can_cancel&&<div className="account-security-cancel">{confirm?<><p role="alert">Batalkan kode untuk @{detail.username}? Slip sebelumnya tidak dapat digunakan lagi.</p><div><Button variant="secondary" disabled={busy} onClick={()=>setConfirm(false)}>Kembali</Button><button type="button" className="account-cancel-danger" disabled={busy} onClick={cancel}>Ya, batalkan kode</button></div></>:<Button variant="secondary" disabled={busy} onClick={()=>setConfirm(true)}>Batalkan kode aktivasi</Button>}</div>}
      <Button size="small" variant="secondary" disabled={busy||loading} onClick={()=>setRevision(v=>v+1)}>Periksa status terbaru</Button></section>
    </div>}
  </WorkspaceModal>
}
