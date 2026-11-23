import { useEffect } from 'react'
import './ToastNotification.css'
export default function ToastNotification({notice,onClose}) {
  useEffect(()=>{if(!notice)return;const timer=setTimeout(onClose,notice.type==='error'?8500:5000);return()=>clearTimeout(timer)},[notice,onClose])
  if(!notice)return null
  const type=notice.type||'info'
  return <div className={`siakad-toast-v2 ${type}`} role={type==='error'||type==='warning'?'alert':'status'}><span className="stv-icon" aria-hidden="true">{type==='success'?'✓':type==='error'?'×':type==='warning'?'!':'i'}</span><div><strong>{notice.title}</strong>{notice.message&&<p>{notice.message}</p>}</div><button type="button" onClick={onClose} aria-label="Tutup notifikasi">×</button></div>
}
