import { useEffect, useRef, useState } from 'react'
import './ToastNotification.css'
export default function ToastNotification({notice,onClose}) {
  const closeRef=useRef(onClose)
  useEffect(()=>{closeRef.current=onClose},[onClose])
  const [hovered,setHovered]=useState(false),[focused,setFocused]=useState(false)
  const [modalOpen,setModalOpen]=useState(false)
  const type=notice?.type||'info', title=notice?.title, message=notice?.message
  const visible=Boolean(notice)
  useEffect(()=>{
    if(!visible){setHovered(false);setFocused(false);setModalOpen(false);return}
    const sync=()=>setModalOpen(Boolean(document.querySelector('dialog:modal')))
    sync()
    const observer=new MutationObserver(sync)
    observer.observe(document.body,{subtree:true,childList:true,attributes:true,attributeFilter:['open']})
    return()=>observer.disconnect()
  },[visible])
  useEffect(()=>{
    if(!visible||type==='error'||type==='warning'||hovered||focused||modalOpen)return
    const timer=setTimeout(()=>{if(!document.querySelector('dialog:modal'))closeRef.current?.()},5000)
    return()=>clearTimeout(timer)
  },[visible,type,title,message,hovered,focused,modalOpen])
  if(!notice)return null
  return <div className={`siakad-toast-v2 ${type}`} role={type==='error'||type==='warning'?'alert':'status'} onMouseEnter={()=>setHovered(true)} onMouseLeave={()=>setHovered(false)} onFocus={()=>setFocused(true)} onBlur={e=>{if(!e.currentTarget.contains(e.relatedTarget))setFocused(false)}}><span className="stv-icon" aria-hidden="true">{type==='success'?'✓':type==='error'?'×':type==='warning'?'!':'i'}</span><div><strong>{notice.title}</strong>{notice.message&&<p>{notice.message}</p>}</div><button type="button" onClick={onClose} aria-label="Tutup notifikasi">×</button></div>
}
